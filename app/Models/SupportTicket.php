<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Storage;

class SupportTicket extends Model
{
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
        'was_modified_by_user',
        'status',
    ];

    protected $casts = [
        'client_ids'           => 'array',
        'ai_priority'          => 'integer',
        'ai_confidence'        => 'float',
        'glpi_ticket_id'       => 'integer',
        'glpi_created_at'      => 'datetime',
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

        if (! $category?->median_resolution_seconds || $category->resolution_sample_count < 5) {
            return null;
        }

        return [
            'hours' => round($category->median_resolution_seconds / 3600, 1),
            'count' => (int) $category->resolution_sample_count,
        ];
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
