<?php

namespace App\Jobs;

use App\Models\SupportTicket;
use App\Services\GlpiClientService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class RetryGlpiTicketCreation implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(GlpiClientService $glpiClient): void
    {
        $tickets = SupportTicket::pendingGlpi()
            ->with('organization', 'user')
            ->get();

        if ($tickets->isEmpty()) {
            return;
        }

        Log::info("RetryGlpiTicketCreation: {$tickets->count()} ticket(s) à traiter");

        foreach ($tickets as $ticket) {
            if (! $ticket->organization?->hasGlpiConfig()) {
                Log::warning("Ticket #{$ticket->id} : organisation sans config GLPI, skip");
                continue;
            }

            try {
                $glpiResult = $glpiClient->createTicket($ticket->organization, [
                    'title'           => $ticket->ai_title,
                    'body'            => $ticket->ai_body,
                    'category_slug'   => $ticket->ai_category_slug,
                    'priority'        => $ticket->ai_priority,
                    'confidence'      => $ticket->ai_confidence,
                    'provider'        => $ticket->ai_provider,
                    'commercial_name'         => $ticket->user->name ?? 'N/A',
                    'commercial_email'        => $ticket->user->email ?? null,
                    'commercial_glpi_user_id' => $ticket->user->glpi_user_id ?? null,
                    'client_name'             => $ticket->client_name,
                    'attachment_count'        => $ticket->attachments()->count(),
                ]);

                $ticket->markAsCreatedInGlpi($glpiResult['id']);

                Log::info("Ticket #{$ticket->id} → GLPI #{$glpiResult['id']}");
            } catch (\Throwable $e) {
                $ticket->markAsGlpiFailed($e->getMessage());

                Log::warning("Ticket #{$ticket->id} retry failed: {$e->getMessage()}");
            }
        }
    }
}
