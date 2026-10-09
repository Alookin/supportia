<?php

namespace App\Services;

use App\Exceptions\AiProviderIncidentException;
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
     * Vérifie que la description contient un contenu suffisamment exploitable.
     * Rejette : moins de 3 mots distincts, patterns de test/lorem/garbage.
     */
    public function isDescriptionSuffisante(string $description): bool
    {
        $lower = mb_strtolower(trim($description));

        // Que des chiffres, espaces et ponctuation
        if (preg_match('/^[\d\s\p{P}]+$/u', $lower)) {
            return false;
        }

        // Caractère unique répété 4+ fois de suite (aaaa, zzzz, !!!!)
        if (preg_match('/(.)\1{3,}/u', $lower)) {
            return false;
        }

        // Mots de garbage connus
        $garbageWords = [
            'test', 'lorem', 'ipsum', 'asdf', 'qwerty', 'azerty',
            'xxx', 'abc', 'aaa', 'bbb', 'zzz', '123', '456', '789',
            'toto', 'tata', 'titi', 'blabla', 'foo', 'bar', 'baz',
            'truc', 'machin', 'chose',
        ];

        // Tokens significatifs : 3+ caractères, non purement numériques
        $tokens = preg_split('/[\s\p{P}]+/u', $lower, -1, PREG_SPLIT_NO_EMPTY);
        $meaningful = array_values(array_unique(array_filter(
            $tokens,
            fn ($w) => mb_strlen($w) >= 3 && ! is_numeric($w)
        )));

        // Tous les mots sont des garbage words
        if (! empty($meaningful) && array_sum(array_map(
            fn ($w) => in_array($w, $garbageWords, true) ? 1 : 0,
            $meaningful
        )) === count($meaningful)) {
            return false;
        }

        // Moins de 3 mots distincts significatifs
        if (count($meaningful) < 3) {
            return false;
        }

        return true;
    }

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
        ?int $teamId = null,
    ): array {
        // Seules les catégories de l'équipe du demandeur (+ communes) sont proposées à l'IA
        $categories = $organization->activeCategories()->forTeam($teamId)->get();
        $prompt = $this->buildPrompt($description, $clientName, $categories);

        // Toute autre valeur que « openai » ou « local » retombe sur Claude
        $provider = match (config('supportia.ai_provider')) {
            'openai' => 'openai',
            'local'  => 'local',
            default  => 'claude',
        };

        try {
            $start = microtime(true);
            $result = match ($provider) {
                'openai' => $this->callOpenAi($prompt),
                'local'  => $this->callLocal($prompt),
                'claude' => $this->callClaude($organization, $prompt),
            };
            $latencyMs = (int) ((microtime(true) - $start) * 1000);

            $result['provider'] = $provider;
            $result['_meta']    = ['latency_ms' => $latencyMs, 'error' => null] + ($result['_meta'] ?? []);

            if ($ticket) {
                $this->logFor($ticket, $result);
            }

            return $result;
        } catch (\Throwable $e) {
            // Les incidents (clé, quota) sont déjà journalisés en error par le moteur
            if (! $e instanceof AiProviderIncidentException) {
                Log::warning('AI classification failed, using keyword fallback', [
                    'provider'     => config('supportia.ai_provider'),
                    'organization' => $organization->slug,
                    'error'        => $e->getMessage(),
                ]);
            }

            $result = $this->fallbackClassify($description, $categories);
            $result['_meta'] = ['latency_ms' => 0, 'error' => mb_substr($e->getMessage(), 0, 1000)];

            if ($ticket) {
                $this->logFor($ticket, $result);
            }

            return $result;
        }
    }

    /**
     * Construit le prompt de classification (commun à tous les moteurs).
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
            ->when(! config('supportia.claude_verify_ssl', true), fn ($h) => $h->withoutVerifying())
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

        return $this->parseClassification($data['content'][0]['text'] ?? '', [
            'model'             => (string) config('supportia.claude_model'),
            'prompt_tokens'     => $data['usage']['input_tokens'] ?? null,
            'completion_tokens' => $data['usage']['output_tokens'] ?? null,
        ]);
    }

    /**
     * Appelle l'API OpenAI (Chat Completions) et parse la réponse JSON.
     * Les modèles GPT-5.x refusent max_tokens : max_completion_tokens est obligatoire.
     */
    private function callOpenAi(string $prompt): array
    {
        $apiKey = config('supportia.openai.api_key');
        $model  = config('supportia.openai.model');

        if (empty($apiKey)) {
            $this->openAiIncident(AiProviderIncidentException::OPENAI_KEY_MISSING, 'OpenAI : clé absente (OPENAI_API_KEY vide)', $model);
        }

        $response = Http::timeout(config('supportia.openai.timeout', 10))
            ->withToken($apiKey)
            ->post(rtrim((string) config('supportia.openai.base_url'), '/') . '/chat/completions', [
                'model'                 => $model,
                'max_completion_tokens' => 1024,
                'temperature'           => 0,
                'response_format'       => ['type' => 'json_object'],
                'messages'              => [
                    ['role' => 'user', 'content' => $prompt],
                ],
            ]);

        // Le corps d'erreur n'est pas recopié : sur un 401, OpenAI y cite un fragment de la clé
        match ($response->status()) {
            401 => $this->openAiIncident(AiProviderIncidentException::OPENAI_KEY_REJECTED, 'OpenAI : clé refusée (401)', $model, $response->json('error.code')),
            429 => $this->openAiIncident(AiProviderIncidentException::OPENAI_QUOTA_EXCEEDED, 'OpenAI : quota épuisé (429)', $model, $response->json('error.code')),
            default => $response->throw(),
        };

        $data = $response->json();

        return $this->parseClassification($data['choices'][0]['message']['content'] ?? '', [
            'model'             => (string) $model,
            'prompt_tokens'     => $data['usage']['prompt_tokens'] ?? null,
            'completion_tokens' => $data['usage']['completion_tokens'] ?? null,
        ]);
    }

    /**
     * Journalise en error un incident OpenAI qui demande une intervention, puis lève
     * l'exception qui déclenche le fallback mots-clés (message « [CODE] … » dans ai_request_logs).
     */
    private function openAiIncident(string $incident, string $message, ?string $model, mixed $apiErrorCode = null): never
    {
        Log::error($message, array_filter([
            'incident'       => $incident,
            'model'          => $model,
            'api_error_code' => is_scalar($apiErrorCode) ? (string) $apiErrorCode : null, // insufficient_quota, rate_limit_exceeded, invalid_api_key…
        ]));

        throw new AiProviderIncidentException($incident, $message);
    }

    /**
     * Appelle un modèle local via une API compatible OpenAI (Ollama, LM Studio…).
     */
    private function callLocal(string $prompt): array
    {
        $model = config('supportia.local_ai.model');

        if (empty($model)) {
            throw new \RuntimeException('LOCAL_AI_MODEL non configuré');
        }

        $response = Http::timeout(config('supportia.ai_timeout', 25))
            ->when(config('supportia.local_ai.api_key'), fn ($h, $key) => $h->withToken($key))
            ->post(rtrim((string) config('supportia.local_ai.base_url'), '/') . '/chat/completions', [
                'model'       => $model,
                'temperature' => 0,
                'messages'    => [
                    ['role' => 'system', 'content' => 'Tu réponds uniquement par un objet JSON valide, sans texte autour.'],
                    ['role' => 'user', 'content' => $prompt],
                ],
            ]);

        $response->throw();

        $data = $response->json();

        return $this->parseClassification($data['choices'][0]['message']['content'] ?? '', [
            'model'             => $model,
            'prompt_tokens'     => $data['usage']['prompt_tokens'] ?? null,
            'completion_tokens' => $data['usage']['completion_tokens'] ?? null,
        ]);
    }

    /**
     * Extrait et normalise le JSON de classification renvoyé par le modèle.
     */
    private function parseClassification(string $text, array $meta): array
    {
        // Le modèle peut ajouter du texte (ou un bloc ```json) autour
        if (! preg_match('/\{[\s\S]*\}/', $text, $matches)) {
            throw new \RuntimeException(
                'Impossible de parser la réponse IA : ' . mb_substr($text, 0, 200)
            );
        }

        $result = json_decode($matches[0], true, 512, JSON_THROW_ON_ERROR);

        return [
            '_meta'         => $meta,
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
            'title'         => self::fallbackTitle($description),
            'body'          => $description,
            'category_slug' => $bestSlug,
            'priority'      => 3, // par défaut en mode dégradé
            'confidence'    => $bestScore > 0 ? 0.35 : 0.1,
            'provider'      => 'fallback_keywords',
        ];
    }

    /**
     * Trace un appel de classification (provider, latence, tokens, erreur éventuelle)
     * une fois le ticket créé. Ne fait jamais échouer le flux principal.
     */
    public function logFor(SupportTicket $ticket, array $classification): void
    {
        $meta = $classification['_meta'] ?? [];
        unset($classification['_meta']);

        try {
            AiRequestLog::create([
                'support_ticket_id' => $ticket->id,
                'provider'          => $classification['provider'] ?? 'unknown',
                'model'             => $meta['model'] ?? 'keywords',
                'prompt_tokens'     => $meta['prompt_tokens'] ?? null,
                'completion_tokens' => $meta['completion_tokens'] ?? null,
                'latency_ms'        => $meta['latency_ms'] ?? 0,
                'raw_response'      => $classification,
                'error'             => $meta['error'] ?? null,
            ]);
        } catch (\Throwable $e) {
            Log::error('Failed to log AI request', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Titre du fallback : première phrase porteuse d'information, sans les formules de politesse
     * d'ouverture (« Bonjour Paul, », « J'espère que vous allez bien. », « Je vous contacte car… »).
     * Sert à chaque incident du moteur d'IA : il doit rester présentable dans les listes.
     */
    public static function fallbackTitle(string $description): string
    {
        $sentences = preg_split('/(?<=[.!?…])\s+|\R+/u', trim($description), -1, PREG_SPLIT_NO_EMPTY);

        foreach ($sentences as $sentence) {
            $sentence = self::stripCourtesy($sentence);
            $words = preg_split('/[\s\p{P}]+/u', $sentence, -1, PREG_SPLIT_NO_EMPTY);

            // « Merci d'avance. », « Petit souci : » … ne suffisent pas à faire un titre
            if (count(array_filter($words, fn ($w) => mb_strlen($w) >= 3)) >= 3) {
                return self::shortTitle(self::upperFirst(rtrim($sentence, " \t.;:,!")));
            }
        }

        // Rien de mieux : le texte entier, débarrassé des formules d'ouverture
        $text = self::stripCourtesy(trim(preg_replace('/\s+/u', ' ', $description)));

        return self::shortTitle(self::upperFirst($text !== '' ? $text : trim($description)));
    }

    /** Retire, en début de phrase, salutations et formules d'introduction (plusieurs à la suite). */
    private static function stripCourtesy(string $text): string
    {
        $greeting = '(?:bonjour|bonsoir|salut|hello|coucou|hey|re)';
        // Destinataire, seulement s'il est suivi d'une ponctuation : « Bonjour Paul, », « Bonjour à tous ! »
        // (« Bonjour Transports Duval n'arrive plus… » garde le nom du client)
        $addressee = "(?:\\s+(?:à|a)?\\s*(?:tous|toutes|vous|tout le monde|l['’]équipe|la team|madame|monsieur|\\p{Lu}[\\p{L}'-]*)){1,3}";

        $patterns = [
            "/^(?:re|tr|fwd?)\\s*:\\s*/iu",
            "/^{$greeting}\\b(?:{$addressee}\\s*[,.!?:;]+|\\s*[,.!?:;]*)\\s*/iu",
            "/^j['’]espère que (?:vous allez|tu vas|tout va) bien\\s*[,.!?:;]*\\s*/iu",
            "/^(?:désolée?|pardon) de (?:vous|te) déranger\\s*[,.!?:;]*\\s*/iu",
            "/^(?:je me permets de (?:vous|te) (?:contacter|écrire|solliciter)|je (?:vous|te) (?:contacte|écris|sollicite))(?:\\s+(?:car|parce que|pour|concernant|au sujet de|à propos de))?\\s*[,.!?:;]*\\s*/iu",
            "/^(?:petite question|petit souci|petit problème|une question)\\s*[,.!?:;]+\\s*/iu",
        ];

        do {
            $before = $text;
            $text = trim(preg_replace($patterns, '', $text));
        } while ($text !== $before && $text !== '');

        return $text;
    }

    private static function upperFirst(string $text): string
    {
        return mb_strtoupper(mb_substr($text, 0, 1)) . mb_substr($text, 1);
    }

    /** Titre ≤ 80 caractères, coupé sur un mot entier, avec « … » si tronqué. */
    public static function shortTitle(string $text, int $max = 80): string
    {
        $text = trim(preg_replace('/\s+/', ' ', $text));
        if (mb_strlen($text) <= $max) {
            return $text;
        }
        $cut = mb_substr($text, 0, $max - 1);
        $space = mb_strrpos($cut, ' ');

        return rtrim($space > 40 ? mb_substr($cut, 0, $space) : $cut, ' ,;:-') . '…';
    }
}
