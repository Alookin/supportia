<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\GlpiCategoryMap;
use App\Models\Organization;
use App\Models\SupportTicket;
use App\Models\Team;
use App\Models\User;
use App\Notifications\TicketResolvedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class WeekendFeaturesTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;
    private Team $sales;
    private User $lea;

    protected function setUp(): void
    {
        parent::setUp();
        config(['supportia.glpi_dry_run' => false]);

        $this->org = Organization::create([
            'name' => 'Via-Mobilis', 'slug' => 'via-mobilis', 'is_active' => true,
            'glpi_api_url' => 'https://glpi.test/apirest.php', 'glpi_app_token' => 'app', 'glpi_user_token' => 'user',
        ]);
        $this->sales = Team::create(['organization_id' => $this->org->id, 'name' => 'Commercial', 'slug' => 'commercial']);
        $this->lea   = User::factory()->create(['organization_id' => $this->org->id, 'team_id' => $this->sales->id, 'name' => 'Léa Martin']);

        GlpiCategoryMap::create(['organization_id' => $this->org->id, 'glpi_category_id' => 18, 'slug' => 'import', 'label' => 'Import', 'is_active' => true, 'is_visible_to_users' => true]);
        GlpiCategoryMap::create(['organization_id' => $this->org->id, 'glpi_category_id' => 13, 'slug' => 'acces', 'label' => 'Accès', 'is_active' => true, 'is_visible_to_users' => true]);
    }

    private function ticket(array $attrs = []): SupportTicket
    {
        return SupportTicket::create($attrs + [
            'organization_id' => $this->org->id, 'user_id' => $this->lea->id, 'team_id' => $this->sales->id,
            'raw_description' => 'x', 'ai_title' => 'Import bloqué', 'ai_category_slug' => 'import', 'status' => 'created',
        ]);
    }

    public function test_sync_updates_status_category_and_notifies_once(): void
    {
        Notification::fake();
        $resolved = $this->ticket(['glpi_ticket_id' => 101]);
        $open     = $this->ticket(['glpi_ticket_id' => 102, 'ai_category_slug' => 'acces']);

        Http::fake([
            'glpi.test/*/initSession*' => Http::response(['session_token' => 's']),
            'glpi.test/*/Ticket/101*'  => Http::response(json_encode(['id' => 101, 'status' => 5, 'itilcategories_id' => 18]) . '["ERROR_METHOD_NOT_ALLOWED",""]'),
            'glpi.test/*/Ticket/102*'  => Http::response(['id' => 102, 'status' => 2, 'itilcategories_id' => 18]),
        ]);

        $this->artisan('glpi:sync-ticket-statuses')->assertSuccessful();

        $this->assertSame('resolved', $resolved->fresh()->status);
        $this->assertSame(18, $resolved->fresh()->glpi_category_id_final);
        $this->assertNotNull($resolved->fresh()->resolved_notified_at);
        $this->assertSame('created', $open->fresh()->status);
        Notification::assertSentToTimes($this->lea, TicketResolvedNotification::class, 1);

        // Deuxième passage : ticket résolu plus synchronisé, pas de seconde notification
        $this->artisan('glpi:sync-ticket-statuses')->assertSuccessful();
        Notification::assertSentToTimes($this->lea, TicketResolvedNotification::class, 1);

        // Précision IA : 1 catégorie conservée (import→18) sur 2 vérifiées (acces→13 corrigée en 18)
        $admin = User::factory()->create(['organization_id' => $this->org->id, 'role' => UserRole::Admin])->fresh();
        $this->actingAs($admin)->get('/support/dashboard')->assertOk()->assertSee('50 %')->assertSee('2 tickets vérifiés');
    }

    public function test_open_ticket_for_same_client_is_flagged(): void
    {
        $this->ticket(['client_ids' => [['id' => '4521', 'name' => 'Garage Dupont']], 'status' => 'created']);
        $this->ticket(['client_ids' => [['id' => '9999', 'name' => 'Autre']], 'status' => 'created']);
        $this->ticket(['client_ids' => [['id' => '4521', 'name' => 'Garage Dupont']], 'status' => 'resolved']);

        $this->actingAs($this->lea)->getJson('/support/tickets/open-for-client?client_id=4521')
            ->assertOk()->assertJsonCount(1, 'tickets')->assertJsonPath('tickets.0.visible', true)
            ->assertJsonPath('tickets.0.title', 'Import bloqué');

        // Un collègue est prévenu sans voir le contenu
        $hugo = User::factory()->create(['organization_id' => $this->org->id, 'team_id' => $this->sales->id]);
        $this->actingAs($hugo)->getJson('/support/tickets/open-for-client?client_id=4521')
            ->assertOk()->assertJsonPath('tickets.0.visible', false)->assertJsonMissingPath('tickets.0.title');
    }

    public function test_long_estimates_are_hidden(): void
    {
        GlpiCategoryMap::where('slug', 'import')->update(['median_resolution_seconds' => 10 * 3600, 'resolution_sample_count' => 50]);
        GlpiCategoryMap::where('slug', 'acces')->update(['median_resolution_seconds' => 15 * 24 * 3600, 'resolution_sample_count' => 50]);

        $this->assertSame(['hours' => 10.0, 'count' => 50], $this->ticket()->resolutionEstimate());
        $this->assertNull($this->ticket(['ai_category_slug' => 'acces'])->resolutionEstimate());
    }
}
