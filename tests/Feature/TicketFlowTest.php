<?php

namespace Tests\Feature;

use App\Console\Commands\SyncResolutionStatsCommand;
use App\Jobs\CreateGlpiTicket;
use App\Models\AiRequestLog;
use App\Models\GlpiCategoryMap;
use App\Models\Organization;
use App\Models\SupportTicket;
use App\Models\User;
use App\Services\GlpiClientService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Flux complet création → GLPI, avec Claude et GLPI simulés (aucun appel réel).
 */
class TicketFlowTest extends TestCase
{
    use RefreshDatabase;

    private const GLPI = 'https://glpi.test/apirest.php';

    private Organization $org;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        config(['supportia.claude_api_key' => 'sk-test', 'supportia.confidence_threshold' => 0.7]);

        $this->org = Organization::create([
            'name' => 'Via-Mobilis', 'slug' => 'via-mobilis', 'is_active' => true,
            'glpi_api_url' => self::GLPI, 'glpi_app_token' => 'app', 'glpi_user_token' => 'user',
        ]);

        GlpiCategoryMap::create([
            'organization_id' => $this->org->id, 'glpi_category_id' => 18, 'slug' => 'tech_flux_bug_import',
            'label' => '[TECHNIQUE] Bug import', 'label_simple' => "Problème d'import",
            'is_active' => true, 'is_visible_to_users' => true,
        ]);

