<?php

namespace App\Services;

use App\Models\GlpiCategoryMap;
use App\Models\Organization;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GlpiClientService
{
    /**
     * Crée un ticket dans GLPI pour l'organisation donnée.
     *
     * @return array{id: int, url: string}
     *
     * @throws \RuntimeException
     */
    public function createTicket(Organization $organization, array $ticketData): array
    {
        if (self::dryRun()) {
            $id = max(900000, (int) \App\Models\SupportTicket::where('glpi_ticket_id', '>=', 900000)->max('glpi_ticket_id') + 1);
            Log::info('[GLPI simulation] Ticket non envoyé', ['fake_id' => $id, 'content' => $this->formatContent($ticketData)] + $ticketData);

            return ['id' => $id, 'url' => $this->ticketUrl($organization, $id)];
        }

        if (! $organization->hasGlpiConfig()) {
            throw new \RuntimeException(
                "GLPI non configuré pour l'organisation {$organization->slug}"
            );
        }

        $sessionToken = $this->getSessionToken($organization);

        // Résoudre le slug vers l'ID GLPI
        $categorySlug = $ticketData['category_slug'] ?? 'autre';
        $category = GlpiCategoryMap::where('organization_id', $organization->id)
            ->where('slug', $categorySlug)
            ->first();

        $glpiCategoryId = $category?->glpi_category_id ?? 0;
        $glpiEntityId   = $category?->glpi_entity_id   ?? 0;

        Log::debug('[GLPI] Résolution catégorie', [
            'organization_id'  => $organization->id,
            'category_slug'    => $categorySlug,
            'category_found'   => $category !== null,
            'glpi_category_id' => $glpiCategoryId,
            'glpi_entity_id'   => $glpiEntityId,
        ]);

        $input = [
            'name'              => $ticketData['title'],
            'content'           => $this->formatContent($ticketData),
            'itilcategories_id' => $glpiCategoryId,
            'priority'          => $ticketData['priority'] ?? 3,
            'type'              => config('supportia.glpi_ticket_type', 1),
            'entities_id'       => $glpiEntityId,
            'urgency'           => $ticketData['priority'] ?? 3,
            'impact'            => min($ticketData['priority'] ?? 3, 4),
        ];

        if (! empty($ticketData['commercial_glpi_user_id'])) {
            $input['_users_id_requester'] = (int) $ticketData['commercial_glpi_user_id'];
        }

        // Identification du demandeur par email (fallback si glpi_user_id indisponible,
        // ou complément pour garantir les notifications même sans compte GLPI)
        if (! empty($ticketData['commercial_email'])) {
            $input['_users_id_requester_notif'] = [
                'use_notification'  => 1,
                'alternative_email' => $ticketData['commercial_email'],
            ];
        }

        $payload = ['input' => $input];

        Log::debug('[GLPI] Envoi ticket', [
            'organization_id'   => $organization->id,
            'category_slug'     => $ticketData['category_slug'] ?? null,
            'itilcategories_id' => $glpiCategoryId,
            'priority'          => $ticketData['priority'] ?? 3,
        ]);

        $response = $this->http()->withHeaders($this->headers($organization, $sessionToken))
            ->post($this->url($organization, '/Ticket'), $payload);

        // Si 401 → session expirée, on réessaie une fois
        if ($response->status() === 401) {
            $this->clearSessionToken($organization);
            $sessionToken = $this->getSessionToken($organization);

            $response = $this->http()->withHeaders($this->headers($organization, $sessionToken))
                ->post($this->url($organization, '/Ticket'), $payload);
        }

        // Un code d'erreur HTTP n'est jamais un succès, même si le corps contient un « id »
        if (! $response->successful()) {
            throw new \RuntimeException(
                'GLPI a refusé la création (HTTP ' . $response->status() . ') : ' . mb_substr($response->body(), 0, 200)
            );
        }

        $data = $this->parseFirstJson($response->body());

        if (empty($data)) {
            throw new \RuntimeException(
                'GLPI retourne une réponse invalide (HTTP ' . $response->status() . ')'
            );
        }

        $ticketId = $data['id']
            ?? throw new \RuntimeException(
                'GLPI ne retourne pas d\'ID ticket (HTTP ' . $response->status() . ')'
            );

        return [
            'id'  => (int) $ticketId,
            'url' => $this->ticketUrl($organization, $ticketId),
        ];
    }

    /**
     * Statut et catégorie bruts d'un ticket GLPI (synchronisation périodique).
     * Retourne null si GLPI est indisponible.
     *
     * @return array{status: int, category_id: int}|null
     */
    public function getTicketCore(Organization $organization, int $glpiTicketId): ?array
    {
        if (self::dryRun()) {
            return null; // mode test : statuts inchangés
        }

        try {
            $data = $this->decodeJson($this->get($organization, "/Ticket/{$glpiTicketId}", [], 30)->body());
        } catch (\Throwable $e) {
            Log::warning('[GLPI] getTicketCore failed', ['glpi_ticket_id' => $glpiTicketId, 'error' => $e->getMessage()]);

            return null;
        }

        if (! isset($data['id'], $data['status'])) {
            return null;
        }

        return [
            'status'      => (int) $data['status'],
            'category_id' => (int) ($data['itilcategories_id'] ?? 0),
        ];
    }

    /**
     * Récupère le statut temps réel d'un ticket GLPI : statut, technicien, followups.
     *
     * Retourne null si GLPI est indisponible (sans lever d'exception).
     * Les résultats sont mis en cache 2 min ; les échecs ne sont pas cachés
     * pour permettre un retry immédiat dès que GLPI revient.
     *
     * @return array{status: int, status_label: string, assigned_to: string|null, resolution_date: string|null, followups: list<array{date: string|null, author: string|null, content: string}>}|null
     */
    public function getTicketStatus(Organization $organization, int $glpiTicketId): ?array
    {
        if (self::dryRun() && $glpiTicketId >= 900000) {
            $solvedAt = Cache::get("glpi_dryrun_solved_{$glpiTicketId}");

            return [
                'status'          => $solvedAt ? 5 : 2,
                'status_label'    => $solvedAt ? 'Résolu' : 'En cours',
                'assigned_to'     => 'Technicien (simulation)',
                'resolution_date' => $solvedAt,
                'followups'       => [],
            ];
        }

        if (! $organization->hasGlpiConfig()) {
            return null;
        }

        $cacheKey = "glpi_ticket_status_{$organization->id}_{$glpiTicketId}";

        $cached = Cache::get($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        try {
            Log::info('[GLPI Status] Starting', ['glpi_ticket_id' => $glpiTicketId]);

            $sessionToken = $this->getSessionToken($organization);

            Log::info('[GLPI Status] Session', ['token' => $sessionToken ? 'ok' : 'null']);

            // GET /Ticket/{id}?expand_dropdowns=true → statut + technicien en clair
            $ticketResp = $this->http()
                ->withHeaders($this->headers($organization, $sessionToken))
                ->get($this->url($organization, "/Ticket/{$glpiTicketId}"), [
                    'expand_dropdowns' => true,
                ]);

            if ($ticketResp->status() === 401) {
                $this->clearSessionToken($organization);
                $sessionToken = $this->getSessionToken($organization);
                $ticketResp   = $this->http()
                    ->withHeaders($this->headers($organization, $sessionToken))
                    ->get($this->url($organization, "/Ticket/{$glpiTicketId}"), [
                        'expand_dropdowns' => true,
                    ]);
            }

            // Le body GLPI peut contenir des blocs JSON concaténés après les données du ticket.
            // On extrait le premier objet JSON complet en suivant la profondeur des accolades.
            $body = $ticketResp->body();
            $firstBrace = strpos($body, '{');
            if ($firstBrace === false) {
                Log::warning('[GLPI Status] No JSON object in body', ['status' => $ticketResp->status()]);
                return null;
            }
            $depth = 0;
            $end   = $firstBrace;
            for ($i = $firstBrace, $len = strlen($body); $i < $len; $i++) {
                if ($body[$i] === '{') {
                    $depth++;
                } elseif ($body[$i] === '}') {
                    $depth--;
                    if ($depth === 0) {
                        $end = $i;
                        break;
                    }
                }
            }
            $ticketData = json_decode(substr($body, $firstBrace, $end - $firstBrace + 1), true);

            if (! isset($ticketData['id'])) {
                Log::warning('[GLPI Status] Invalid ticket response', ['status' => $ticketResp->status()]);
                return null;
            }

            $statusInt = (int) ($ticketData['status'] ?? 0);

            $statusLabel = match($statusInt) {
                1 => 'Nouveau',
                2 => 'En cours (assigné)',
                3 => 'En cours (planifié)',
                4 => 'En attente',
                5 => 'Résolu',
                6 => 'Fermé',
                default => 'Inconnu',
            };

            // Date de résolution / fermeture
            $resolutionDate = null;
            foreach (['solvedate', 'closedate'] as $field) {
                $v = $ticketData[$field] ?? null;
                if ($v && $v !== '0000-00-00 00:00:00') {
                    $resolutionDate = $v;
                    break;
                }
            }

            // Technicien assigné : GET /Ticket/{id}/User?searchText[type]=2
            $assignedTo = null;
            $userResp = $this->http()
                ->withHeaders($this->headers($organization, $sessionToken))
                ->get($this->url($organization, "/Ticket/{$glpiTicketId}/User"), [
                    'searchText[type]'  => 2,
                    'expand_dropdowns'  => true,
                    'range'             => '0-0',
                ]);

            if ($userResp->ok()) {
                $users = $userResp->json();
                if (is_array($users) && ! empty($users)) {
                    $first = reset($users);
                    $name  = $first['users_id'] ?? $first['name'] ?? null;
                    if (is_string($name) && ! is_numeric($name) && ! empty($name)) {
                        $assignedTo = $name;
                    }
                }
            }

            // Suivis : sous-ressource du ticket (fiable sur GLPI 10, cf. glpi:probe du 02/10/2026).
            // expand_dropdowns → users_id contient directement le nom de l'auteur.
            // Les suivis privés (notes internes des techniciens) ne sont jamais renvoyés.
            $fuResp = $this->http()
                ->withHeaders($this->headers($organization, $sessionToken))
                ->get($this->url($organization, "/Ticket/{$glpiTicketId}/ITILFollowup"), [
                    'expand_dropdowns' => 'true',
                    'range'            => '0-99',
                ]);

            $followups = [];
            foreach ($this->decodeJson($fuResp->body()) ?? [] as $fu) {
                if (! is_array($fu) || ! empty($fu['is_private'])) {
                    continue;
                }

                // GLPI encode parfois les balises en entités HTML (&#60;p&#62;…)
                // Ordre obligatoire : 1) décoder les entités, 2) blocs → \n, 3) strip_tags
                $decoded = html_entity_decode((string) ($fu['content'] ?? ''), ENT_QUOTES, 'UTF-8');
                $decoded = preg_replace('/<\s*(br|p|div|li)[^>]*>/i', "\n", $decoded);
                $content = trim(preg_replace('/\n{3,}/', "\n\n", strip_tags($decoded)));

                if ($content === '') {
                    continue;
                }

                $author = $fu['users_id'] ?? null;

                $followups[] = [
                    'date'    => $fu['date'] ?? $fu['date_creation'] ?? null,
                    'author'  => is_string($author) && ! is_numeric($author) ? $author : null,
                    'content' => $content,
                ];
            }

            usort($followups, fn ($a, $b) => strcmp((string) $a['date'], (string) $b['date']));

            $result = [
                'status'          => $statusInt,
                'status_label'    => $statusLabel,
                'assigned_to'     => $assignedTo,
                'resolution_date' => $resolutionDate,
                'followups'       => $followups,
            ];

            Cache::put($cacheKey, $result, 120);

            return $result;
        } catch (\Throwable $e) {
            Log::warning('[GLPI] getTicketStatus failed', [
                'glpi_ticket_id' => $glpiTicketId,
                'error'          => $e->getMessage(),
            ]);

            return null; // pas mis en cache → retry immédiat au prochain chargement
        }
    }


    // ─── Followup ──────────────────────────────────────────

    /**
     * Crée un followup sur un ticket GLPI (POST /ITILFollowup).
     *
     * Utilisé pour transmettre les réponses commerciales (TicketComment)
     * directement dans GLPI afin que le technicien les voie.
     *
     * Retourne true si le followup a été créé, false si GLPI est indisponible.
     * Jamais d'exception : échec silencieux (le commentaire Zeno est déjà sauvegardé).
     */
    /**
     * Marque le ticket « Résolu » dans GLPI en ajoutant une solution (POST /ITILSolution) :
     * GLPI passe alors le ticket au statut 5 lui-même, comme si un technicien l'avait résolu.
     * À vérifier sur l'instance réelle : droit « Résoudre » du compte API.
     */
    public function solveTicket(Organization $organization, int $glpiTicketId, string $content): bool
    {
        if (self::dryRun()) {
            Log::info('[GLPI simulation] Résolution non envoyée', ['glpi_ticket_id' => $glpiTicketId]);
            Cache::put("glpi_dryrun_solved_{$glpiTicketId}", now()->toDateTimeString(), now()->addDays(7));

            return true;
        }

        if (! $organization->hasGlpiConfig()) {
            return false;
        }

        $payload = ['input' => [
            'itemtype' => 'Ticket',
            'items_id' => $glpiTicketId,
            'content'  => nl2br(e($content)),
        ]];

        try {
            $sessionToken = $this->getSessionToken($organization);
            $response = $this->http()
                ->withHeaders($this->headers($organization, $sessionToken))
                ->post($this->url($organization, '/ITILSolution'), $payload);

            if ($response->status() === 401) {
                $this->clearSessionToken($organization);
                $sessionToken = $this->getSessionToken($organization);
                $response = $this->http()
                    ->withHeaders($this->headers($organization, $sessionToken))
                    ->post($this->url($organization, '/ITILSolution'), $payload);
            }

            Log::info('[GLPI] solveTicket', ['glpi_ticket_id' => $glpiTicketId, 'status' => $response->status()]);
            Cache::forget("glpi_ticket_status_{$organization->id}_{$glpiTicketId}");

            return $response->successful();
        } catch (\Throwable $e) {
            Log::warning('[GLPI] solveTicket failed', ['glpi_ticket_id' => $glpiTicketId, 'error' => $e->getMessage()]);

            return false;
        }
    }

    public function addFollowup(Organization $organization, int $glpiTicketId, string $content): bool
    {
        if (self::dryRun()) {
            Log::info('[GLPI simulation] Suivi non envoyé', ['glpi_ticket_id' => $glpiTicketId, 'content' => $content]);

            return true;
        }

        if (! $organization->hasGlpiConfig()) {
            return false;
        }

        try {
            $sessionToken = $this->getSessionToken($organization);

            $payload = [
                'input' => [
                    'items_id' => $glpiTicketId,
                    'itemtype' => 'Ticket',
                    'content'  => nl2br(e($content)),
                ],
            ];

            $response = $this->http()
                ->withHeaders($this->headers($organization, $sessionToken))
                ->post($this->url($organization, '/ITILFollowup'), $payload);

            if ($response->status() === 401) {
                $this->clearSessionToken($organization);
                $sessionToken = $this->getSessionToken($organization);
                $response     = $this->http()
                    ->withHeaders($this->headers($organization, $sessionToken))
                    ->post($this->url($organization, '/ITILFollowup'), $payload);
            }

            Log::debug('[GLPI] addFollowup', [
                'glpi_ticket_id' => $glpiTicketId,
                'status'         => $response->status(),
            ]);

            return $response->successful();
        } catch (\Throwable $e) {
            Log::warning('[GLPI] addFollowup failed', [
                'glpi_ticket_id' => $glpiTicketId,
                'error'          => $e->getMessage(),
            ]);

            return false;
        }
    }


    // ─── Lecture générique ─────────────────────────────────

    /**
     * GET générique sur l'API GLPI (session gérée, 1 retry sur 401).
     * Ne lève pas d'exception sur un code HTTP d'erreur : à l'appelant de vérifier.
     */
    public function get(
        Organization $organization,
        string $path,
        array $query = [],
        ?int $timeout = null,
    ): \Illuminate\Http\Client\Response {
        if (! $organization->hasGlpiConfig()) {
            throw new \RuntimeException("GLPI non configuré pour l'organisation {$organization->slug}");
        }

        $sessionToken = $this->getSessionToken($organization);

        $response = $this->http($timeout)
            ->withHeaders($this->headers($organization, $sessionToken))
            ->get($this->url($organization, $path), $query);

        if ($response->status() === 401) {
            $this->clearSessionToken($organization);
            $sessionToken = $this->getSessionToken($organization);

            $response = $this->http($timeout)
                ->withHeaders($this->headers($organization, $sessionToken))
                ->get($this->url($organization, $path), $query);
        }

        return $response;
    }

    // ─── Session management ────────────────────────────────

    /**
     * Obtient (ou crée) un session token GLPI, mis en cache 30 min.
     */
    private function getSessionToken(Organization $organization): string
    {
        $cacheKey = "glpi_session_{$organization->id}";

        return Cache::remember($cacheKey, 1800, function () use ($organization) {
            $response = $this->http()->withHeaders([
                'App-Token'     => $organization->glpi_app_token,
                'Authorization' => 'user_token ' . $organization->glpi_user_token,
                'Content-Type'  => 'application/json',
            ])->get($this->url($organization, '/initSession'));

            $data = $this->parseFirstJson($response->body());

            return $data['session_token']
                ?? throw new \RuntimeException(
                    "GLPI initSession a échoué pour {$organization->slug} : " . $response->body()
                );
        });
    }

    private function clearSessionToken(Organization $organization): void
    {
        Cache::forget("glpi_session_{$organization->id}");
    }

    // ─── Helpers ────────────────────────────────────────────


    private function headers(Organization $organization, string $sessionToken): array
    {
        return [
            'App-Token'     => $organization->glpi_app_token,
            'Session-Token' => $sessionToken,
            'Content-Type'  => 'application/json',
        ];
    }

    private function url(Organization $organization, string $path): string
    {
        return rtrim($organization->glpi_api_url, '/') . $path;
    }

    /**
     * Client HTTP préconfiguré (SSL optionnel via env GLPI_VERIFY_SSL=false).
     */
    private function http(?int $timeout = null): \Illuminate\Http\Client\PendingRequest
    {
        $client = Http::timeout($timeout ?? config('supportia.glpi_timeout', 15));

        if (! config('supportia.glpi_verify_ssl', true)) {
            $client = $client->withoutVerifying();
        }

        return $client;
    }

    /**
     * Premier objet/tableau JSON d'une réponse GLPI (voir decodeJson), ou [] si illisible.
     */
    private function parseFirstJson(string $body): array
    {
        return $this->decodeJson($body) ?? [];
    }

    /**
     * Décode le corps d'une réponse GLPI, y compris quand GLPI concatène un second
     * bloc JSON après la réponse valide (ex. `[{...}]["ERROR_METHOD_NOT_ALLOWED","..."]`,
     * constaté sur glpi.web-eci.com pour toutes les listes, même en HTTP 200/206).
     * Retourne la première valeur JSON (objet ou tableau) équilibrée, ou null.
     */
    public function decodeJson(string $body): ?array
    {
        $decoded = json_decode($body, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        $len   = strlen($body);
        $start = strcspn($body, '{[');
        if ($start >= $len) {
            return null;
        }

        $depth    = 0;
        $inString = false;
        $escaped  = false;

        for ($i = $start; $i < $len; $i++) {
            $char = $body[$i];

            if ($inString) {
                if ($escaped) {
                    $escaped = false;
                } elseif ($char === '\\') {
                    $escaped = true;
                } elseif ($char === '"') {
                    $inString = false;
                }
                continue;
            }

            if ($char === '"') {
                $inString = true;
            } elseif ($char === '{' || $char === '[') {
                $depth++;
            } elseif ($char === '}' || $char === ']') {
                $depth--;
                if ($depth === 0) {
                    $decoded = json_decode(substr($body, $start, $i - $start + 1), true);

                    return is_array($decoded) ? $decoded : null;
                }
            }
        }

        return null;
    }

    /**
     * Lot d'éléments d'une liste GLPI (/Ticket, /ITILFollowup…), 3 tentatives, puis
     * scission de la plage en deux (GLPI renvoie des 500 intermittents sur les gros lots).
     *
     * @return array{0: list<array>, 1: int} [éléments, total annoncé par Content-Range]
     */
    public function listRange(Organization $organization, string $itemtype, int $offset, int $size, ?int $total = null): array
    {
        $end = $total !== null ? min($offset + $size - 1, $total - 1) : $offset + $size - 1;
        if ($end < $offset) {
            return [[], $total ?? 0];
        }

        $error = null;
        for ($attempt = 1; $attempt <= 3; $attempt++) {
            try {
                $response = $this->get($organization, "/{$itemtype}", ['range' => "{$offset}-{$end}"], 120);
                $data     = $this->decodeJson($response->body());

                if (is_array($data) && array_is_list($data) && ($data === [] || is_array($data[0])) && $response->status() < 500) {
                    preg_match('#/(\d+)$#', (string) $response->header('Content-Range'), $m);

                    return [$data, isset($m[1]) ? (int) $m[1] : ($total ?? count($data))];
                }
                if (is_array($data) && ($data[0] ?? null) === 'ERROR_RANGE_EXCEED_TOTAL') {
                    return [[], $total ?? 0];
                }
                $error = 'HTTP ' . $response->status();
            } catch (\Throwable $e) {
                $error = $e->getMessage();
            }
            usleep(500000 * $attempt);
        }

        if ($end > $offset) {
            $half = intdiv($end - $offset + 1, 2);
            [$left, $total]  = $this->listRange($organization, $itemtype, $offset, $half, $total);
            [$right, $total] = $this->listRange($organization, $itemtype, $offset + $half, $end - $offset + 1 - $half, $total);

            return [array_merge($left, $right), $total];
        }

        throw new \RuntimeException("{$itemtype} offset {$offset} illisible : {$error}");
    }

    /** Mode simulation actif (jamais en production). */
    public static function dryRun(): bool
    {
        return (bool) config('supportia.glpi_dry_run') && ! app()->environment('production');
    }

    public function ticketUrlFor(Organization $organization, int $ticketId): string
    {
        return $this->ticketUrl($organization, $ticketId);
    }

    /**
     * Envoie un fichier dans GLPI et le rattache au ticket (POST /Document, multipart).
     * Format « uploadManifest » de l'API REST GLPI ; le rattachement via itemtype/items_id
     * crée le Document_Item. À valider sur l'instance réelle (première PJ envoyée).
     *
     * @return int ID du document GLPI
     */
    public function uploadDocument(Organization $organization, int $glpiTicketId, string $path, string $originalName): int
    {
        if (self::dryRun()) {
            Log::info('[GLPI simulation] Pièce jointe non envoyée', ['glpi_ticket_id' => $glpiTicketId, 'file' => $originalName]);

            return 0;
        }

        if (! is_file($path)) {
            throw new \RuntimeException("Fichier introuvable : {$originalName}");
        }

        $sessionToken = $this->getSessionToken($organization);

        $manifest = json_encode(['input' => [
            'name'      => $originalName,
            '_filename' => [$originalName],
            'itemtype'  => 'Ticket',
            'items_id'  => $glpiTicketId,
        ]], JSON_UNESCAPED_UNICODE);

        $response = $this->http(60)
            ->withHeaders([
                'App-Token'     => $organization->glpi_app_token,
                'Session-Token' => $sessionToken,
            ])
            ->attach('uploadManifest', $manifest)
            ->attach('filename[0]', file_get_contents($path), $originalName)
            ->post($this->url($organization, '/Document'));

        $data = $this->decodeJson($response->body()) ?? [];

        if (! $response->successful() || empty($data['id'])) {
            throw new \RuntimeException('Envoi du document refusé par GLPI (HTTP ' . $response->status() . ') : ' . mb_substr($response->body(), 0, 200));
        }

        return (int) $data['id'];
    }

    private function ticketUrl(Organization $organization, int $ticketId): string
    {
        // Déduire l'URL front de GLPI depuis l'URL API
        $baseUrl = str_replace('/apirest.php', '', $organization->glpi_api_url);

        return $baseUrl . '/front/ticket.form.php?id=' . $ticketId;
    }

    /**
     * Convertit le markdown minimal produit par le moteur de classification (quel qu'il soit) en HTML compatible GLPI.
     * GLPI attend du HTML ; le markdown brut s'affiche tel quel sans conversion.
     */
    private function markdownToGlpiHtml(string $text): string
    {
        $lines = explode("\n", $text);
        $html  = [];

        foreach ($lines as $line) {
            // ## Titre  et  ### Titre → <br><b>Titre</b><br>
            if (preg_match('/^#{2,}\s+(.+)$/', $line, $m)) {
                $html[] = '<br><b>' . e($m[1]) . '</b><br>';
                continue;
            }

            // Échappement AVANT la mise en forme : aucun HTML saisi ne passe tel quel dans GLPI
            $line = e($line);

            // - item  →  • item
            if (preg_match('/^- (.+)$/', $line, $m)) {
                $line = '• ' . $m[1];
            }

            // **texte** → <b>texte</b>
            $line = preg_replace('/\*\*(.+?)\*\*/', '<b>$1</b>', $line);

            $html[] = $line;
        }

        // Sauts de ligne → <br>
        return implode('<br>', $html);
    }

    /**
     * Formate le contenu du ticket pour GLPI (HTML léger).
     */
    private function formatContent(array $ticketData): string
    {
        $body    = $ticketData['body'] ?? $ticketData['title'] ?? '';
        $content = $this->markdownToGlpiHtml($body);

        $meta = [];
        $meta[] = '<hr>';
        $meta[] = '<b>Créé via Zeno</b>';

        $attachmentCount = (int) ($ticketData['attachment_count'] ?? 0);
        if ($attachmentCount > 0) {
            $s     = $attachmentCount > 1;
            $meta[] = "📎 {$attachmentCount} pièce" . ($s ? 's' : '') . " jointe" . ($s ? 's' : '')
                    . " disponible" . ($s ? 's' : '') . " dans Zeno";
        }

        if (! empty($ticketData['commercial_name'])) {
            $meta[] = 'Commercial : ' . e($ticketData['commercial_name']);
        }
        if (! empty($ticketData['client_name'])) {
            $meta[] = 'Client : ' . e($ticketData['client_name']);
        }

        $confidence = $ticketData['confidence'] ?? null;
        if ($confidence !== null) {
            $meta[] = 'Confiance IA : ' . round($confidence * 100) . '%';
        }

        $meta[] = 'Classification : ' . ($ticketData['provider'] ?? 'claude');

        return $content . "\n\n" . implode("<br>\n", $meta);
    }
}
