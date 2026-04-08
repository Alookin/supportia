<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiRequestLog extends Model
{
    protected $fillable = [
        'support_ticket_id',
        'provider',
        'model',
        'prompt_tokens',
        'completion_tokens',
        'latency_ms',
        'raw_response',
        'error',
    ];

    protected $casts = [
        'raw_response'     => 'array',
        'prompt_tokens'    => 'integer',
        'completion_tokens' => 'integer',
        'latency_ms'       => 'integer',
    ];

    public function supportTicket(): BelongsTo
    {
        return $this->belongsTo(SupportTicket::class);
    }
}