        $this->user = User::factory()->create(['organization_id' => $this->org->id, 'name' => 'Léa Martin']);
    }

    /** Réponse Claude simulée avec la confiance voulue. */
    private function claude(float $confidence): array
    {
        $json = json_encode([
            'category_slug' => 'tech_flux_bug_import', 'priority' => 3,
            'title' => "Import bloqué", 'body' => "Le flux ne remonte plus.\n<script>alert(1)</script>",
            'confidence' => $confidence,
        ]);

        return ['content' => [['type' => 'text', 'text' => $json]], 'usage' => ['input_tokens' => 900, 'output_tokens' => 120]];
    }

    private function fakeGlpi(int $ticketStatus = 201, float $confidence = 0.92): void
    {
        Http::fake([
            'api.anthropic.com/*'        => Http::response($this->claude($confidence)),
            'glpi.test/*/initSession*'   => Http::response(['session_token' => 'sess']),
            'glpi.test/*/Ticket'         => Http::response(['id' => 4242, 'message' => ''], $ticketStatus),
            'glpi.test/*/Document'       => Http::response(['id' => 77, 'message' => ''], 201),
        ]);
    }

    private function submit(array $extra = [])
    {
        return $this->actingAs($this->user)->post('/support/tickets', [
            'description'        => "Le client n'arrive plus à importer ses annonces depuis ce matin",
            'is_specific_client' => '0',
        ] + $extra, ['Accept' => 'application/json']);
    }

    public function test_high_confidence_ticket_is_created_with_attachment_and_logged(): void
    {
        $this->fakeGlpi();

        $this->submit(['attachments' => [UploadedFile::fake()->createWithContent('erreur.log', "ERREUR import ligne 12")]])
            ->assertOk()
            ->assertJsonPath('status', 'created')
            ->assertJsonPath('glpi_ticket_id', 4242);

        $ticket = SupportTicket::firstOrFail();
        $this->assertSame('created', $ticket->status);
        $this->assertSame(77, (int) $ticket->attachments()->first()->glpi_document_id);

        // Corps envoyé à GLPI : le HTML saisi est échappé
        Http::assertSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/Ticket')
            && ! str_contains($r['input']['content'], '<script>')
            && str_contains($r['input']['content'], '&lt;script&gt;'));

        // Appel IA tracé
        $log = AiRequestLog::firstOrFail();
        $this->assertSame('claude', $log->provider);
        $this->assertSame(900, $log->prompt_tokens);
    }

    public function test_glpi_down_puts_ticket_in_queue_with_retry_job(): void
    {
        Queue::fake();
        $this->fakeGlpi(ticketStatus: 500);

        $this->submit()->assertStatus(202)->assertJsonPath('status', 'queued');

        $ticket = SupportTicket::firstOrFail();
        $this->assertSame('queued', $ticket->status);
        $this->assertNull($ticket->glpi_ticket_id);
        Queue::assertPushed(CreateGlpiTicket::class, fn ($job) => $job->ticketId === $ticket->id);
    }

    public function test_retry_job_creates_ticket_then_marks_failure_when_exhausted(): void
    {
        $this->fakeGlpi();
        $ticket = SupportTicket::create([
            'organization_id' => $this->org->id, 'user_id' => $this->user->id, 'raw_description' => 'x',
            'ai_title' => 'Titre', 'ai_body' => 'Corps', 'ai_category_slug' => 'tech_flux_bug_import',
            'ai_priority' => 3, 'ai_confidence' => 0.9, 'ai_provider' => 'claude', 'status' => 'queued',
        ]);

        (new CreateGlpiTicket($ticket->id))->handle(app(\App\Services\GlpiTicketPublisher::class));
        $this->assertSame('created', $ticket->fresh()->status);
        $this->assertSame(4242, (int) $ticket->fresh()->glpi_ticket_id);

        $other = SupportTicket::create(['organization_id' => $this->org->id, 'user_id' => $this->user->id, 'raw_description' => 'y', 'status' => 'queued']);
        (new CreateGlpiTicket($other->id))->failed(new \RuntimeException('GLPI injoignable'));
        $this->assertSame('failed', $other->fresh()->status);
        $this->assertSame('GLPI injoignable', $other->fresh()->glpi_last_error);
    }

    public function test_job_never_sends_a_ticket_awaiting_review(): void
    {
        Http::fake();
        $ticket = SupportTicket::create(['organization_id' => $this->org->id, 'user_id' => $this->user->id, 'raw_description' => 'x', 'ai_title' => 'T', 'status' => 'needs_review']);

        (new CreateGlpiTicket($ticket->id))->handle(app(\App\Services\GlpiTicketPublisher::class));

        Http::assertNothingSent();
        $this->assertSame('needs_review', $ticket->fresh()->status);
    }

    public function test_low_confidence_requires_validation_then_confirm_creates_once(): void
    {
        $this->fakeGlpi(confidence: 0.4);

        $this->submit()->assertOk()->assertJsonPath('status', 'needs_review');
        $ticket = SupportTicket::firstOrFail();
        $this->assertSame('needs_review', $ticket->status);
        Http::assertNotSent(fn (HttpRequest $r) => str_contains($r->url(), 'glpi.test'));

        $this->actingAs($this->user)->postJson("/support/tickets/{$ticket->id}/confirm", ['priority' => 4])
            ->assertOk()->assertJsonPath('status', 'created');
        $this->assertSame('created', $ticket->fresh()->status);
        $this->assertTrue($ticket->fresh()->was_modified_by_user);

        $this->actingAs($this->user)->postJson("/support/tickets/{$ticket->id}/confirm")->assertStatus(409);
    }

    public function test_private_followups_are_never_shown(): void
    {
        $suffix = '["ERROR_METHOD_NOT_ALLOWED","Méthode non autorisée"]';
        Http::fake([
            'glpi.test/*/initSession*'            => Http::response(['session_token' => 'sess']),
            'glpi.test/*/Ticket/10/User*'         => Http::response([]),
            'glpi.test/*/Ticket/10/ITILFollowup*' => Http::response(json_encode([
                ['id' => 1, 'date' => '2026-10-01 10:00:00', 'users_id' => 'nicolas', 'content' => '&lt;p&gt;Corrigé côté import&lt;/p&gt;', 'is_private' => 0],
                ['id' => 2, 'date' => '2026-10-01 09:00:00', 'users_id' => 'nicolas', 'content' => '<p>Note interne : client pénible</p>', 'is_private' => 1],
            ]) . $suffix),
            'glpi.test/*/Ticket/10*'              => Http::response(json_encode(['id' => 10, 'status' => 5, 'solvedate' => '2026-10-01 10:00:00']) . $suffix),
        ]);

        $status = app(GlpiClientService::class)->getTicketStatus($this->org, 10);

        $this->assertSame(5, $status['status']);
        $this->assertCount(1, $status['followups']);
        $this->assertSame('Corrigé côté import', $status['followups'][0]['content']);
        $this->assertSame('nicolas', $status['followups'][0]['author']);
    }

    public function test_resolution_stats_give_median_per_category(): void
    {
        $recent = now()->subMonth()->format('Y-m-d H:i:s');
        Http::fake([
            'glpi.test/*/initSession*' => Http::response(['session_token' => 'sess']),
            'glpi.test/*/Ticket*'      => Http::response([
                ['id' => 1, 'status' => 6, 'itilcategories_id' => 18, 'solve_delay_stat' => 3600,  'date' => $recent],
                ['id' => 2, 'status' => 5, 'itilcategories_id' => 18, 'solve_delay_stat' => 7200,  'date' => $recent],
                ['id' => 3, 'status' => 6, 'itilcategories_id' => 18, 'solve_delay_stat' => 10800, 'date' => $recent],
                ['id' => 4, 'status' => 6, 'itilcategories_id' => 18, 'solve_delay_stat' => 14400, 'date' => $recent],
                ['id' => 5, 'status' => 6, 'itilcategories_id' => 18, 'solve_delay_stat' => 999999, 'date' => $recent],
                ['id' => 6, 'status' => 2, 'itilcategories_id' => 18, 'solve_delay_stat' => 5,     'date' => $recent], // non résolu
                ['id' => 7, 'status' => 6, 'itilcategories_id' => 18, 'solve_delay_stat' => 5,     'date' => '2020-01-01 00:00:00'], // trop ancien
            ], 206, ['Content-Range' => '0-6/7']),
        ]);

        $this->artisan('glpi:sync-resolution-stats')->assertSuccessful();

        $category = GlpiCategoryMap::firstOrFail();
        $this->assertSame(10800, $category->median_resolution_seconds);
        $this->assertSame(5, $category->resolution_sample_count);

        $ticket = SupportTicket::create(['organization_id' => $this->org->id, 'user_id' => $this->user->id, 'raw_description' => 'x', 'ai_category_slug' => 'tech_flux_bug_import', 'status' => 'created']);
        $this->assertSame(['hours' => 3.0, 'count' => 5], $ticket->resolutionEstimate());

        $this->assertSame(5, SyncResolutionStatsCommand::median([1, 5, 9]));
        $this->assertSame(4, SyncResolutionStatsCommand::median([1, 3, 5, 9]));
    }
}
