<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Storage;

class SupportTicket extends Model
{
    /** Moteurs d'IA réels. Tout autre ai_provider (fallback_keywords, manual) = analyse simplifiée. */
    public const AI_PROVIDERS = ['openai', 'claude', 'local'];

    protected $fillable = [
        'organization_id',
        'user_id',
        'team_id',
        'client_identifier',
        'client_name',
        'client_ids',
        'raw_description',
        'screenshot_path',
        'ai_title',
        'ai_body',
        'ai_category_slug',
        'ai_priority',
        'ai_confidence',
        'ai_provider',
        'glpi_ticket_id',
        'glpi_status',
        'glpi_created_at',
        'glpi_retry_count',
        'glpi_last_error',
        'glpi_category_id_final',
        'resolved_notified_at',
        'glpi_synced_at',
        'was_modified_by_user',
        'status',
    ];

    protected $casts = [
        'client_ids'           => 'array',
        'ai_priority'          => 'integer',
        'ai_confidence'        => 'float',
        'glpi_ticket_id'       => 'integer',
        'glpi_created_at'      => 'datetime',
        'resolved_notified_at' => 'datetime',
        'glpi_synced_at'       => 'datetime',
        'glpi_retry_count'     => 'integer',
        'was_modified_by_user' => 'boolean',
    ];

    // ─── Boot ───────────────────────────────────────────

    protected static function boot(): void
    {
        parent::boot();

        // RGPD : suppression des fichiers physiques avant suppression du ticket
        static::deleting(function (self $ticket): void {
            $ticket->deleteAttachments();
        });
    }

    // ─── Relations ──────────────────────────────────────

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function aiRequestLogs(): HasMany
    {
        return $this->hasMany(AiRequestLog::class, 'support_ticket_id');
    }

    public function comments(): HasMany
    {
        return $this->hasMany(TicketComment::class, 'support_ticket_id')->orderBy('created_at');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(TicketAttachment::class, 'support_ticket_id')->orderBy('created_at');
    }

    // ─── RGPD ───────────────────────────────────────────

    /**
     * Supprime les fichiers physiques de toutes les pièces jointes du ticket.
     * Appelé automatiquement avant la suppression du ticket (voir boot()).
     *
     * Politique de rétention recommandée : 12 mois après clôture du ticket
     * (à définir selon la politique interne de conservation des données RGPD).
     */
    public function deleteAttachments(): void
    {
        foreach ($this->attachments()->lazy() as $attachment) {
            Storage::disk('local')->delete($attachment->path);
        }
        $this->attachments()->delete();
    }

    // ─── Scopes ─────────────────────────────────────────

    /**
     * Tickets réellement soumis : exclut les brouillons « à valider » que le commercial
     * n'a pas confirmés (supprimés à l'annulation, purgés après 24 h sinon).
     */
    public function scopeSubmitted(Builder $query): Builder
    {
        return $query->where('status', '!=', 'needs_review');
    }

    /**
     * Tickets visibles par un utilisateur (toujours limités à son organisation) :
     * - admin       : tous les tickets de l'organisation
     * - admin équipe: les tickets de son équipe + les siens
     * - membre      : uniquement les siens
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        $query->where('organization_id', $user->organization_id);

        if ($user->isAdmin()) {
            return $query;
        }

        if ($user->isTeamAdmin()) {
            return $query->where(fn (Builder $q) => $q
                ->where('team_id', $user->team_id)
                ->orWhere('user_id', $user->id));
        }

        return $query->where('user_id', $user->id);
    }

    public function isVisibleTo(User $user): bool
    {
        if ((int) $this->organization_id !== (int) $user->organization_id) {
            return false;
        }

        return (int) $this->user_id === (int) $user->id
            || $user->isAdmin()
            || ($user->isTeamAdmin() && $this->team_id !== null && (int) $this->team_id === (int) $user->team_id);
    }


    // ─── Actions ────────────────────────────────────────


    // ─── Affichage ──────────────────────────────────────

    /**
     * Libellé du moteur côté commercial, identique sur tous les écrans
     * (jamais « mots-clés », qui ne lui parle pas).
     */
    public static function analysisLabel(?string $provider): string
    {
        return in_array($provider, self::AI_PROVIDERS, true) ? 'Analyse IA' : 'Analyse simplifiée';
    }

