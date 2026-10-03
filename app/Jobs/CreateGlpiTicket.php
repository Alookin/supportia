<?php

namespace App\Jobs;

use App\Enums\TicketStatus;
use App\Models\SupportTicket;
use App\Services\GlpiTicketPublisher;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Nouvelle tentative de création GLPI pour un ticket validé dont l'envoi direct a échoué.
 * 5 tentatives espacées (1 min, 5 min, 15 min, 1 h), puis statut « failed ».
 */
class CreateGlpiTicket implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    public function __construct(public int $ticketId) {}

    public function uniqueId(): string
    {
        return (string) $this->ticketId;
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [60, 300, 900, 3600];
    }

    public function handle(GlpiTicketPublisher $publisher): void
    {
        $ticket = SupportTicket::find($this->ticketId);

        // Supprimé, déjà créé, ou jamais validé : rien à faire
        if (! $ticket || $ticket->glpi_ticket_id || $ticket->status !== TicketStatus::Queued->value) {
            return;
        }

        $ticket->increment('glpi_retry_count');

        try {
            $result = $publisher->publish($ticket);
            Log::info("Ticket #{$ticket->id} → GLPI #{$result['id']} (tentative {$this->attempts()})");
        } catch (\Throwable $e) {
            $ticket->update(['glpi_last_error' => mb_substr($e->getMessage(), 0, 1000)]);

            throw $e; // le worker replanifie selon backoff()
        }
    }

    public function failed(?\Throwable $e): void
    {
        SupportTicket::whereKey($this->ticketId)->whereNull('glpi_ticket_id')->update([
            'status'          => TicketStatus::Failed->value,
            'glpi_last_error' => mb_substr((string) $e?->getMessage(), 0, 1000),
        ]);
    }
}
