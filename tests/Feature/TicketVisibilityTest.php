<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Organization;
use App\Models\SupportTicket;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Règles de visibilité :
 * - membre      : ses tickets uniquement
 * - admin équipe: tickets de son équipe (+ les siens)
 * - admin       : tous les tickets de l'organisation
 * Jamais de fuite entre organisations.
 */
class TicketVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;
    private Team $sales;
    private Team $marketing;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org       = Organization::create(['name' => 'Via-Mobilis', 'slug' => 'via-mobilis', 'is_active' => true]);
        $this->sales     = Team::create(['organization_id' => $this->org->id, 'name' => 'Commercial', 'slug' => 'commercial']);
        $this->marketing = Team::create(['organization_id' => $this->org->id, 'name' => 'Marketing', 'slug' => 'marketing']);
    }

    private function user(?Team $team, UserRole $role = UserRole::Member, ?Organization $org = null): User
    {
        return User::factory()->create([
            'organization_id' => ($org ?? $this->org)->id,
            'team_id'         => $team?->id,
            'role'            => $role,
        ])->fresh();
    }

    private function ticketOf(User $author): SupportTicket
    {
        return SupportTicket::create([
            'organization_id' => $author->organization_id,
            'user_id'         => $author->id,
            'team_id'         => $author->team_id,
            'raw_description' => 'Description de test suffisamment longue',
            'ai_title'        => 'Ticket de ' . $author->name,
            'status'          => 'created',
        ]);
    }

    public function test_member_sees_only_own_tickets(): void
    {
        $alice = $this->user($this->sales);
        $bob   = $this->user($this->sales);
        $mine  = $this->ticketOf($alice);
        $other = $this->ticketOf($bob);

        $this->actingAs($alice)->get("/support/tickets/{$mine->id}")->assertOk();
        $this->actingAs($alice)->get("/support/tickets/{$other->id}")->assertForbidden();
        $this->actingAs($alice)->post("/support/tickets/{$other->id}/comment", ['content' => 'x'])->assertForbidden();

        $this->actingAs($alice)->get('/support/mes-tickets')
            ->assertOk()->assertSee($mine->ai_title)->assertDontSee($other->ai_title);
    }

    public function test_member_has_no_dashboard_nor_team_list(): void
    {
        $alice = $this->user($this->sales);

        $this->actingAs($alice)->get('/support/dashboard')->assertForbidden();
        $this->actingAs($alice)->get('/support/equipe')->assertForbidden();
        $this->actingAs($alice)->get('/support')->assertOk()->assertDontSee('Mon équipe');
    }

    public function test_team_admin_sees_own_team_only(): void
    {
        $chief     = $this->user($this->sales, UserRole::TeamAdmin);
        $seller    = $this->user($this->sales);
        $marketer  = $this->user($this->marketing);
        $salesT    = $this->ticketOf($seller);
        $marketT   = $this->ticketOf($marketer);

        $this->actingAs($chief)->get("/support/tickets/{$salesT->id}")->assertOk();
        $this->actingAs($chief)->get("/support/tickets/{$marketT->id}")->assertForbidden();

        $this->actingAs($chief)->get('/support/equipe')
            ->assertOk()->assertSee($salesT->ai_title)->assertDontSee($marketT->ai_title);

        $ids = SupportTicket::visibleTo($chief)->pluck('id')->all();
        $this->assertSame([$salesT->id], $ids);
    }

    public function test_team_admin_cannot_confirm_someone_elses_ticket(): void
    {
        $chief  = $this->user($this->sales, UserRole::TeamAdmin);
        $seller = $this->user($this->sales);
        $ticket = $this->ticketOf($seller);
        $ticket->update(['status' => 'pending']);

        $this->actingAs($chief)->postJson("/support/tickets/{$ticket->id}/confirm")->assertForbidden();
    }

    public function test_admin_sees_every_team_but_not_other_organizations(): void
    {
        $admin    = $this->user(null, UserRole::Admin);
        $seller   = $this->user($this->sales);
        $marketer = $this->user($this->marketing);

        $otherOrg = Organization::create(['name' => 'Autre', 'slug' => 'autre', 'is_active' => true]);
        $stranger = $this->user(null, UserRole::Member, $otherOrg);

        $a = $this->ticketOf($seller);
        $b = $this->ticketOf($marketer);
        $c = $this->ticketOf($stranger);

        $this->assertEqualsCanonicalizing([$a->id, $b->id], SupportTicket::visibleTo($admin)->pluck('id')->all());
        $this->actingAs($admin)->get("/support/tickets/{$c->id}")->assertForbidden();
        $this->actingAs($admin)->get('/support/equipe')->assertOk()->assertSee('Tous les tickets');
    }

    public function test_ticket_records_author_team_at_creation(): void
    {
        $seller = $this->user($this->sales);

        // Fallback mots-clés (pas de clé Claude) → needs_review, aucun appel GLPI
        config(['supportia.claude_api_key' => null]);

        $this->actingAs($seller)->postJson('/support/tickets', [
            'description'        => "Le client n'arrive plus à importer ses annonces depuis ce matin",
            'is_specific_client' => '0',
        ])->assertOk()->assertJsonPath('status', 'needs_review');

        $this->assertSame($this->sales->id, SupportTicket::firstOrFail()->team_id);
    }
}
