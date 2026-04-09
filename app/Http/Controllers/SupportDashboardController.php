<?php

namespace App\Http\Controllers;

use App\Models\GlpiCategoryMap;
use App\Models\SupportTicket;
use App\Models\TicketAttachment;
use App\Models\TicketComment;
use App\Services\GlpiClientService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

class SupportDashboardController extends Controller
{
    public function index(Request $request): View
    {
        $user  = $request->user();
        $org   = $user->organization;
        $orgId = $org?->id;

        // ─── Category label map (needed early for chart labels) ───
        $categories = $org
            ? GlpiCategoryMap::where('organization_id', $orgId)->pluck('label_simple', 'slug')
            : collect();

        // ─── Stats cards ──────────────────────────────────────────
        $totalTickets = SupportTicket::where('organization_id', $orgId)->count();

        $todayTickets = SupportTicket::where('organization_id', $orgId)
            ->whereDate('created_at', today())
            ->count();

        $autoClassified = SupportTicket::where('organization_id', $orgId)
            ->where('ai_confidence', '>=', config('supportia.confidence_threshold', 0.7))
            ->count();

        $autoRate = $totalTickets > 0
            ? round($autoClassified / $totalTickets * 100)
            : 0;

        // ─── Top 5 categories (horizontal bar chart) ─────────────
        $topCategories = SupportTicket::where('organization_id', $orgId)
            ->whereNotNull('ai_category_slug')
            ->selectRaw('ai_category_slug, count(*) as total')
            ->groupBy('ai_category_slug')
            ->orderByDesc('total')
            ->limit(5)
            ->get()
            ->map(fn($row) => [
                'label' => $categories->get($row->ai_category_slug, $row->ai_category_slug),
                'count' => (int) $row->total,
            ]);

        $maxCategoryCount = $topCategories->max('count') ?: 1;
        $topCategoryLabel = $topCategories->first()['label'] ?? '—';

        // ─── Tickets par jour — 7 derniers jours (bar chart) ──────
        $sevenDaysAgo = today()->subDays(6)->startOfDay();

        $rawByDay = SupportTicket::where('organization_id', $orgId)
            ->where('created_at', '>=', $sevenDaysAgo)
            ->selectRaw("DATE(created_at) as day, count(*) as total")
            ->groupBy('day')
            ->pluck('total', 'day');

        $ticketsByDay = collect(range(6, 0))->map(function ($daysAgo) use ($rawByDay) {
            $date = today()->subDays($daysAgo);
            return [
                'label' => $date->format('d/m'),
                'day'   => $date->locale('fr')->isoFormat('ddd'),
                'count' => (int) ($rawByDay->get($date->format('Y-m-d')) ?? 0),
            ];
        });

        $maxDayCount = $ticketsByDay->max('count') ?: 1;

        // ─── Tickets par catégorie — top 10 ──────────────────────
        $categoryDistribution = SupportTicket::where('organization_id', $orgId)
            ->whereNotNull('ai_category_slug')
            ->selectRaw('ai_category_slug, count(*) as total')
            ->groupBy('ai_category_slug')
            ->orderByDesc('total')
            ->limit(10)
            ->get()
            ->map(fn($row) => [
                'label' => $categories->get($row->ai_category_slug, $row->ai_category_slug),
                'count' => (int) $row->total,
            ]);
        $maxCategoryDistCount = $categoryDistribution->max('count') ?: 1;

        // ─── Tickets par priorité ────────────────────────────────
        $ticketsByPriority = SupportTicket::where('organization_id', $orgId)
            ->whereNotNull('ai_priority')
            ->selectRaw('ai_priority, count(*) as total')
            ->groupBy('ai_priority')
            ->orderBy('ai_priority')
            ->get()
            ->map(fn($row) => [
                'priority' => (int) $row->ai_priority,
                'label'    => match((int) $row->ai_priority) {
                    1 => 'Très basse', 2 => 'Basse', 3 => 'Normale',
                    4 => 'Haute',      5 => 'Critique', default => 'Inconnue',
                },
                'count' => (int) $row->total,
                'color' => match((int) $row->ai_priority) {
                    1 => 'bg-gray-300',   2 => 'bg-blue-400',
                    3 => 'bg-yellow-400', 4 => 'bg-orange-400',
                    5 => 'bg-red-500',    default => 'bg-gray-300',
                },
            ]);
        $maxPriorityCount = $ticketsByPriority->max('count') ?: 1;

        // ─── Temps moyen de résolution par catégorie ─────────────
        $resolutionTimes = SupportTicket::where('organization_id', $orgId)
            ->where('status', 'created')
            ->whereNotNull('glpi_created_at')
            ->whereNotNull('ai_category_slug')
            ->selectRaw("ai_category_slug, AVG(EXTRACT(EPOCH FROM (glpi_created_at - created_at))) as avg_seconds")
            ->groupBy('ai_category_slug')
            ->orderBy('avg_seconds')
            ->limit(8)
            ->get()
            ->map(fn($row) => [
                'label'       => $categories->get($row->ai_category_slug, $row->ai_category_slug),
                'avg_seconds' => (float) $row->avg_seconds,
                'display'     => $this->formatDuration((float) $row->avg_seconds),
            ]);
        $maxResolutionSeconds = $resolutionTimes->max('avg_seconds') ?: 1;

        // ─── Last 20 tickets ──────────────────────────────────────
        $tickets = SupportTicket::where('organization_id', $orgId)
            ->with('user:id,name')
            ->orderByDesc('created_at')
            ->limit(20)
            ->get();

        $glpiBaseUrl = $org
            ? str_replace('/apirest.php', '', rtrim($org->glpi_api_url, '/'))
            : null;

        $orgName = $org?->name ?? 'Via-Mobilis';

        return view('support.dashboard', compact(
            'totalTickets',
            'categoryDistribution',
            'maxCategoryDistCount',
            'ticketsByPriority',
            'maxPriorityCount',
            'resolutionTimes',
            'maxResolutionSeconds',
            'todayTickets',
            'autoClassified',
            'autoRate',
            'topCategoryLabel',
            'topCategories',
            'maxCategoryCount',
            'ticketsByDay',
            'maxDayCount',
            'categories',
            'tickets',
            'glpiBaseUrl',
            'orgName',
        ));
    }

