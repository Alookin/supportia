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
use Illuminate\Support\Facades\Cache;
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

        abort_unless($user->canSupervise(), 403, 'Dashboard réservé aux administrateurs.');

        // ─── Category label map (needed early for chart labels) ───
        $categories = $org
            ? GlpiCategoryMap::where('organization_id', $orgId)->pluck('label_simple', 'slug')
            : collect();

        // ─── Stats cards ──────────────────────────────────────────
        $totalTickets = SupportTicket::visibleTo($user)->count();

        $todayTickets = SupportTicket::visibleTo($user)
            ->whereDate('created_at', today())
            ->count();

        $autoClassified = SupportTicket::visibleTo($user)
            ->where('ai_confidence', '>=', config('supportia.confidence_threshold', 0.7))
            ->count();

        $autoRate = $totalTickets > 0
            ? round($autoClassified / $totalTickets * 100)
            : 0;

        // ─── Précision de l'IA : catégorie Zeno vs catégorie finale dans GLPI ───
        // (le technicien a pu la corriger ; renseignée par glpi:sync-ticket-statuses)
        $slugToGlpiId = $org
            ? GlpiCategoryMap::where('organization_id', $orgId)->pluck('glpi_category_id', 'slug')
            : collect();
        $checked = SupportTicket::visibleTo($user)
            ->where('glpi_category_id_final', '>', 0)
            ->get(['ai_category_slug', 'glpi_category_id_final']);
        $aiAccuracyCount = $checked->count();
        $aiAccuracy = $aiAccuracyCount > 0
            ? (int) round($checked->filter(fn ($t) => (int) $slugToGlpiId->get($t->ai_category_slug) === (int) $t->glpi_category_id_final)->count() / $aiAccuracyCount * 100)
            : null;

        // ─── Top 5 categories (horizontal bar chart) ─────────────
        $topCategories = SupportTicket::visibleTo($user)
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

        $rawByDay = SupportTicket::visibleTo($user)
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
        $categoryDistribution = SupportTicket::visibleTo($user)
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
        $ticketsByPriority = SupportTicket::visibleTo($user)
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


        // ─── Last 20 tickets ──────────────────────────────────────
        $tickets = SupportTicket::visibleTo($user)
            ->with('user:id,name')
            ->orderByDesc('created_at')
            ->limit(20)
            ->get();

        $glpiBaseUrl = $org
            ? str_replace('/apirest.php', '', rtrim($org->glpi_api_url, '/'))
            : null;

        $orgName = $user->isAdmin()
            ? ($org?->name ?? 'Via-Mobilis')
            : ($user->team?->name ?? $org?->name ?? 'Via-Mobilis');

        return view('support.dashboard', compact(
            'totalTickets',
            'categoryDistribution',
            'maxCategoryDistCount',
            'ticketsByPriority',
            'maxPriorityCount',
            'todayTickets',
            'autoClassified',
            'autoRate',
            'aiAccuracy',
            'aiAccuracyCount',
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


    public function show(Request $request, int $id): View
    {
        $user  = $request->user();
        $orgId = $user->organization?->id;

        $ticket = SupportTicket::with(['user:id,name', 'comments.user:id,name', 'comments.attachment', 'attachments'])
            ->findOrFail($id);

        $this->authorize('view', $ticket);

        $categoryLabel = $orgId
            ? (GlpiCategoryMap::where('organization_id', $orgId)
                ->where('slug', $ticket->ai_category_slug)
                ->value('label_simple') ?? $ticket->ai_category_slug ?? '—')
            : ($ticket->ai_category_slug ?? '—');


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

        return view('support.ticket-detail', compact('ticket', 'categoryLabel', 'glpiStatus') + ['estimate' => $ticket->resolutionEstimate()]);
    }

    public function addComment(Request $request, int $id): RedirectResponse
    {
        $user   = $request->user();
        $ticket = SupportTicket::findOrFail($id);

        $this->authorize('addComment', $ticket);

        $request->validate([
            'content'    => ['nullable', 'string', 'max:2000'],
            'attachment' => ['nullable', 'file', 'max:10240',
                             'mimes:jpg,jpeg,png,gif,webp,pdf,csv,txt,log'],
        ]);

        $hasFile = $request->hasFile('attachment');
        $content = trim($request->content ?? '');

        // Refuser si ni texte ni fichier
        if (empty($content) && ! $hasFile) {
            return back()->withErrors(['content' => 'Veuillez saisir un message ou joindre un fichier.']);
        }

        // Fichier seul sans texte → le nom du fichier sert de contenu de la bulle
        if (empty($content) && $hasFile) {
            $content = $request->file('attachment')->getClientOriginalName();
        }

        $comment = $ticket->comments()->create([
            'user_id' => $user->id,
            'content' => $content,
        ]);

        $attachmentOriginalName = null;
        if ($hasFile) {
            $file = $request->file('attachment');
            $uuid = (string) \Illuminate\Support\Str::uuid();
            $ext  = $file->getClientOriginalExtension();
            $path = $file->storeAs('attachments/comments', $uuid . '.' . $ext, 'local');
            $attachmentOriginalName = $file->getClientOriginalName();

            TicketAttachment::create([
                'support_ticket_id' => $ticket->id,
                'ticket_comment_id' => $comment->id,
                'filename'          => $uuid . '.' . $ext,
                'original_name'     => $attachmentOriginalName,
                'mime_type'         => $file->getMimeType(),
                'size'              => $file->getSize(),
                'path'              => $path,
            ]);
        }

        // Poster le commentaire comme followup dans GLPI pour que le technicien le voie
        if ($ticket->glpi_ticket_id && $user->organization?->hasGlpiConfig()) {
            $glpiContent = $content;
            if ($attachmentOriginalName !== null) {
                $glpiContent .= "\n\n[Pièce jointe disponible dans Zeno : {$attachmentOriginalName}]";
            }
            $posted = app(GlpiClientService::class)->addFollowup(
                $user->organization,
                (int) $ticket->glpi_ticket_id,
                $glpiContent
            );
            // Invalider le cache de statut si le followup a bien été créé
            if ($posted) {
                Cache::forget("glpi_ticket_status_{$user->organization->id}_{$ticket->glpi_ticket_id}");
            }
        }

        return redirect()->route('support.ticket-detail', $id)
            ->with('comment_added', true);
    }

    public function myTickets(Request $request): View
    {
        $user = $request->user();

        abort_if(! $user->organization_id, 403, 'Aucune organisation active associée à votre compte.');

        return $this->ticketList(SupportTicket::where('organization_id', $user->organization_id)->where('user_id', $user->id), false);
    }

    /**
     * Tickets de toute l'équipe (admin d'équipe) ou de toute l'organisation (admin).
     */
    public function teamTickets(Request $request): View
    {
        $user = $request->user();

        abort_unless($user->canSupervise(), 403, 'Réservé aux administrateurs.');

        return $this->ticketList(SupportTicket::visibleTo($user), true, $user->isAdmin() ? 'Tous les tickets' : 'Tickets de l\'équipe '.$user->team?->name);
    }

    private function ticketList($base, bool $teamView, ?string $title = null): View
    {
        $org = request()->user()->organization;

        $myTotal    = (clone $base)->count();
        $myThisWeek = (clone $base)->where('created_at', '>=', now()->startOfWeek())->count();
        $myPending  = (clone $base)->whereIn('status', ['needs_review', 'queued'])->count();

        $tickets = (clone $base)->with('user:id,name')->orderByDesc('created_at')->get();

        $categories = $org
            ? GlpiCategoryMap::where('organization_id', $org->id)->pluck('label_simple', 'slug')
            : collect();

        $glpiBaseUrl = $org
            ? str_replace('/apirest.php', '', rtrim((string) $org->glpi_api_url, '/'))
            : null;

        return view('support.my-tickets', compact(
            'myTotal',
            'myThisWeek',
            'myPending',
            'tickets',
            'categories',
            'glpiBaseUrl',
            'teamView',
            'title',
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

        $ticket = SupportTicket::visibleTo($user)
            ->where('id', $id)
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
