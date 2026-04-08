<?php

namespace App\Services;

use App\Models\AiRequestLog;
use App\Models\GlpiCategoryMap;
use App\Models\Organization;
use App\Models\SupportTicket;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class AIClassifierService
{
    private const CLAUDE_API_URL = 'https://api.anthropic.com/v1/messages';

    /**
     * Classifie et enrichit une description de ticket.
     *
     * @return array{
     *   title: string,
     *   body: string,
     *   category_slug: string,
     *   priority: int,
     *   confidence: float,
     *   provider: string
     * }
     */
    public function classify(
        Organization $organization,
        string $description,
        ?string $clientName = null,
        ?SupportTicket $ticket = null,
    ): array {
        $categories = $organization->activeCategories()->get();
        $prompt = $this->buildPrompt($description, $clientName, $categories);

        try {
            $start = microtime(true);
            $result = $this->callClaude($organization, $prompt);
            $latencyMs = (int) ((microtime(true) - $start) * 1000);

            $result['provider'] = 'claude';

            if ($ticket) {
                $this->logRequest($ticket, 'claude', $latencyMs, $result);
            }

            return $result;
        } catch (\Throwable $e) {
            Log::warning('Claude API failed, using keyword fallback', [
                'organization' => $organization->slug,
                'error'        => $e->getMessage(),
            ]);

            $result = $this->fallbackClassify($description, $categories);

            if ($ticket) {
                $this->logRequest($ticket, 'fallback_keywords', 0, $result, $e->getMessage());
            }

            return $result;
        }
    }

    /**
     * Construit le prompt de classification pour Claude.
     * Le prompt injecte dynamiquement les catégories de l'organisation.
     */
    private function buildPrompt(string $description, ?string $clientName, $categories): string
    {
        $categoryList = $categories
            ->map(fn(GlpiCategoryMap $cat) => $cat->toPromptLine())
            ->implode("\n");

        $clientContext = $clientName
            ? "Clients concernés : {$clientName}"
            : 'Client non spécifié';

        return <<<PROMPT
Tu es un assistant de support technique pour une entreprise qui utilise GLPI comme outil de ticketing.

Un utilisateur non-technique (commercial) vient de signaler un problème client. À partir de sa description en langage naturel, tu dois produire un ticket de support structuré.

## Catégories disponibles dans GLPI
{$categoryList}

## Description du commercial
{$clientContext}

"{$description}"

## Consignes
1. Choisis la catégorie la plus appropriée parmi celles listées (retourne son slug exact).
2. Attribue une priorité de 1 à 5 :
   - 1 = Très basse (question, demande d'info)
   - 2 = Basse (anomalie mineure, pas d'impact immédiat)
   - 3 = Moyenne (fonctionnalité dégradée, contournement possible)
   - 4 = Haute (service inaccessible, perte de production active)
   - 5 = Très haute (perte de données, tous les utilisateurs impactés)
3. Rédige un titre concis (max 80 caractères) qui résume le problème.
4. Rédige un corps de ticket structuré pour l'équipe technique :
   - Contexte client
   - Symptôme observé
   - Impact
   - Étapes de reproduction si déductibles
5. Indique ton niveau de confiance (0.0 à 1.0) sur la classification.

## Format de réponse
Réponds UNIQUEMENT avec ce JSON, sans aucun texte autour, sans backticks :
{"category_slug":"...","priority":3,"title":"...","body":"...","confidence":0.85}
PROMPT;
    }

    /**
     * Appelle l'API Claude et parse la réponse JSON.
     */
    private function callClaude(Organization $organization, string $prompt): array
    {
        $apiKey = $organization->getClaudeApiKey();

        if (empty($apiKey)) {
            throw new \RuntimeException('Aucune clé Claude API configurée');
        }

        $response = Http::timeout(config('supportia.ai_timeout', 5))
            ->withHeaders([
                'x-api-key'         => $apiKey,
                'anthropic-version' => '2023-06-01',
            ])
            ->post(self::CLAUDE_API_URL, [
                'model'      => config('supportia.claude_model'),
                'max_tokens' => 1024,
                'messages'   => [
                    ['role' => 'user', 'content' => $prompt],
                ],
            ]);

        $response->throw();

        $data = $response->json();
        $text = $data['content'][0]['text'] ?? '';

        // Extraire le JSON de la réponse (Claude peut ajouter du texte autour)
        if (! preg_match('/\{[\s\S]*\}/', $text, $matches)) {
            throw new \RuntimeException(
                'Impossible de parser la réponse IA : ' . mb_substr($text, 0, 200)
            );
        }

        $result = json_decode($matches[0], true, 512, JSON_THROW_ON_ERROR);

        return [
            'title'         => mb_substr($result['title'] ?? 'Ticket sans titre', 0, 500),
            'body'          => $result['body'] ?? $text,
            'category_slug' => $result['category_slug'] ?? 'autre',
            'priority'      => min(5, max(1, (int) ($result['priority'] ?? 3))),
            'confidence'    => min(1.0, max(0.0, (float) ($result['confidence'] ?? 0.5))),
        ];
    }

    /**
     * Fallback basique par scoring de mots-clés quand l'IA est indisponible.
     * Pas parfait, mais ça permet de ne pas bloquer les commerciaux.
     */
    private function fallbackClassify(string $description, $categories): array
    {
        $descLower = mb_strtolower($description);
        $bestSlug = 'autre';
        $bestLabel = 'Autre';
        $bestScore = 0;

        foreach ($categories as $cat) {
            $score = 0;
            foreach ($cat->keywords ?? [] as $keyword) {
                if (str_contains($descLower, mb_strtolower($keyword))) {
                    $score++;
                }
            }
            if ($score > $bestScore) {
                $bestSlug  = $cat->slug;
                $bestLabel = $cat->label;
                $bestScore = $score;
            }
        }

        return [
            'title'         => mb_substr(
                                    preg_split('/[.?!,]/', trim($description))[0],
                                    0, 80
                                ),
            'body'          => $description,
            'category_slug' => $bestSlug,
            'priority'      => 3, // par défaut en mode dégradé
            'confidence'    => $bestScore > 0 ? 0.35 : 0.1,
            'provider'      => 'fallback_keywords',
        ];
    }

    private function logRequest(
        SupportTicket $ticket,
        string $provider,
        int $latencyMs,
        array $result,
        ?string $error = null,
    ): void {
        try {
            AiRequestLog::create([
                'support_ticket_id' => $ticket->id,
                'provider'          => $provider,
                'model'             => config('supportia.claude_model'),
                'latency_ms'        => $latencyMs,
                'raw_response'      => $result,
                'error'             => $error,
            ]);
        } catch (\Throwable $e) {
            // Ne jamais planter le flux principal pour un log
            Log::error('Failed to log AI request', ['error' => $e->getMessage()]);
        }
    }
}
