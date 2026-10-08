<?php

namespace Tests\Feature;

use App\Models\AiRequestLog;
use App\Models\Organization;
use App\Models\SupportTicket;
use App\Models\User;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Rétention des logs IA : le contenu (raw_response, error) est purgé, les compteurs restent.
 */
class AiLogRetentionTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $org = Organization::create(['name' => 'Via-Mobilis', 'slug' => 'via-mobilis', 'is_active' => true]);
        $this->user = User::factory()->create(['organization_id' => $org->id]);
    }

    private function ticket(): SupportTicket
    {
        return SupportTicket::create([
            'organization_id' => $this->user->organization_id, 'user_id' => $this->user->id,
            'raw_description' => 'Texte du client', 'status' => 'needs_review',
        ]);
    }

    private function log(SupportTicket $ticket, int $daysAgo = 0): AiRequestLog
    {
        $log = AiRequestLog::create([
            'support_ticket_id' => $ticket->id, 'user_id' => $this->user->id,
            'provider' => 'fallback_keywords', 'model' => 'keywords', 'fallback_reason' => 'invalid_response',
            'attempted_provider' => 'openai', 'attempted_model' => 'gpt-5.4-mini',
            'prompt_tokens' => 3000, 'completion_tokens' => 200, 'total_tokens' => 3200,
            'usage_raw' => ['prompt_tokens' => 3000, 'completion_tokens' => 200], 'estimated_cost' => 0.0016,
            'latency_ms' => 1900, 'raw_response' => ['title' => 'Texte du client', 'body' => 'Texte du client'],
            'error' => 'Impossible de parser la réponse IA : Texte du client',
        ]);
        $log->forceFill(['created_at' => now()->subDays($daysAgo)])->save();

        return $log;
    }

    private function assertContentPurgedCountersKept(AiRequestLog $log): void
    {
        $log->refresh();
        $this->assertNull($log->raw_response);
        $this->assertNull($log->error);
        $this->assertSame(3200, $log->total_tokens);
        $this->assertSame(1900, $log->latency_ms);
        $this->assertSame('invalid_response', $log->fallback_reason);
        $this->assertSame('gpt-5.4-mini', $log->attempted_model);
        $this->assertSame($this->user->id, $log->user_id);
        $this->assertEqualsWithDelta(0.0016, $log->estimated_cost, 1e-9);
        $this->assertSame(3000, $log->usage_raw['prompt_tokens']); // compteurs seulement : conservé
    }

    public function test_deleting_a_ticket_purges_its_log_content_immediately(): void
    {
        $deleted = $this->ticket();
        $kept    = $this->ticket();
        $deletedLog = $this->log($deleted);
        $keptLog    = $this->log($kept);

        $deleted->delete();

        $this->assertContentPurgedCountersKept($deletedLog);
        $this->assertNull($deletedLog->fresh()->support_ticket_id);
        // Les logs des autres tickets ne sont pas touchés
        $this->assertNotNull($keptLog->fresh()->raw_response);
        $this->assertNotNull($keptLog->fresh()->error);
    }

    public function test_command_purges_content_older_than_the_configured_delay(): void
    {
        $ticket = $this->ticket();
        $old    = $this->log($ticket, daysAgo: 91);
        $recent = $this->log($ticket, daysAgo: 89);

        $this->artisan('zeno:prune-ai-log-content')
            ->expectsOutputToContain('1 log(s) IA purgé(s) de leur contenu (plus de 90 jours)')
            ->assertSuccessful();

        $this->assertContentPurgedCountersKept($old);
        $this->assertNotNull($recent->fresh()->raw_response);
        // Le ticket existe toujours : seul le contenu du log est purgé
        $this->assertSame($ticket->id, $old->fresh()->support_ticket_id);
    }

    public function test_delay_comes_from_config_and_can_be_overridden(): void
    {
        $ticket = $this->ticket();
        $log    = $this->log($ticket, daysAgo: 40);

        config(['supportia.ai_log_content_retention_days' => 60]);
        $this->artisan('zeno:prune-ai-log-content')->expectsOutputToContain('0 log(s)')->assertSuccessful();
        $this->assertNotNull($log->fresh()->raw_response);

        config(['supportia.ai_log_content_retention_days' => 30]);
        $this->artisan('zeno:prune-ai-log-content')->expectsOutputToContain('1 log(s)')->assertSuccessful();
        $this->assertNull($log->fresh()->raw_response);

        $this->artisan('zeno:prune-ai-log-content --days=0')->assertFailed();
    }

    public function test_purge_is_scheduled_nightly_after_draft_pruning(): void
    {
        $events = collect(app(Schedule::class)->events())
            ->filter(fn ($e) => str_contains($e->command ?? '', 'zeno:prune-ai-log-content'));

        $this->assertCount(1, $events);
        $this->assertSame('51 2 * * *', $events->first()->expression);
    }
}