    public function wasAnalyzedByAi(): bool
    {
        return in_array($this->ai_provider, self::AI_PROVIDERS, true);
    }

    /** Numéro GLPI affiché (#900001), ou null tant que le ticket n'est pas créé dans GLPI. */
    public function displayNumber(): ?string
    {
        return $this->glpi_ticket_id ? '#' . $this->glpi_ticket_id : null;
    }

    /**
     * La description originale apporte-t-elle quelque chose par rapport à la description affichée ?
     * Non quand les deux textes sont identiques (analyse simplifiée sans retouche, notamment).
     */
    public function hasDistinctOriginalDescription(): bool
    {
        $normalize = fn (?string $text) => trim(preg_replace('/\s+/u', ' ', (string) $text));

        return $normalize($this->raw_description) !== ''
            && $normalize($this->raw_description) !== $normalize($this->ai_body);
    }

    /**
     * Délai habituel de traitement de la catégorie du ticket (médiane GLPI sur 12 mois),
     * ou null si moins de 5 tickets de référence.
     *
     * @return array{hours: float, count: int}|null
     */
    public function resolutionEstimate(): ?array
    {
        if (! $this->ai_category_slug) {
            return null;
        }

        $category = GlpiCategoryMap::where('organization_id', $this->organization_id)
            ->where('slug', $this->ai_category_slug)
            ->first();

        if (! $category?->median_resolution_seconds || $category->resolution_sample_count < 5
            || $category->median_resolution_seconds > config('supportia.estimate_max_hours', 120) * 3600) {
            return null;
        }

        return [
            'hours' => round($category->median_resolution_seconds / 3600, 1),
            'count' => (int) $category->resolution_sample_count,
        ];
    }

    /**
     * Statut affiché, identique sur toutes les pages (liste, détail, suivi, accueil).
     * Le statut GLPI synchronisé prime ; sinon le statut Zeno.
     *
     * @return array{label: string, class: string, dot: string, group: string}
     */
    public function statusBadge(): array
    {
        $gray   = ['class' => 'bg-gray-100 text-gray-600 ring-1 ring-gray-200',        'dot' => 'bg-gray-400'];
        $blue   = ['class' => 'bg-blue-50 text-blue-700 ring-1 ring-blue-200',         'dot' => 'bg-blue-500'];
        $amber  = ['class' => 'bg-amber-50 text-amber-700 ring-1 ring-amber-200',      'dot' => 'bg-amber-400'];
        $green  = ['class' => 'bg-emerald-50 text-emerald-700 ring-1 ring-emerald-200', 'dot' => 'bg-emerald-500'];
        $red    = ['class' => 'bg-red-50 text-red-600 ring-1 ring-red-200',            'dot' => 'bg-red-500'];
        $glpi   = (int) $this->glpi_status;

        return match (true) {
            $this->status === 'closed' || $glpi === 6   => ['label' => 'Clôturé', 'group' => 'resolu'] + $gray,
            $this->status === 'resolved' || $glpi === 5 => ['label' => 'Résolu', 'group' => 'resolu'] + $green,
            $this->status === 'needs_review'             => ['label' => 'Brouillon', 'group' => 'attente'] + $amber,
            $this->status === 'queued'                   => ['label' => "En attente d'envoi", 'group' => 'attente'] + $amber,
            $this->status === 'failed'                   => ['label' => "Échec d'envoi", 'group' => 'attente'] + $red,
            $glpi === 4                                  => ['label' => 'En attente', 'group' => 'attente'] + $amber,
            in_array($glpi, [2, 3], true)                => ['label' => 'En cours', 'group' => 'en_cours'] + $blue,
            $glpi === 1                                  => ['label' => 'Nouveau', 'group' => 'en_cours'] + $blue,
            default                                      => ['label' => 'Transmis au support', 'group' => 'en_cours'] + $blue,
        };
    }

    public function markAsCreatedInGlpi(int $glpiTicketId): void
    {
        $this->update([
            'glpi_ticket_id'  => $glpiTicketId,
            'glpi_created_at' => now(),
            'status'          => 'created',
            'glpi_last_error' => null,
        ]);
    }

}