    private function formatDuration(float $seconds): string
    {
        if ($seconds < 3600) {
            return round($seconds / 60) . ' min';
        }
        if ($seconds < 86400) {
            return round($seconds / 3600, 1) . ' h';
        }
        return round($seconds / 86400, 1) . ' j';
    }

    public function show(Request $request, int $id): View
    {
        $user  = $request->user();
        $orgId = $user->organization?->id;

        $ticket = SupportTicket::where('id', $id)
            ->where('organization_id', $orgId)
            ->with(['user:id,name', 'comments.user:id,name', 'attachments'])
            ->firstOrFail();

        $categoryLabel = $orgId
            ? (GlpiCategoryMap::where('organization_id', $orgId)
                ->where('slug', $ticket->ai_category_slug)
                ->value('label_simple') ?? $ticket->ai_category_slug ?? '—')
            : ($ticket->ai_category_slug ?? '—');

        $estimate = $orgId && $ticket->ai_category_slug
            ? SupportTicket::estimateResolutionHours($orgId, $ticket->ai_category_slug)
            : ['hours' => null, 'count' => 0];

        // Données GLPI temps réel (null = indisponible)
        $glpiStatus = null;
        $glpiTicketId = $ticket->glpi_ticket_id ? (int) $ticket->glpi_ticket_id : null;

        Log::debug('[Ticket detail] glpi_ticket_id', [
            'ticket_id'      => $ticket->id,
            'glpi_ticket_id' => $glpiTicketId,
            'has_glpi_config' => (bool) $user->organization?->hasGlpiConfig(),
        ]);

        if ($glpiTicketId && $user->organization?->hasGlpiConfig()) {
            $glpiStatus = app(GlpiClientService::class)
                ->getTicketStatus($user->organization, $glpiTicketId);

            if ($glpiStatus !== null) {
                $glpiStatusInt = $glpiStatus['status'];

                // Synchronise glpi_status en base
                $updates = ['glpi_status' => $glpiStatusInt];

                // Propage le statut GLPI vers le statut local lisible dans my-tickets
                if (in_array($glpiStatusInt, [5, 6], true) && $ticket->status === 'created') {
                    $updates['status'] = $glpiStatusInt === 6 ? 'closed' : 'resolved';
                }

                $ticket->update($updates);
                $ticket->refresh();
            }
        }

        return view('support.ticket-detail', compact('ticket', 'categoryLabel', 'estimate', 'glpiStatus'));
    }

