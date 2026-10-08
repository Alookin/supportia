<?php

namespace Tests\Feature;

use App\Models\AiRequestLog;
use App\Models\GlpiCategoryMap;
use App\Models\Organization;
use App\Models\SupportTicket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * Moteur « openai » (Chat Completions), incidents (clé, quota) et déclenchement du fallback
 * mots-clés, avec OpenAI simulé (aucun appel réel) et GLPI en mode simulation.
 */
class OpenAiProviderTest extends TestCase
{
    use RefreshDatabase;

    private const OPENAI = 'https://api.openai.com/v1/chat/completions';

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
        ]);

        $org = Organization::create([
            'name' => 'Via-Mobilis', 'slug' => 'via-mobilis', 'is_active' => true,
            'glpi_api_url' => 'https://glpi.test/apirest.php', 'glpi_app_token' => 'app', 'glpi_user_token' => 'user',
        ]);

        GlpiCategoryMap::create([
            'organization_id' => $org->id, 'glpi_category_id' => 18, 'slug' => 'tech_flux_bug_import',
            'label' => '[TECHNIQUE] Bug import', 'label_simple' => "Problème d'import",
            'keywords' => ['import'], 'is_active' => true, 'is_visible_to_users' => true,
        ]);

        $this->user = User::factory()->create(['organization_id' => $org->id]);
    }

    /** Réponse Chat Completions simulée. */
    private function openAi(?string $content = null): array
    {
        $content ??= json_encode([
            'category_slug' => 'tech_flux_bug_import', 'priority' => 4,
            'title' => 'Import bloqué', 'body' => 'Le flux ne remonte plus.', 'confidence' => 0.93,
        ]);

        return [
            'choices' => [['message' => ['role' => 'assistant', 'content' => $content], 'finish_reason' => 'stop']],
            'usage'   => ['prompt_tokens' => 3002, 'completion_tokens' => 210],
        ];
    }

    private function submit()
    {
        return $this->actingAs($this->user)->post('/support/tickets', [
            'description'        => "Le client n'arrive plus à importer ses annonces depuis ce matin",
            'is_specific_client' => '0',
        ], ['Accept' => 'application/json']);
    }

    private function assertFellBackToKeywords(string $expectedError): void
    {
        $ticket = SupportTicket::firstOrFail();
        $this->assertSame('fallback_keywords', $ticket->ai_provider);
        $this->assertSame('tech_flux_bug_import', $ticket->ai_category_slug);
        $this->assertSame(3, (int) $ticket->ai_priority);

        $log = AiRequestLog::firstOrFail();
        $this->assertSame('fallback_keywords', $log->provider);
        $this->assertSame('keywords', $log->model);
        $this->assertStringContainsString($expectedError, (string) $log->error);
    }

    /** Incident : journalisé en error (jamais en warning), code reconnaissable en base. */
    private function assertIncident(string $code, string $message): void
    {
        $this->assertFellBackToKeywords("[{$code}] {$message}");
        $this->assertStringStartsWith("[{$code}]", AiRequestLog::firstOrFail()->error);
        $this->assertSame(1, AiRequestLog::where('error', 'like', '[OPENAI_%')->count());

        Log::shouldHaveReceived('error')->once()->withArgs(fn ($msg, $ctx) => $msg === $message && $ctx['incident'] === $code);
        Log::shouldNotHaveReceived('warning');
    }

    /** Échec ordinaire : warning, et rien qui ressemble à un incident. */
    private function assertOrdinaryFailure(): void
    {
        $this->assertSame(0, AiRequestLog::where('error', 'like', '[OPENAI_%')->count());
        Log::shouldHaveReceived('warning')->once();
        Log::shouldNotHaveReceived('error');
    }

    public function test_openai_is_the_default_provider(): void
    {
        $saved = [getenv('AI_PROVIDER'), $_ENV['AI_PROVIDER'] ?? null, $_SERVER['AI_PROVIDER'] ?? null];
        putenv('AI_PROVIDER');
        unset($_ENV['AI_PROVIDER'], $_SERVER['AI_PROVIDER']);

        try {
            $config = require config_path('supportia.php');
        } finally {
            putenv('AI_PROVIDER=' . $saved[0]);
            $_ENV['AI_PROVIDER'] = $saved[1];
            $_SERVER['AI_PROVIDER'] = $saved[2];
        }

        $this->assertSame('openai', $config['ai_provider']);
        $this->assertSame('https://api.openai.com/v1', $config['openai']['base_url']);
        $this->assertSame('gpt-5.4-mini', $config['openai']['model']);
    }

    public function test_openai_classifies_with_chat_completions_and_is_logged(): void
    {
        Http::fake(['api.openai.com/*' => Http::response($this->openAi())]);

        $this->submit()->assertOk()->assertJsonPath('status', 'created');

        Http::assertSent(fn (HttpRequest $r) => $r->url() === self::OPENAI
            && $r->hasHeader('Authorization', 'Bearer sk-test')
            && $r['model'] === 'gpt-5.4-mini'
            && $r['max_completion_tokens'] === 1024
            && ! isset($r['max_tokens'])
            && $r['temperature'] === 0
            && $r['response_format'] === ['type' => 'json_object']
            && $r['messages'][0]['role'] === 'user'
            && str_contains($r['messages'][0]['content'], 'tech_flux_bug_import'));

        $ticket = SupportTicket::firstOrFail();
        $this->assertSame('openai', $ticket->ai_provider);
        $this->assertSame(4, (int) $ticket->ai_priority);

        $log = AiRequestLog::firstOrFail();
        $this->assertSame('openai', $log->provider);
        $this->assertSame('gpt-5.4-mini', $log->model);
        $this->assertSame(3002, $log->prompt_tokens);
        $this->assertSame(210, $log->completion_tokens);
        $this->assertNull($log->error);
    }

    public function test_custom_base_url_and_model_are_used(): void
    {
        config(['supportia.openai.base_url' => 'https://proxy.test/v1/', 'supportia.openai.model' => 'gpt-5.4-nano']);
        Http::fake(['proxy.test/*' => Http::response($this->openAi())]);

        $this->submit()->assertOk();

        Http::assertSent(fn (HttpRequest $r) => $r->url() === 'https://proxy.test/v1/chat/completions' && $r['model'] === 'gpt-5.4-nano');
        $this->assertSame('gpt-5.4-nano', AiRequestLog::firstOrFail()->model);
    }

    public function test_rejected_key_is_an_incident_and_falls_back(): void
    {
        Log::spy();
        Http::fake(['api.openai.com/*' => Http::response(['error' => [
            'message' => 'Incorrect API key provided: sk-proj-****abcd.', 'code' => 'invalid_api_key',
        ]], 401)]);

        $this->submit()->assertOk();

        $this->assertIncident('OPENAI_KEY_REJECTED', 'OpenAI : clé refusée (401)');
        // Le fragment de clé cité par OpenAI n'est jamais recopié
        $this->assertStringNotContainsString('sk-proj', AiRequestLog::firstOrFail()->error);
    }

    public function test_exhausted_quota_is_an_incident_and_falls_back(): void
    {
        Log::spy();
        Http::fake(['api.openai.com/*' => Http::response(['error' => ['message' => 'You exceeded your current quota', 'code' => 'insufficient_quota']], 429)]);

        $this->submit()->assertOk();

        $this->assertIncident('OPENAI_QUOTA_EXCEEDED', 'OpenAI : quota épuisé (429)');
        Log::shouldHaveReceived('error')->withArgs(fn ($msg, $ctx) => ($ctx['api_error_code'] ?? null) === 'insufficient_quota');
    }

    public function test_missing_openai_key_is_an_incident_and_never_calls_the_api(): void
    {
        Log::spy();
        config(['supportia.openai.api_key' => null]);
        Http::fake();

        $this->submit()->assertOk();

        Http::assertNotSent(fn (HttpRequest $r) => str_contains($r->url(), 'openai.com'));
        $this->assertIncident('OPENAI_KEY_MISSING', 'OpenAI : clé absente (OPENAI_API_KEY vide)');
    }

    public function test_openai_server_error_is_an_ordinary_failure(): void
    {
        Log::spy();
        Http::fake(['api.openai.com/*' => Http::response(['error' => ['message' => 'Internal error']], 500)]);

        $this->submit()->assertOk();

        $this->assertFellBackToKeywords('500');
        $this->assertOrdinaryFailure();
    }

    public function test_openai_timeout_is_an_ordinary_failure(): void
    {
        Log::spy();
        Http::fake(['api.openai.com/*' => fn () => throw new ConnectionException('cURL error 28: Operation timed out')]);

        $this->submit()->assertOk();

        $this->assertFellBackToKeywords('timed out');
        $this->assertOrdinaryFailure();
    }

    public function test_openai_unparseable_answer_is_an_ordinary_failure(): void
    {
        Log::spy();
        Http::fake(['api.openai.com/*' => Http::response($this->openAi('Désolé, je ne peux pas.'))]);

        $this->submit()->assertOk();

        $this->assertFellBackToKeywords('Impossible de parser');
        $this->assertOrdinaryFailure();
    }

    public function test_claude_still_works_and_falls_back_the_same_way(): void
    {
        config(['supportia.ai_provider' => 'claude', 'supportia.claude_api_key' => 'sk-ant-test']);
        Http::fake(['api.anthropic.com/*' => Http::response(['error' => ['message' => 'overloaded']], 529)]);

        $this->submit()->assertOk();

        Http::assertNotSent(fn (HttpRequest $r) => str_contains($r->url(), 'openai.com'));
        $this->assertFellBackToKeywords('529');
    }

    public function test_local_provider_error_falls_back_the_same_way(): void
    {
        config(['supportia.ai_provider' => 'local', 'supportia.local_ai.model' => 'qwen2.5:7b']);
        Http::fake(['127.0.0.1:11434/*' => fn () => throw new ConnectionException('Connection refused')]);

        $this->submit()->assertOk();

        Http::assertNotSent(fn (HttpRequest $r) => str_contains($r->url(), 'openai.com'));
        $this->assertFellBackToKeywords('Connection refused');
    }
}
