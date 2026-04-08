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
        $category = GlpiCategoryMap::where('organization_id', $organization->id)
            ->where('slug', $ticketData['category_slug'] ?? 'autre')
            ->first();

        $glpiCategoryId = $category?->glpi_category_id ?? 0;
        $glpiEntityId = $category?->glpi_entity_id ?? 0;

        $payload = [
            'input' => [
                'name'              => $ticketData['title'],
                'content'           => $this->formatContent($ticketData),
                'itilcategories_id' => $glpiCategoryId,
                'priority'          => $ticketData['priority'] ?? 3,
                'type'              => config('supportia.glpi_ticket_type', 1),
                'entities_id'       => $glpiEntityId,
                'urgency'           => $ticketData['priority'] ?? 3,
                'impact'            => min($ticketData['priority'] ?? 3, 4),
            ],
        ];

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
            throw new \RuntimeException('GLPI retourne une réponse invalide : ' . $response->body());
        }

        $ticketId = $data['id']
            ?? throw new \RuntimeException(
                'GLPI ne retourne pas d\'ID ticket : ' . json_encode($data)
            );

        return [
            'id'  => (int) $ticketId,
            'url' => $this->ticketUrl($organization, $ticketId),
        ];
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
     * Formate le contenu du ticket pour GLPI (HTML léger).
     */
    private function formatContent(array $ticketData): string
    {
        $content = $ticketData['body'] ?? $ticketData['title'] ?? '';

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