    public function addComment(Request $request, int $id): RedirectResponse
    {
        $user  = $request->user();
        $orgId = $user->organization?->id;

        $ticket = SupportTicket::where('id', $id)
            ->where('organization_id', $orgId)
            ->firstOrFail();

        $request->validate(['content' => ['required', 'string', 'max:2000']]);

        $ticket->comments()->create([
            'user_id' => $user->id,
            'content' => $request->content,
        ]);

        return redirect()->route('support.ticket-detail', $id)
            ->with('comment_added', true);
    }

    public function myTickets(Request $request): View
    {
        $user  = $request->user();
        $org   = $user->organization;
        $orgId = $org?->id;
        $userId = $user->id;

        abort_if(! $orgId, 403, 'Aucune organisation active associée à votre compte.');

        // ─── Stats ────────────────────────────────────────────────
        $myTotal = SupportTicket::where('user_id', $userId)
            ->where('organization_id', $orgId)
            ->count();

        $myThisWeek = SupportTicket::where('user_id', $userId)
            ->where('organization_id', $orgId)
            ->where('created_at', '>=', now()->startOfWeek())
            ->count();

        $myPending = SupportTicket::where('user_id', $userId)
            ->where('organization_id', $orgId)
            ->where('status', 'pending')
            ->count();

        // ─── Tickets list ─────────────────────────────────────────
        $tickets = SupportTicket::where('user_id', $userId)
            ->where('organization_id', $orgId)
            ->orderByDesc('created_at')
            ->get();

        // ─── Category labels ──────────────────────────────────────
        $categories = $org
            ? GlpiCategoryMap::where('organization_id', $orgId)->pluck('label_simple', 'slug')
            : collect();

        $glpiBaseUrl = $org
            ? str_replace('/apirest.php', '', rtrim($org->glpi_api_url, '/'))
            : null;

        return view('support.my-tickets', compact(
            'myTotal',
            'myThisWeek',
            'myPending',
            'tickets',
            'categories',
            'glpiBaseUrl',
        ));
    }

    /**
     * GET /support/tickets/{id}/attachments/{attachmentId}
     *
     * Téléchargement sécurisé d'une pièce jointe.
     * — Vérifie que le ticket appartient à l'organisation de l'utilisateur.
     * — Vérifie que la pièce jointe appartient bien au ticket.
     * — Logue chaque téléchargement (qui, quand, quel fichier).
     * — Jamais de chemin direct vers le fichier physique.
     */
    public function downloadAttachment(Request $request, int $id, int $attachmentId): mixed
    {
        $user  = $request->user();
        $orgId = $user->organization?->id;

        abort_if(! $orgId, 403);

        $ticket = SupportTicket::where('id', $id)
            ->where('organization_id', $orgId)
            ->firstOrFail();

        $attachment = TicketAttachment::where('id', $attachmentId)
            ->where('support_ticket_id', $ticket->id)
            ->firstOrFail();

        Log::info('[Attachment] Download', [
            'user_id'       => $user->id,
            'user_name'     => $user->name,
            'ticket_id'     => $ticket->id,
            'attachment_id' => $attachment->id,
            'filename'      => $attachment->original_name,
        ]);

        // Images → affichage inline (pour lightbox) ; autres fichiers → téléchargement
        if ($attachment->isImage()) {
            return Storage::disk('local')->response($attachment->path, $attachment->original_name);
        }

        return Storage::disk('local')->download($attachment->path, $attachment->original_name);
    }
}
