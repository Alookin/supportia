<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Organization;
use App\Models\SupportTicket;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ResolveTicketTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;
    private Team $team;
    private User $lea;

    protected function setUp(): void
    {
        parent::setUp();
        config(['supportia.glpi_dry_run' => false]);
        $this->org  = Organization::create([
            'name' => 'Via-Mobilis', 'slug' => 'via-mobilis', 'is_active' => true,
            'glpi_api_url' => 'https://glpi.test/apirest.php', 'glpi_app_token' => 'app', 'glpi_user_token' => 'user',
        ]);
        $this->team = Team::create(['organization_id' => $this->org->id, 'name' => 'Commercial', 'slug' => 'commercial']);
        $this->lea  = User::factory()->create(['organization_id' => $this->org->id, 'team_id' => $this->team->id, 'name' => 'Léa Martin']);
    }

    private function ticket(array $attrs = []): SupportTicket
    {
        return SupportTicket::create($attrs + [
            'organization_id' => $this->org->id, 'user_id' => $this->lea->id, 'team_id' => $this->team->id,
            'raw_description' => 'x', 'ai_title' => 'Import bloqué', 'ai_category_slug' => 'import',
            'status' => 'created', 'glpi_ticket_id' => 4242,
        ]);
    }

    private function fakeGlpi(int $solutionStatus = 201): void
    {
        Http::fake([
            'glpi.test/apirest.php/initSession*' => Http::response(['session_token' => 'tok']),
            'glpi.test/apirest.php/ITILSolution' => Http::response(['id' => 1], $solutionStatus),
            '*' => Http::response([], 200),
        ]);
    }

    public function test_author_can_mark_ticket_resolved_and_glpi_gets_a_solution(): void
    {
        $this->fakeGlpi();
        $ticket = $this->ticket();

        $this->actingAs($this->lea)->post("/support/tickets/{$ticket->id}/resolve")
            ->assertRedirect(route('support.ticket-detail', $ticket->id) . '#conversation');

        $this->assertSame('resolved', $ticket->fresh()->status);
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/ITILSolution')
            && $r['input']['items_id'] === 4242 && $r['input']['itemtype'] === 'Ticket');
    }

    public function test_glpi_refusal_keeps_ticket_open(): void
    {
        $this->fakeGlpi(403);
        $ticket = $this->ticket();

        $this->actingAs($this->lea)->post("/support/tickets/{$ticket->id}/resolve")->assertSessionHasErrors('resolve');
        $this->assertSame('created', $ticket->fresh()->status);
    }

    public function test_other_member_cannot_resolve(): void
    {
        $this->fakeGlpi();
        $hugo   = User::factory()->create(['organization_id' => $this->org->id, 'team_id' => $this->team->id]);
        $ticket = $this->ticket();

        $this->actingAs($hugo)->post("/support/tickets/{$ticket->id}/resolve")->assertForbidden();
        $this->assertSame('created', $ticket->fresh()->status);
    }

    public function test_glpi_link_hidden_for_sales_rep_but_shown_to_team_admin(): void
    {
        $this->fakeGlpi();
        $ticket = $this->ticket();
        $vanessa = User::factory()->create(['organization_id' => $this->org->id, 'team_id' => $this->team->id, 'role' => UserRole::TeamAdmin->value]);

        $this->actingAs($this->lea)->get("/support/tickets/{$ticket->id}")->assertOk()->assertDontSee('Voir dans GLPI')->assertSee('Le problème est résolu');
        $this->actingAs($vanessa)->get("/support/tickets/{$ticket->id}")->assertOk()->assertSee('Voir dans GLPI');
    }

    public function test_get_logout_redirects_instead_of_crashing(): void
    {
        $this->actingAs($this->lea)->get('/logout')->assertRedirect(route('dashboard'));
    }
}
