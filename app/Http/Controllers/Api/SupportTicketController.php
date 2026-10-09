<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Enums\TicketStatus;
use App\Models\SupportTicket;
use App\Models\TicketAttachment;
use App\Services\AIClassifierService;
use App\Services\GlpiTicketPublisher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class SupportTicketController extends Controller
{
    public function __construct(
        private AIClassifierService $classifier,
        private GlpiTicketPublisher $publisher,
    ) {}

    /**
     * POST /support/tickets
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
            'attachments'   => 'nullable|array|max:5',
            'attachments.*' => ['nullable', ...TicketAttachment::rules()],
        ], TicketAttachment::messages('attachments.*'));

        $user = $request->user();
        $organization = $user->organization;

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

        // 3. Classification IA (hors transaction — appel HTTP)
        $classification = $this->classifier->classify(
            $organization,
            $validated['description'],
            $clientName,
            teamId: $user->team_id,
        );

        // 4. Créer le ticket et ses pièces jointes en une opération atomique
        $uploadedFiles = $request->hasFile('attachments')
            ? array_filter($request->file('attachments'), fn ($f) => $f && $f->isValid())
            : [];

        $threshold   = config('supportia.confidence_threshold', 0.7);
        $autoPublish = $classification['confidence'] >= $threshold;

        $ticket = DB::transaction(function () use ($organization, $user, $validated, $clientIds, $clientName, $classification, $uploadedFiles, $autoPublish) {
            $ticket = SupportTicket::create([
                'organization_id'  => $organization->id,
                'user_id'          => $user->id,
                'team_id'          => $user->team_id,
                'client_ids'       => $clientIds,
                'client_name'      => $clientName,
                'raw_description'  => $validated['description'],
                'ai_title'         => $classification['title'],
                'ai_body'          => $classification['body'],
                'ai_category_slug' => $classification['category_slug'],
                'ai_priority'      => $classification['priority'],
                'ai_confidence'    => $classification['confidence'],
                'ai_provider'      => $classification['provider'],
                // Confiance suffisante : envoi immédiat. Sinon : validation obligatoire du commercial.
                'status'           => ($autoPublish ? TicketStatus::Queued : TicketStatus::NeedsReview)->value,
            ]);

            foreach ($uploadedFiles as $file) {
                $ext      = strtolower($file->getClientOriginalExtension());
                $filename = Str::uuid()->toString() . ($ext ? ".{$ext}" : '');
                $path     = $file->storeAs("attachments/{$ticket->id}", $filename, 'local');

                $ticket->attachments()->create([
                    'filename'      => $filename,
                    'original_name' => $file->getClientOriginalName(),
                    'mime_type'     => $file->getMimeType(),
                    'size'          => $file->getSize(),
                    'path'          => $path,
                ]);
            }

            return $ticket;
        });

        $this->classifier->logFor($ticket, $classification);

        // 5. Si confiance suffisante → création directe dans GLPI
        if ($autoPublish) {
            return $this->publishToGlpi($ticket);
        }

        // 6. Confiance trop basse → retourner la suggestion pour validation
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
            'categories' => $organization->activeCategories()->forTeam($user->team_id)
                ->select('slug', 'label', 'label_simple', 'is_visible_to_users')
                ->get(),
        ]);
    }

    /**
     * POST /support/tickets/{ticket}/confirm
     *
     * Validation manuelle d'un ticket en needs_review
     * (le commercial peut modifier titre, catégorie, priorité, corps).
     */
    public function confirm(Request $request, SupportTicket $ticket): JsonResponse
    {
        $user = $request->user();

        $this->authorize('confirm', $ticket);

        if ($ticket->glpi_ticket_id || $ticket->status !== TicketStatus::NeedsReview->value) {
            return response()->json(['error' => 'Ce ticket a déjà été envoyé au support.'], 409);
        }

        $validated = $request->validate([
            'title'         => 'nullable|string|max:500',
            'body'          => 'nullable|string|max:10000',
            'category_slug' => [
                'nullable', 'string', 'max:100',
                \Illuminate\Validation\Rule::in(
                    $ticket->organization->activeCategories()->forTeam($ticket->team_id)->pluck('slug')->push('autre')->all()
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
            if (isset($validated[$input]) && (string) $validated[$input] !== (string) $ticket->$field) {
                $ticket->$field = $validated[$input];
                $hasChanges = true;
            }
        }

        if ($hasChanges) {
            $ticket->was_modified_by_user = true;
        }

        $ticket->status = TicketStatus::Queued->value;
        $ticket->save();

        return $this->publishToGlpi($ticket);
    }

    /**
     * DELETE /support/tickets/{ticket}/draft
     *
     * Le commercial annule l'écran de validation : le brouillon (jamais envoyé) est supprimé,
     * pièces jointes comprises.
     */
    public function cancelDraft(Request $request, SupportTicket $ticket): JsonResponse
    {
        $this->authorize('confirm', $ticket);

        if ($ticket->status !== TicketStatus::NeedsReview->value || $ticket->glpi_ticket_id) {
            return response()->json(['error' => 'Ce ticket a déjà été envoyé au support.'], 409);
        }

        $ticket->delete();

        return response()->json(['status' => 'deleted']);
    }

    /**
     * GET /support/tickets/open-for-client?client_id=4521
     *
     * Tickets encore ouverts pour ce client (30 derniers jours), pour éviter les doublons.
     * Les tickets d'un collègue sont signalés sans leur contenu si l'utilisateur
     * n'a pas le droit de les voir.
     */
    public function openForClient(Request $request): JsonResponse
    {
        $clientId = trim((string) $request->query('client_id'));
        $user     = $request->user();

        if ($clientId === '' || mb_strlen($clientId) > 50) {
            return response()->json(['tickets' => []]);
        }

        $tickets = SupportTicket::where('organization_id', $user->organization_id)
            ->whereIn('status', [TicketStatus::Queued->value, TicketStatus::Created->value])
            ->where('created_at', '>=', now()->subDays(30))
            ->whereNotNull('client_ids')
            ->orderByDesc('created_at')
            ->limit(200)
            ->get()
            ->filter(fn (SupportTicket $t) => collect($t->client_ids)->contains(fn ($c) => strcasecmp(trim($c['id'] ?? ''), $clientId) === 0))
            ->take(5)
            ->map(fn (SupportTicket $t) => $t->isVisibleTo($user)
                ? ['visible' => true, 'id' => $t->id, 'title' => $t->ai_title, 'age' => $t->created_at->locale('fr')->diffForHumans(), 'url' => route('support.ticket-detail', $t->id)]
                : ['visible' => false, 'age' => $t->created_at->locale('fr')->diffForHumans()])
            ->values();

        return response()->json(['tickets' => $tickets]);
    }

    /**
     * GET /support/tickets
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

    // ─── Private ─────────────────────────────────────────

    /**
     * Tente la création immédiate dans GLPI. En cas d'échec, le ticket part en file
     * d'attente (job CreateGlpiTicket, nouvelles tentatives automatiques).
     */
    private function publishToGlpi(SupportTicket $ticket): JsonResponse
    {
        $estimate = $ticket->resolutionEstimate();

        $summary = [
            'estimate_hours' => $estimate['hours'] ?? null,
            'estimate_count' => $estimate['count'] ?? 0,
            'ticket_id'     => $ticket->id,
            'title'         => $ticket->ai_title,
            'category_slug' => $ticket->ai_category_slug,
            'priority'      => $ticket->ai_priority,
            'confidence'    => $ticket->ai_confidence,
        ];

        try {
            $result = $this->publisher->publish($ticket);

            return response()->json($summary + [
                'status'         => 'created',
                'glpi_ticket_id' => $result['id'],
                'glpi_url'       => $result['url'],
            ]);
        } catch (\Throwable $e) {
            Log::error('GLPI ticket creation failed', ['ticket_id' => $ticket->id, 'error' => $e->getMessage()]);

            $this->publisher->queue($ticket, $e);

            return response()->json($summary + [
                'status'  => 'queued',
                'message' => 'Ticket enregistré. L\'envoi au support sera relancé automatiquement.',
            ], 202);
        }
    }
}
