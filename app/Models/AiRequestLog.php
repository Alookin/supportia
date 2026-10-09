<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiRequestLog extends Model
{
    protected $fillable = [
        'support_ticket_id',
        'organization_id',
        'user_id',
        'team_id',
        'provider',
        'model',
        'attempted_provider',
        'attempted_model',
        'fallback_reason',
        'prompt_tokens',
        'completion_tokens',
        'total_tokens',
        'cached_tokens',
        'reasoning_tokens',
        'usage_raw',
        'estimated_cost',
        'latency_ms',
        'raw_response',
        'error',
    ];

    protected $casts = [
        'raw_response'      => 'array',
        'usage_raw'         => 'array',
        'prompt_tokens'     => 'integer',
        'completion_tokens' => 'integer',
        'total_tokens'      => 'integer',
        'cached_tokens'     => 'integer',
        'reasoning_tokens'  => 'integer',
        'estimated_cost'    => 'float',
        'latency_ms'        => 'integer',
    ];

    /** Ticket d'origine ; null une fois le ticket supprimé (brouillon annulé ou purgé). */
    public function supportTicket(): BelongsTo
    {
        return $this->belongsTo(SupportTicket::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }
}
