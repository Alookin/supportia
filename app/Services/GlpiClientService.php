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
        if (! $organization->hasGlpiConfig()) {
            return null;
        }

        $cacheKey = "glpi_ticket_status_{$organization->id}_{$glpiTicketId}";

        $cached = Cache::get($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        try {
            Log::debug('[GLPI Status] Starting', ['glpi_ticket_id' => $glpiTicketId]);

            $sessionToken = $this->getSessionToken($organization);

            Log::debug('[GLPI Status] Session', ['token' => $sessionToken ? 'ok' : 'null']);

            // GET /Ticket/{id}?expand_dropdowns=true → statut + technicien en clair
            $ticketResp = $this->http()
                ->withHeaders($this->headers($organization, $sessionToken))
                ->get($this->url($organization, "/Ticket/{$glpiTicketId}"), [
                    'expand_dropdowns' => true,
                ]);

            Log::debug('[GLPI Status] Ticket response', [
                'status'    => $ticketResp->status(),
                'has_id'    => isset($ticketResp->json()['id']),
                'body_size' => strlen($ticketResp->body()),
            ]);

            if ($ticketResp->status() === 401) {
                $this->clearSessionToken($organization);
                $sessionToken = $this->getSessionToken($organization);
                $ticketResp   = $this->http()
                    ->withHeaders($this->headers($organization, $sessionToken))
                    ->get($this->url($organization, "/Ticket/{$glpiTicketId}"), [
                        'expand_dropdowns' => true,
                    ]);

                Log::debug('[GLPI Status] Ticket response (retry after 401)', [
                    'status'  => $ticketResp->status(),
                    'has_id'  => isset($ticketResp->json()['id']),
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

            Log::debug('[GLPI Status] Parsed ticket', [
                'has_id' => isset($ticketData['id']),
                'keys'   => array_keys($ticketData ?? []),
            ]);

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

            // Followups : GET /ITILFollowup?searchText[tickets_id]={id}
            // forcedisplay : 2=id, 4=date, 5=content (les sous-ressources /Ticket/{id}/ITILFollowup retournent 405)
            $fuResp = $this->http()
                ->withHeaders($this->headers($organization, $sessionToken))
                ->get($this->url($organization, '/ITILFollowup'), [
                    'searchText[tickets_id]' => $glpiTicketId,
                    'forcedisplay[0]'        => 2,   // id
                    'forcedisplay[1]'        => 4,   // date
                    'forcedisplay[2]'        => 5,   // content
                    'range'                  => '0-19',
                ]);

            Log::debug('[GLPI Status] Followups response', [
                'status'    => $fuResp->status(),
                'count'     => count(($fuResp->json()['data'] ?? [])),
                'body_size' => strlen($fuResp->body()),
            ]);

            $followups = [];
            if ($fuResp->ok()) {
                $fuData = $fuResp->json();
                $rows   = $fuData['data'] ?? (is_array($fuData) ? $fuData : []);
                foreach ($rows as $fu) {
                    if (! is_array($fu)) {
                        continue;
                    }
                    // forcedisplay retourne les champs par numéro : 4=date, 5=content
                    $content = strip_tags($fu['5'] ?? $fu['content'] ?? '');
                    if (empty(trim($content))) {
                        continue;
                    }
                    $followups[] = [
                        'date'    => $fu['4'] ?? $fu['date'] ?? null,
                        'author'  => null, // non disponible sans lookup supplémentaire
                        'content' => $content,
                    ];
                }
            }

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
            Log::debug('[GLPI Status] Error', ['message' => $e->getMessage()]);
            Log::warning('[GLPI] getTicketStatus failed', [
                'glpi_ticket_id' => $glpiTicketId,
                'error'          => $e->getMessage(),
            ]);

            return null; // pas mis en cache → retry immédiat au prochain chargement
        }
    }

    /**
     * Recherche des tickets ouverts similaires dans GLPI.
     * Utilisé pour le dédoublonnage (phase 2+).
     */
    public function searchSimilarTickets(Organization $organization, string $title): array
    {
        if (! $organization->hasGlpiConfig()) {
            return [];
        }

        try {
            $sessionToken = $this->getSessionToken($organization);
        } catch (\Throwable) {
            return [];
        }

        // Extraire les 3 mots les plus significatifs (> 3 caractères)
        $words = array_filter(
            explode(' ', $title),
            fn($w) => mb_strlen($w) > 3
        );
        $searchTerm = implode(' ', array_slice($words, 0, 3));

        if (empty($searchTerm)) {
            return [];
        }

        $response = $this->http()->withHeaders($this->headers($organization, $sessionToken))
            ->get($this->url($organization, '/search/Ticket'), [
                'criteria[0][field]'      => 1,   // Titre
                'criteria[0][searchtype]' => 'contains',
                'criteria[0][value]'      => $searchTerm,
                'criteria[1][link]'       => 'AND',
                'criteria[1][field]'      => 12,  // Statut
                'criteria[1][searchtype]' => 'notequals',
                'criteria[1][value]'      => 6,   // Pas "Clos"
                'range'                   => '0-4',
                'forcedisplay[0]'         => 1,   // Titre
                'forcedisplay[1]'         => 12,  // Statut
                'forcedisplay[2]'         => 15,  // Date ouverture
            ]);

        if ($response->failed()) {
            return [];
        }

        return $response->json('data') ?? [];
    }

    // ─── Requester assignment ──────────────────────────────

    /**
     * Corrige le demandeur d'un ticket GLPI existant via PUT /Ticket/{id}.
     * Échec silencieux : le ticket est déjà créé, ne pas le faire échouer pour ça.
     */
    private function assignTicketRequester(
        Organization $organization,
        string $sessionToken,
        int $ticketId,
        int $glpiUserId,
    ): void {
        try {
            $response = $this->http()
                ->withHeaders($this->headers($organization, $sessionToken))
                ->put($this->url($organization, "/Ticket/{$ticketId}"), [
                    'input' => [
                        '_users_id_requester' => $glpiUserId,
                    ],
                ]);

            Log::debug('[GLPI] Assignation demandeur', [
                'ticket_id'    => $ticketId,
                'glpi_user_id' => $glpiUserId,
                'status'       => $response->status(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('[GLPI] assignTicketRequester failed', [
                'ticket_id' => $ticketId,
                'error'     => $e->getMessage(),
            ]);
        }
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
    private function http(): \Illuminate\Http\Client\PendingRequest
    {
        $client = Http::timeout(config('supportia.ai_timeout', 10));

        if (! config('supportia.glpi_verify_ssl', true)) {
            $client = $client->withoutVerifying();
        }

        return $client;
    }

    /**
     * Extrait le premier objet JSON d'une réponse GLPI potentiellement malformée.
     * Certaines instances GLPI concatènent plusieurs payloads JSON dans la même réponse.
     */
    private function parseFirstJson(string $body): array
    {
        // Tente un décodage direct d'abord
        $decoded = json_decode($body, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            return $decoded;
        }

        // Extraire le premier objet { ... } valide
        if (preg_match('/(\{[^}]+\})/', $body, $m)) {
            $decoded = json_decode($m[1], true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                return $decoded;
            }
        }

        return [];
    }

    private function ticketUrl(Organization $organization, int $ticketId): string
    {
        // Déduire l'URL front de GLPI depuis l'URL API
        $baseUrl = str_replace('/apirest.php', '', $organization->glpi_api_url);

        return $baseUrl . '/front/ticket.form.php?id=' . $ticketId;
    }

    /**
     * Convertit le markdown minimal généré par Claude en HTML compatible GLPI.
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
        $meta[] = '<b>Créé via SupportIA</b>';

        if (! empty($ticketData['has_screenshot'])) {
            $meta[] = '📎 Capture d\'écran disponible dans SupportIA';
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
