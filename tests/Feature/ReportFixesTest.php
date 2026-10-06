<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\SupportTicket;
use App\Models\User;
use App\Services\AIClassifierService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Corrections du rapport de test Chrome du 06/10/2026. */
class ReportFixesTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;
    private User $lea;

    protected function setUp(): void
    {
        parent::setUp();
        $this->org = Organization::create(['name' => 'Via-Mobilis', 'slug' => 'via-mobilis', 'is_active' => true]);
        $this->lea = User::factory()->create(['organization_id' => $this->org->id]);
    }

    private function ticket(array $attrs): SupportTicket
    {
        return SupportTicket::create($attrs + [
            'organization_id' => $this->org->id, 'user_id' => $this->lea->id, 'raw_description' => 'x', 'ai_title' => 'Titre',
        ]);
    }

    public function test_cancel_deletes_the_draft_and_drafts_stay_out_of_lists(): void
    {
        $draft = $this->ticket(['status' => 'needs_review', 'ai_title' => 'Brouillon abandonné', 'client_ids' => [['id' => '7777', 'name' => '']]]);
        $sent  = $this->ticket(['status' => 'created', 'ai_title' => 'Ticket envoyé']);

        $this->actingAs($this->lea)->get('/support/mes-tickets')
            ->assertOk()->assertSee('Ticket envoyé')->assertDontSee('Brouillon abandonné');
        $this->actingAs($this->lea)->getJson('/support/tickets/open-for-client?client_id=7777')->assertJsonCount(0, 'tickets');

        $this->actingAs($this->lea)->deleteJson("/support/tickets/{$draft->id}/draft")->assertOk();
        $this->assertNull(SupportTicket::find($draft->id));

        // Un ticket déjà envoyé ne peut pas être « annulé »
        $this->actingAs($this->lea)->deleteJson("/support/tickets/{$sent->id}/draft")->assertStatus(409);
    }

    public function test_abandoned_drafts_are_pruned_after_24_hours(): void
    {
        $old   = $this->ticket(['status' => 'needs_review']);
        $old->forceFill(['created_at' => now()->subDays(2)])->save();
        $fresh = $this->ticket(['status' => 'needs_review']);

        $this->artisan('zeno:prune-drafts')->assertSuccessful();

        $this->assertNull(SupportTicket::find($old->id));
        $this->assertNotNull(SupportTicket::find($fresh->id));
    }

    public function test_status_label_is_the_same_everywhere(): void
    {
        $this->assertSame('Transmis au support', $this->ticket(['status' => 'created'])->statusBadge()['label']);
        $this->assertSame('En cours', $this->ticket(['status' => 'created', 'glpi_status' => 2])->statusBadge()['label']);
        $this->assertSame('Résolu', $this->ticket(['status' => 'created', 'glpi_status' => 5])->statusBadge()['label']);
        $this->assertSame('Clôturé', $this->ticket(['status' => 'closed'])->statusBadge()['label']);
        $this->assertSame("En attente d'envoi", $this->ticket(['status' => 'queued'])->statusBadge()['label']);

        $t = $this->ticket(['status' => 'closed', 'ai_title' => 'Ticket clos']);
        $admin = User::factory()->create(['organization_id' => $this->org->id, 'role' => 'admin'])->fresh();
        $this->actingAs($admin)->get('/support/dashboard')->assertOk()->assertSee('Clôturé')->assertDontSee('>closed<', false);
        $this->actingAs($this->lea)->get("/support/tickets/{$t->id}")->assertOk()->assertSee('Clôturé');
    }

    public function test_fallback_title_is_cut_on_a_word_with_ellipsis(): void
    {
        $title = AIClassifierService::shortTitle("Les photos des annonces du client ne s'affichent plus depuis l'import de ce matin sur toutes les annonces");

        $this->assertLessThanOrEqual(80, mb_strlen($title));
        $this->assertStringEndsWith('…', $title);
        $this->assertStringNotContainsString(' mati…', $title);
        $this->assertSame('Court', AIClassifierService::shortTitle('Court'));
    }
}
