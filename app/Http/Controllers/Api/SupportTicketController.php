<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SupportTicket;
use App\Services\AIClassifierService;
use App\Services\GlpiClientService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class SupportTicketController extends Controller
{
    public function __construct(
        private AIClassifierService $classifier,
        private GlpiClientService $glpiClient,
    ) {}

    /**
     * POST /api/tickets
     *
     * Flux principal : description → IA → GLPI
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'description'        => 'required|string|min:20|max:5000',
            'is_specific_client' => 'nullable|in:0,1',
            'clients'            => ['nullable', 'string', function ($attr, $value, $fail) use ($request) {
                if ($request->input('is_specific_client') == '1') {
                    $arr = json_decode($value, true);
                    if (! is_array($arr) || ! collect($arr)->contains(fn ($c) => ! empty(trim($c['id'] ?? '')))) {
                        $fail('Au moins un ID client est requis.');
                    }
                }
            }],
            'screenshot'         => 'nullable|image|max:5120', // 5 MB
        ]);

        $user = $request->user();
        $organization = $user->organization;

        if (! $organization?->is_active) {
            return response()->json([
                'error' => 'Aucune organisation active associée à votre compte.',
            ], 403);
        }

        // 1. Parser les clients
        $isSpecificClient = ($request->input('is_specific_client') == '1');
        $clientIds = null;
        $clientName = null;

        if ($isSpecificClient) {
            $raw = json_decode($request->input('clients', '[]'), true) ?: [];
            $clientIds = collect($raw)
                ->filter(fn ($c) => ! empty(trim($c['id'] ?? '')))
                ->map(fn ($c) => ['id' => trim($c['id']), 'name' => trim($c['name'] ?? '')])
                ->values()->all();
            $clientName = collect($clientIds)
                ->map(fn ($c) => $c['id'] . ($c['name'] ? ' ' . $c['name'] : ''))
                ->implode(', ');
        }

        // 2. Vérifier la qualité de la description avant toute persistance
        if (! $this->classifier->isDescriptionSuffisante($validated['description'])) {
            return response()->json([
                'error' => 'Veuillez décrire le problème plus précisément pour que nous puissions vous aider efficacement.',
                'type'  => 'description_insuffisante',
            ], 422);
        }

        // 3. Stocker la capture d'écran si fournie
        $screenshotPath = null;
        if ($request->hasFile('screenshot')) {
            $allowedMimes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
            $detectedMime = $request->file('screenshot')->getMimeType();
            if (! in_array($detectedMime, $allowedMimes, true)) {
                return response()->json([
                    'error' => 'Type de fichier non autorisé. Formats acceptés : JPEG, PNG, GIF, WebP.',
                ], 422);
            }
            $screenshotPath = $request->file('screenshot')
                ->store('screenshots', 'public');
        }

        // 4. Créer le ticket local (traçabilité avant tout)
        $ticket = SupportTicket::create([
            'organization_id' => $organization->id,
            'user_id'         => $user->id,
            'client_ids'      => $clientIds,
            'client_name'     => $clientName,
            'raw_description' => $validated['description'],
            'screenshot_path' => $screenshotPath,
            'status'          => 'pending',
        ]);

        // 5. Classification IA
        $classification = $this->classifier->classify(
            $organization,
            $validated['description'],
            $clientName,
            $ticket,
        );

        // 6. Mettre à jour le ticket avec les résultats IA
        $ticket->update([
            'ai_title'         => $classification['title'],
            'ai_body'          => $classification['body'],
            'ai_category_slug' => $classification['category_slug'],
            'ai_priority'      => $classification['priority'],
            'ai_confidence'    => $classification['confidence'],
            'ai_provider'      => $classification['provider'],
        ]);

        // 7. Si confiance suffisante → création directe dans GLPI
        $threshold = config('supportia.confidence_threshold', 0.7);

        if ($classification['confidence'] >= $threshold) {
            return $this->createInGlpi($organization, $ticket, $classification, $user);
        }

        // 8. Confiance trop basse → retourner la suggestion pour validation
        return response()->json([
            'status'     => 'needs_review',
            'ticket_id'  => $ticket->id,
            'suggestion' => [
                'title'         => $classification['title'],
                'body'          => $classification['body'],
                'category_slug' => $classification['category_slug'],
                'priority'      => $classification['priority'],
                'confidence'    => $classification['confidence'],
                'provider'      => $classification['provider'],
            ],
            'categories' => $organization->activeCategories()
                ->select('slug', 'label', 'label_simple', 'is_visible_to_users')
                ->get(),
        ]);
    }

    /**
     * POST /api/tickets/{ticket}/confirm
     *
     * Validation manuelle d'un ticket en needs_review
     * (le commercial peut modifier titre, catégorie, priorité, corps).
     */
    public function confirm(Request $request, SupportTicket $ticket): JsonResponse
    {
        $user = $request->user();

        if ($ticket->organization_id !== $user->organization_id) {
            return response()->json(['error' => 'Non autorisé.'], 403);
        }

        if ($ticket->glpi_ticket_id) {
            return response()->json(['error' => 'Ce ticket a déjà été créé dans GLPI.'], 409);
        }

        $validated = $request->validate([
            'title'         => 'nullable|string|max:500',
            'body'          => 'nullable|string|max:10000',
            'category_slug' => [
                'nullable', 'string', 'max:100',
                \Illuminate\Validation\Rule::in(
                    $ticket->organization->activeCategories()->pluck('slug')->push('autre')->all()
                ),
            ],
            'priority'      => 'nullable|integer|min:1|max:5',
        ]);

        // Appliquer les modifications du commercial
        $fieldMap = [
            'title'         => 'ai_title',
            'body'          => 'ai_body',
            'category_slug' => 'ai_category_slug',
            'priority'      => 'ai_priority',
        ];

        $hasChanges = false;
        foreach ($fieldMap as $input => $field) {
            if (isset($validated[$input])) {
                $ticket->$field = $validated[$input];
                $hasChanges = true;
            }
        }

        if ($hasChanges) {
            $ticket->was_modified_by_user = true;
            $ticket->save();
        }

        $classification = [
            'title'         => $ticket->ai_title,
            'body'          => $ticket->ai_body,
            'category_slug' => $ticket->ai_category_slug,
            'priority'      => $ticket->ai_priority,
            'confidence'    => $ticket->ai_confidence,
            'provider'      => $ticket->ai_provider,
        ];

        return $this->createInGlpi($ticket->organization, $ticket, $classification, $user);
    }

    /**
     * GET /api/tickets
     *
     * Liste les tickets récents du commercial connecté.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $tickets = SupportTicket::where('organization_id', $user->organization_id)
            ->where('user_id', $user->id)
            ->orderByDesc('created_at')
            ->limit(20)
            ->get([
                'id',
                'client_name',
                'ai_title',
                'ai_category_slug',
                'ai_priority',
                'ai_confidence',
                'glpi_ticket_id',
                'status',
                'created_at',
            ]);

        return response()->json(['tickets' => $tickets]);
    }

    // ─── Private ─────────────────────────────────────────────

    /**
     * Tente la création dans GLPI et retourne la réponse appropriée.
     */
    private function createInGlpi(
        $organization,
        SupportTicket $ticket,
        array $classification,
        $user,
    ): JsonResponse {
        try {
            $glpiResult = $this->glpiClient->createTicket($organization, [
                ...$classification,
                'commercial_name'         => $user->name,
                'commercial_glpi_user_id' => $user->glpi_user_id,
                'client_name'             => $ticket->client_name,
                'has_screenshot'   => ! empty($ticket->screenshot_path),
            ]);

            $ticket->markAsCreatedInGlpi($glpiResult['id']);

            $estimate = SupportTicket::estimateResolutionHours(
                $organization->id,
                $classification['category_slug'] ?? ''
            );

            return response()->json([
                'status'          => 'created',
                'ticket_id'       => $ticket->id,
                'glpi_ticket_id'  => $glpiResult['id'],
                'glpi_url'        => $glpiResult['url'],
                'title'           => $classification['title'],
                'category_slug'   => $classification['category_slug'],
                'priority'        => $classification['priority'],
                'confidence'      => $classification['confidence'],
                'estimate_hours'  => $estimate['hours'],
                'estimate_count'  => $estimate['count'],
            ]);
        } catch (\Throwable $e) {
            Log::error('GLPI ticket creation failed', [
                'ticket_id' => $ticket->id,
                'error'     => $e->getMessage(),
            ]);

            $ticket->markAsGlpiFailed($e->getMessage());

            $estimate = SupportTicket::estimateResolutionHours(
                $organization->id,
                $classification['category_slug'] ?? ''
            );

            // Le ticket est sauvé en local, le cron retentera
            return response()->json([
                'status'          => 'queued',
                'ticket_id'       => $ticket->id,
                'message'         => 'Ticket enregistré. La création GLPI sera retentée automatiquement.',
                'title'           => $classification['title'],
                'category_slug'   => $classification['category_slug'],
                'priority'        => $classification['priority'],
                'estimate_hours'  => $estimate['hours'],
                'estimate_count'  => $estimate['count'],
            ], 202);
        }
    }
}
