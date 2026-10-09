<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Services\AIClassifierService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Garde-fous contre une valeur d'AI_PROVIDER mal saisie (opneai, Openai…), qui ferait retomber
 * silencieusement sur Claude, donc sans clé Claude sur le fallback mots-clés permanent.
 */
class AiProviderGuardTest extends TestCase
{
    use RefreshDatabase;

    // ─── AIClassifierService : warning, comportement inchangé ───────────

    private function classify(?string $provider): array
    {
        config(['supportia.ai_provider' => $provider, 'supportia.claude_api_key' => null, 'supportia.openai.api_key' => 'sk-test']);
        Http::fake();
        Log::spy();

        $org = Organization::create(['name' => 'Via-Mobilis', 'slug' => 'via-mobilis', 'is_active' => true]);

        return app(AIClassifierService::class)->classify($org, "Le client n'arrive plus à importer ses annonces depuis ce matin");
    }

    /** @return array<string, array{0: ?string}> */
    public static function unknownProviders(): array
    {
        return ['faute de frappe' => ['opneai'], 'majuscule' => ['Openai'], 'suffixe' => ['openai_'], 'vide' => ['']];
    }

    #[DataProvider('unknownProviders')]
    public function test_unknown_provider_is_logged_and_still_falls_back_to_claude(?string $provider): void
    {
        $result = $this->classify($provider);

        Log::shouldHaveReceived('warning')->withArgs(fn ($message, $context = []) => $message === 'AI_PROVIDER inconnu, repli sur claude'
            && $context['ai_provider'] === $provider
            && $context['admis'] === ['openai', 'claude', 'local'])->once();

        // Comportement inchangé : Claude tenté (sans clé), puis fallback mots-clés
        $this->assertSame('fallback_keywords', $result['provider']);
        $this->assertSame('claude', $result['_meta']['attempted_provider']);
        $this->assertSame('Aucune clé Claude API configurée', $result['_meta']['error']);
        Http::assertNothingSent();
    }

    public function test_known_provider_logs_no_warning(): void
    {
        $this->classify('claude');

        Log::shouldNotHaveReceived('warning', ['AI_PROVIDER inconnu, repli sur claude', \Mockery::any()]);
    }

    // ─── scripts/deploy.sh ───────────────────────────────────────────────

    /**
     * Lance deploy.sh dans un dossier temporaire. PHP_BIN introuvable : le script s'arrête juste
     * après les contrôles du .env, sans rien toucher (« php introuvable » = contrôles passés).
     */
    private function deploy(string $aiProviderLine): \Illuminate\Contracts\Process\ProcessResult
    {
        $dir = sys_get_temp_dir().'/zeno-deploy-'.uniqid();
        File::ensureDirectoryExists("{$dir}/scripts");
        File::copy(base_path('scripts/deploy.sh'), "{$dir}/scripts/deploy.sh");
        File::put("{$dir}/.env", implode("\n", array_filter([
            'APP_ENV=production', 'APP_DEBUG=false', 'APP_KEY=base64:test', 'GLPI_DRY_RUN=false',
            $aiProviderLine, 'OPENAI_API_KEY=sk-test',
        ]))."\n");

        try {
            return Process::path($dir)->env(['PHP_BIN' => '/inexistant/php'])->run(['bash', 'scripts/deploy.sh']);
        } finally {
            File::deleteDirectory($dir);
        }
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function rejectedLines(): array
    {
        return [
            'faute de frappe' => ['AI_PROVIDER=opneai', 'opneai'],
            'majuscule'       => ['AI_PROVIDER=Openai', 'Openai'],
            'suffixe'         => ['AI_PROVIDER=openai_', 'openai_'],
            'entre guillemets' => ['AI_PROVIDER="opneai"', 'opneai'],
            'vide'            => ['AI_PROVIDER=', ''],
        ];
    }

    #[DataProvider('rejectedLines')]
    public function test_deploy_refuses_unknown_provider_naming_value_and_allowed_ones(string $line, string $value): void
    {
        $result = $this->deploy($line);

        $this->assertSame(1, $result->exitCode());
        $this->assertStringContainsString(
            "AI_PROVIDER=\"{$value}\" n'est pas une valeur admise (openai, claude ou local, en minuscules)",
            $result->errorOutput()
        );
    }

    /** @return array<string, array{0: string}> */
    public static function acceptedLines(): array
    {
        return ['openai' => ['AI_PROVIDER=openai'], 'claude' => ['AI_PROVIDER=claude'], 'absente (défaut openai)' => ['']];
    }

    #[DataProvider('acceptedLines')]
    public function test_deploy_accepts_known_or_absent_provider(string $line): void
    {
        $result = $this->deploy($line);

        $this->assertStringNotContainsString("n'est pas une valeur admise", $result->errorOutput());
        $this->assertStringContainsString('php introuvable', $result->errorOutput());
    }

    public function test_deploy_still_refuses_local(): void
    {
        $this->assertStringContainsString('AI_PROVIDER=local interdit en production', $this->deploy('AI_PROVIDER=local')->errorOutput());
    }
}
