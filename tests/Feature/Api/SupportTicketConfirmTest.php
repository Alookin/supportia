<?php

namespace Tests\Feature\Api;

use App\Models\Organization;
use App\Models\SupportTicket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Smoke test ciblant la régression du commit f9a8296 :
 * Api\SupportTicketController::confirm() appelle $this->authorize(...),
 * mais le trait AuthorizesRequests n'était pas chargé sur la classe
 * Controller de base (skeleton Laravel 11). Tout appel à la route
 * /support/tickets/{ticket}/confirm renvoyait alors un 500
 * "undefined method authorize".
 *
 * Avec le trait restauré, un utilisateur d'une autre organisation doit
 * recevoir un 403 propre (Policy refuse), pas un 500.
 */
class SupportTicketConfirmTest extends TestCase
{
    use RefreshDatabase;

    public function test_confirm_route_authorize_is_wired_and_denies_other_org_user(): void
    {
        $orgA = Organization::create([
            'name'      => 'Org A',
            'slug'      => 'org-a',
            'is_active' => true,
        ]);

        $orgB = Organization::create([
            'name'      => 'Org B',
            'slug'      => 'org-b',
            'is_active' => true,
        ]);

        $owner = User::factory()->create();
        $owner->organization_id = $orgA->id;
        $owner->save();

        $intruder = User::factory()->create();
        $intruder->organization_id = $orgB->id;
        $intruder->save();

        $ticket = SupportTicket::create([
            'organization_id' => $orgA->id,
            'user_id'         => $owner->id,
            'raw_description' => 'Smoke test ticket — checking authorize() trait',
            'status'          => 'pending',
        ]);

        $response = $this->actingAs($intruder)
            ->postJson("/support/tickets/{$ticket->id}/confirm");

        // Régression cible : sans le trait AuthorizesRequests sur le base
        // Controller, $this->authorize(...) lève "undefined method" → 500.
        $this->assertNotSame(
            500,
            $response->status(),
            'authorize() must be available on Controller — the AuthorizesRequests trait should be wired on the base Controller class.'
        );

        // Avec le trait + la SupportTicketPolicy::confirm, un user d'une
        // autre orga doit être refusé.
        $response->assertForbidden();
    }
}
