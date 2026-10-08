<?php

namespace Tests\Feature;

use App\Models\GlpiCategoryMap;
use App\Models\Organization;
use App\Models\SupportTicket;
use App\Models\User;
use App\Services\AIClassifierService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Retours de la recette UI du 08/10/2026 : libellés du moteur, description originale,
 * numéro de ticket dans l'en-tête, titre du fallback.
 */
class UiRecetteTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        config(['supportia.glpi_dry_run' => true]);

        $this->org = Organization::create([
            'name' => 'Via-Mobilis', 'slug' => 'via-mobilis', 'is_active' => true,
            'glpi_api_url' => 'https://glpi.test/apirest.php', 'glpi_app_token' => 'app', 'glpi_user_token' => 'user',
        ]);

        GlpiCategoryMap::create([
            'organization_id' => $this->org->id, 'glpi_category_id' => 18, 'slug' => 'tech_flux_bug_import',
            'label' => '[TECHNIQUE] Bug import', 'label_simple' => "Problème d'import",
            'keywords' => ['import'], 'is_active' => true, 'is_visible_to_users' => true,
        ]);

        $this->user = User::factory()->create(['organization_id' => $this->org->id]);
    }

    private function ticket(array $attrs): SupportTicket
    {
        return SupportTicket::create($attrs + [
            'organization_id' => $this->org->id, 'user_id' => $this->user->id,
            'raw_description' => "Le client n'arrive plus à importer ses annonces depuis ce matin",
            'ai_title' => 'Import bloqué', 'ai_category_slug' => 'tech_flux_bug_import', 'ai_priority' => 3,
            'status' => 'created', 'glpi_ticket_id' => 900001,
        ]);
    }

    private function detail(SupportTicket $ticket)
    {
        Http::fake();

        return $this->actingAs($this->user)->get("/support/tickets/{$ticket->id}")->assertOk();
    }

    // ─── 1. Description originale ───────────────────────

    public function test_original_description_is_hidden_when_identical_to_the_displayed_one(): void
    {
        $ticket = $this->ticket(['ai_provider' => 'fallback_keywords', 'ai_body' => "Le client n'arrive plus à importer ses annonces depuis ce matin\n"]);

        $this->detail($ticket)
            ->assertDontSee('Description originale')
            ->assertDontSee('avant analyse IA');
    }

    public function test_original_description_label_follows_the_engine(): void
    {
        $ai = $this->ticket(['ai_provider' => 'openai', 'ai_body' => "**Symptôme** : import bloqué depuis ce matin."]);
        $this->detail($ai)
            ->assertSee('Description originale')
            ->assertSee('Texte saisi par le commercial, avant analyse IA');

        // Analyse simplifiée, puis description retouchée par le commercial en validation
        $edited = $this->ticket(['ai_provider' => 'fallback_keywords', 'ai_body' => 'Import bloqué pour Garage Martin, 40 annonces.']);
        $this->detail($edited)
            ->assertSee('Texte saisi par le commercial, avant ses modifications')
            ->assertDontSee('avant analyse IA');
    }

    // ─── 2. Un seul libellé par moteur ──────────────────

    public function test_engine_label_is_the_same_everywhere_and_never_mentions_keywords(): void
    {
        $this->assertSame('Analyse IA', SupportTicket::analysisLabel('openai'));
        $this->assertSame('Analyse IA', SupportTicket::analysisLabel('claude'));
        $this->assertSame('Analyse IA', SupportTicket::analysisLabel('local'));
        $this->assertSame('Analyse simplifiée', SupportTicket::analysisLabel('fallback_keywords'));

        $ticket = $this->ticket(['ai_provider' => 'fallback_keywords', 'ai_body' => 'Corps']);
        $this->detail($ticket)
            ->assertSee('Analyse simplifiée')
            ->assertDontSee('Classement simplifié')
            ->assertDontSee('(mots-clés)');

        // Écran de proposition : mêmes libellés, mêmes moteurs, issus du modèle
        $this->actingAs($this->user)->get('/support')->assertOk()
            ->assertSee('Analyse simplifi', false)
            ->assertDontSee('Classement simplifié')
            ->assertDontSee("['openai', 'claude', 'local']", false);
    }

    // ─── 3. Numéro dans l'en-tête ───────────────────────

    public function test_ticket_number_is_shown_in_the_header_and_breadcrumb(): void
    {
        $ticket = $this->ticket(['ai_provider' => 'openai', 'ai_body' => 'Corps']);

        $this->detail($ticket)
            ->assertSeeInOrder(['#900001', 'Import bloqué'])
            ->assertSee('Ticket #900001');
    }

    public function test_no_number_before_the_ticket_reaches_glpi(): void
    {
        $draft = $this->ticket(['ai_provider' => 'openai', 'ai_body' => 'Corps', 'status' => 'needs_review', 'glpi_ticket_id' => null]);

        $this->assertNull($draft->displayNumber());
        $this->detail($draft)->assertDontSee('Ticket #');
    }

    // ─── 4. Titre du fallback ───────────────────────────

    public function test_fallback_title_skips_greetings_and_courtesy(): void
    {
        $cases = [
            "Bonjour, le client Garage Martin ne voit plus ses photos depuis hier. Merci"
                => 'Le client Garage Martin ne voit plus ses photos depuis hier',
            "Bonjour Paul,\nJ'espère que vous allez bien.\nLes annonces de Transports Duval ne remontent plus sur Leboncoin."
                => 'Les annonces de Transports Duval ne remontent plus sur Leboncoin',
            "Bonjour à tous ! Je vous contacte car Mme Leroy n'arrive plus à se connecter."
                => "Mme Leroy n'arrive plus à se connecter",
            "Bonjour,\n\nJ’espère que tu vas bien. Petit souci : la facture de mars du client Agro-Tech est en double."
                => 'La facture de mars du client Agro-Tech est en double',
            "Bonjour Madame, Je me permets de vous contacter au sujet de traductions manquantes sur la version allemande"
                => 'Traductions manquantes sur la version allemande',
            // Sans ponctuation après « Bonjour », le nom qui suit est celui du client : il reste
            "Bonjour Transports Duval n'arrive plus à modifier ses annonces"
                => "Transports Duval n'arrive plus à modifier ses annonces",
            "Bonjour le site plante quand on dépose une annonce, aidez-moi"
                => 'Le site plante quand on dépose une annonce, aidez-moi',
            // Sans politesse : inchangé
            'Le flux XML de Garage Martin ne passe plus depuis lundi'
                => 'Le flux XML de Garage Martin ne passe plus depuis lundi',
        ];

        foreach ($cases as $description => $expected) {
            $this->assertSame($expected, AIClassifierService::fallbackTitle($description), $description);
        }

        $long = AIClassifierService::fallbackTitle("Bonjour, désolé de vous déranger, le client ne reçoit plus les leads par mail depuis ce matin et il est furieux car il perd des ventes");
        $this->assertLessThanOrEqual(80, mb_strlen($long));
        $this->assertStringStartsWith('Le client ne reçoit plus les leads', $long);
        $this->assertStringEndsWith('…', $long);
    }

    public function test_fallback_ticket_gets_a_presentable_title(): void
    {
        config(['supportia.ai_provider' => 'openai', 'supportia.openai.api_key' => 'sk-test']);
        Http::fake(['api.openai.com/*' => Http::response(['error' => ['message' => 'Internal error']], 500)]);

        $this->actingAs($this->user)->post('/support/tickets', [
            'description'        => "Bonjour,\nle client n'arrive plus à importer ses annonces depuis ce matin.\nMerci",
            'is_specific_client' => '0',
        ], ['Accept' => 'application/json'])->assertOk();

        $ticket = SupportTicket::firstOrFail();
        $this->assertSame('fallback_keywords', $ticket->ai_provider);
        $this->assertSame("Le client n'arrive plus à importer ses annonces depuis ce matin", $ticket->ai_title);
    }
}
