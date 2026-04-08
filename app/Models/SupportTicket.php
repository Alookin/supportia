<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Builder;

class SupportTicket extends Model
{
    protected $fillable = [
        'organization_id',
        'user_id',
        'client_identifier',
        'client_name',
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
        'ai_priority'          => 'integer',
        'ai_confidence'        => 'float',
        'glpi_ticket_id'       => 'integer',
        'glpi_created_at'      => 'datetime',
        'glpi_retry_count'     => 'integer',
        'was_modified_by_user' => 'boolean',
    ];

    // ─── Relations ──────────────────────────────────────

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function aiRequestLogs(): HasMany
    {
        return $this->hasMany(AiRequestLog::class, 'support_ticket_id');
    }

    public function comments(): HasMany
    {
        return $this->hasMany(TicketComment::class, 'support_ticket_id')->orderBy('created_at');
    }

    // ─── Scopes ─────────────────────────────────────────

    /**
     * Tickets en attente de création GLPI (pour le job de retry).
     */
    public function scopePendingGlpi(Builder $query): Builder
    {
        return $query->whereNull('glpi_ticket_id')
                     ->where('status', 'pending')
                     ->where('glpi_retry_count', '<', config('supportia.glpi_retry_attempts', 3))
                     ->whereNotNull('ai_title'); // ne retry que si l'IA a classifié
    }

    // ─── Actions ────────────────────────────────────────

    public function canRetry(): bool
    {
        return is_null($this->glpi_ticket_id)
            && $this->glpi_retry_count < config('supportia.glpi_retry_attempts', 3);
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

    public function markAsGlpiFailed(string $error): void
    {
        $this->increment('glpi_retry_count');
        $this->update([
            'glpi_last_error' => $error,
            'status' => $this->glpi_retry_count >= config('supportia.glpi_retry_attempts', 3)
                ? 'failed'
                : 'pending',
        ]);
    }

    /**
     * Estimate average resolution time for a given org + category,
     * based on past tickets (created_at → glpi_created_at).
     *
     * Returns ['hours' => float|null, 'count' => int].
     * hours is null if fewer than 3 tickets are available.
     */
    public static function estimateResolutionHours(int $orgId, string $categorySlug): array
    {
        $result = static::where('organization_id', $orgId)
            ->where('ai_category_slug', $categorySlug)
            ->where('status', 'created')
            ->whereNotNull('glpi_created_at')
            ->selectRaw("COUNT(*) as total, AVG(EXTRACT(EPOCH FROM (glpi_created_at - created_at))) as avg_seconds")
            ->first();

        $count = (int) ($result?->total ?? 0);

        if ($count < 3) {
            return ['hours' => null, 'count' => $count];
        }

        return [
            'hours' => round((float) $result->avg_seconds / 3600, 1),
            'count' => $count,
        ];
    }
}
