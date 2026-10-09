<?php

namespace Tests\Feature;

use App\Models\AiRequestLog;
use App\Models\GlpiCategoryMap;
use App\Models\Organization;
use App\Models\SupportTicket;
use App\Models\Team;
use App\Models\User;
use App\Services\AiPricing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Traçabilité de la consommation IA : attribution, tokens, durée, coût, cause des fallbacks.
 * Observabilité uniquement : la classification elle-même est couverte par OpenAiProviderTest.
 */
class AiUsageLogsTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;
    private Team $team;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'supportia.ai_provider'     => 'openai',
            'supportia.openai.base_url' => 'https://api.openai.com/v1',
            'supportia.openai.api_key'  => 'sk-test',
            'supportia.openai.model'    => 'gpt-5.4-mini',
            'supportia.glpi_dry_run'    => true,
            'supportia.ai_pricing'      => ['gpt-5.4-mini' => ['input' => null, 'cached_input' => null, 'output' => null]],
        ]);

        $this->org  = Organization::create(['name' => 'Via-Mobilis', 'slug' => 'via-mobilis', 'is_active' => true]);
        $this->team = Team::create(['organization_id' => $this->org->id, 'name' => 'Ventes', 'slug' => 'ventes']);

        GlpiCategoryMap::create([
            'organization_id' => $this->org->id, 'glpi_category_id' => 18, 'slug' => 'tech_flux_bug_import',
            'label' => '[TECHNIQUE] Bug import', 'label_simple' => "Problème d'import",
            'keywords' => ['import'], 'is_active' => true, 'is_visible_to_users' => true,
        ]);

        $this->user = User::factory()->create(['organization_id' => $this->org->id, 'team_id' => $this->team->id]);
    }

    private function openAi(float $confidence = 0.93): array
    {
        return [
            'choices' => [['message' => ['content' => json_encode([
                'category_slug' => 'tech_flux_bug_import', 'priority' => 4,
                'title' => 'Import bloqué', 'body' => 'Le flux ne remonte plus.', 'confidence' => $confidence,
            ])]]],
            'usage' => [
                'prompt_tokens' => 3002, 'completion_tokens' => 210, 'total_tokens' => 3212,
                'prompt_tokens_details'     => ['cached_tokens' => 1000],
                'completion_tokens_details' => ['reasoning_tokens' => 0],
            ],
        ];
    }

    private function submit()
    {
        return $this->actingAs($this->user)->post('/support/tickets', [
            'description'        => "Le client n'arrive plus à importer ses annonces depuis ce matin",
            'is_specific_client' => '0',
        ], ['Accept' => 'application/json'])->assertOk();
    }

    public function test_successful_call_records_attribution_tokens_and_duration(): void
    {
        Http::fake(['api.openai.com/*' => Http::response($this->openAi())]);

        $this->submit();

        $log = AiRequestLog::firstOrFail();
        $this->assertSame([$this->org->id, $this->user->id, $this->team->id], [$log->organization_id, $log->user_id, $log->team_id]);
        $this->assertSame(['openai', 'gpt-5.4-mini'], [$log->provider, $log->model]);
        $this->assertSame(['openai', 'gpt-5.4-mini'], [$log->attempted_provider, $log->attempted_model]);
        $this->assertNull($log->fallback_reason);
        $this->assertSame([3002, 210, 3212, 1000, 0], [$log->prompt_tokens, $log->completion_tokens, $log->total_tokens, $log->cached_tokens, $log->reasoning_tokens]);
        $this->assertSame(1000, $log->usage_raw['prompt_tokens_details']['cached_tokens']);
        $this->assertNotNull($log->latency_ms);
        // Tarifs non renseignés : pas de coût inventé
        $this->assertNull($log->estimated_cost);
    }

    public function test_cost_comes_from_configured_prices(): void
    {
        config(['supportia.ai_pricing' => ['gpt-5.4-mini' => ['input' => 0.25, 'cached_input' => 0.025, 'output' => 2.0]]]);
        Http::fake(['api.openai.com/*' => Http::response($this->openAi())]);

        $this->submit();

        // (3002 - 1000) × 0,25 + 1000 × 0,025 + 210 × 2 = 945,5 $ par million de tokens
        $this->assertEqualsWithDelta(0.0009455, AiRequestLog::firstOrFail()->estimated_cost, 1e-9);

        // Sans cache : 100 × 0,25 + 10 × 2 = 45 $ par million
        $this->assertEqualsWithDelta(0.000045, AiPricing::estimate('gpt-5.4-mini', 100, 10), 1e-9);
        $this->assertNull(AiPricing::estimate('modele-inconnu', 100, 10));

        // cached_input non renseigné : le cache est facturé au tarif input (100 × 1 + 10 × 2 = 120)
        config(['supportia.ai_pricing' => ['gpt-5.4-mini' => ['input' => 1.0, 'cached_input' => null, 'output' => 2.0]]]);
        $this->assertEqualsWithDelta(0.00012, AiPricing::estimate('gpt-5.4-mini', 100, 10, 50), 1e-9);
    }

    /** @return array<string, array{0: \Closure, 1: string}> */
    public static function failures(): array
    {
        return [
            'timeout'            => [fn () => throw new ConnectionException('cURL error 28: Operation timed out'), 'timeout'],
            'connexion refusée'  => [fn () => throw new ConnectionException('cURL error 7: Connection refused'), 'connection_error'],
            'erreur serveur'     => [fn () => Http::response(['error' => ['message' => 'boom']], 500), 'http_error'],
            'clé refusée'        => [fn () => Http::response(['error' => ['code' => 'invalid_api_key']], 401), 'key_rejected'],
            'quota épuisé'       => [fn () => Http::response(['error' => ['code' => 'insufficient_quota']], 429), 'quota_exceeded'],
            'réponse illisible'  => [fn () => Http::response(['choices' => [['message' => ['content' => 'Désolé.']]]]), 'invalid_response'],
        ];
    }

    #[DataProvider('failures')]
    public function test_fallback_records_attempted_engine_and_reason(\Closure $response, string $reason): void
    {
        Http::fake(['api.openai.com/*' => $response]);

        $this->submit();

        $log = AiRequestLog::firstOrFail();
        $this->assertSame(['fallback_keywords', 'keywords'], [$log->provider, $log->model]);
        $this->assertSame(['openai', 'gpt-5.4-mini'], [$log->attempted_provider, $log->attempted_model]);
        $this->assertSame($reason, $log->fallback_reason);
        $this->assertSame($this->user->id, $log->user_id);
        $this->assertNull($log->estimated_cost);
    }

    public function test_missing_key_is_a_fallback_reason(): void
    {
        config(['supportia.openai.api_key' => null]);
        Http::fake();

        $this->submit();

        $this->assertSame('key_missing', AiRequestLog::firstOrFail()->fallback_reason);
    }

    public function test_failed_call_keeps_its_real_duration(): void
    {
        Http::fake(['api.openai.com/*' => function () {
            usleep(60_000);
            throw new ConnectionException('cURL error 28: Operation timed out');
        }]);

        $this->submit();

        // Auparavant forcée à 0 en cas de fallback
        $this->assertGreaterThanOrEqual(50, AiRequestLog::firstOrFail()->latency_ms);
    }

    public function test_logs_survive_draft_cancellation_and_pruning(): void
    {
        Http::fake(['api.openai.com/*' => Http::response($this->openAi(confidence: 0.4))]);

        // Deux brouillons (confiance basse) : l'un annulé par le commercial, l'autre purgé la nuit
        $this->submit()->assertJsonPath('status', 'needs_review');
        $this->submit()->assertJsonPath('status', 'needs_review');
        [$cancelled, $abandoned] = SupportTicket::orderBy('id')->get()->all();

        $this->actingAs($this->user)->deleteJson("/support/tickets/{$cancelled->id}/draft")->assertOk();
        $abandoned->forceFill(['created_at' => now()->subDays(2)])->save();
        $this->artisan('zeno:prune-drafts')->assertSuccessful();

        $this->assertSame(0, SupportTicket::count());
        $this->assertSame(2, AiRequestLog::count());
        AiRequestLog::all()->each(function (AiRequestLog $log) {
            $this->assertNull($log->support_ticket_id);
            $this->assertSame($this->user->id, $log->user_id);
            $this->assertSame($this->team->id, $log->team_id);
            $this->assertSame(3212, $log->total_tokens);
        });
    }

    public function test_claude_usage_is_normalized(): void
    {
        config(['supportia.ai_provider' => 'claude', 'supportia.claude_api_key' => 'sk-ant-test', 'supportia.claude_model' => 'claude-sonnet-4-20250514']);
        Http::fake(['api.anthropic.com/*' => Http::response([
            'content' => [['type' => 'text', 'text' => json_encode(['category_slug' => 'tech_flux_bug_import', 'priority' => 3, 'title' => 'T', 'body' => 'B', 'confidence' => 0.9])]],
            'usage'   => ['input_tokens' => 900, 'output_tokens' => 120, 'cache_read_input_tokens' => 100],
        ])]);

        $this->submit();

        $log = AiRequestLog::firstOrFail();
        $this->assertSame(['claude', 'claude-sonnet-4-20250514'], [$log->attempted_provider, $log->attempted_model]);
        $this->assertSame([1000, 120, 1120, 100], [$log->prompt_tokens, $log->completion_tokens, $log->total_tokens, $log->cached_tokens]);
    }

    public function test_estimate_command_fills_missing_costs_once_prices_are_set(): void
    {
        Http::fake(['api.openai.com/*' => Http::response($this->openAi())]);
        $this->submit();
        $this->assertNull(AiRequestLog::firstOrFail()->estimated_cost);

        config(['supportia.ai_pricing' => ['gpt-5.4-mini' => ['input' => 0.25, 'cached_input' => 0.025, 'output' => 2.0]]]);
        $this->artisan('zeno:estimate-ai-costs')->expectsOutputToContain('1 appel(s) chiffré(s)')->assertSuccessful();

        $this->assertEqualsWithDelta(0.0009455, AiRequestLog::firstOrFail()->estimated_cost, 1e-9);
    }
}
