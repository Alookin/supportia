<?php

namespace App\Services;

use App\Enums\TicketStatus;
use App\Models\SupportTicket;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Publie un ticket Zeno dans GLPI : création du ticket puis envoi des pièces jointes.
 * Utilisé en direct à la création (réponse immédiate au commercial) et par le job
 * CreateGlpiTicket quand GLPI était indisponible.
 */
class GlpiTicketPublisher
{
    public function __construct(private GlpiClientService $glpi) {}

    /**
     * @return array{id: int, url: string}
     *
     * @throws \Throwable si la création du ticket GLPI échoue (l'appelant gère la file d'attente)
     */
    public function publish(SupportTicket $ticket): array
    {
        $ticket->loadMissing('organization', 'user', 'attachments');

        if ($ticket->glpi_ticket_id) {
            return ['id' => (int) $ticket->glpi_ticket_id, 'url' => $this->glpi->ticketUrlFor($ticket->organization, (int) $ticket->glpi_ticket_id)];
        }

        $attachments = $ticket->attachments->whereNull('ticket_comment_id');

        $result = $this->glpi->createTicket($ticket->organization, [
            'title'                   => $ticket->ai_title,
            'body'                    => $ticket->ai_body,
            'category_slug'           => $ticket->ai_category_slug,
            'priority'                => $ticket->ai_priority,
            'confidence'              => $ticket->ai_confidence,
            'provider'                => $ticket->ai_provider,
            'commercial_name'         => $ticket->user?->name ?? 'N/A',
            'commercial_email'        => $ticket->user?->email,
            'commercial_glpi_user_id' => $ticket->user?->glpi_user_id,
            'client_name'             => $ticket->client_name,
            'attachment_count'        => $attachments->count(),
        ]);

        $ticket->markAsCreatedInGlpi($result['id']);

        // Pièces jointes : un échec ne remet pas en cause le ticket (elles restent consultables dans Zeno)
        foreach ($attachments as $attachment) {
            try {
                $documentId = $this->glpi->uploadDocument(
                    $ticket->organization,
                    $result['id'],
                    Storage::disk('local')->path($attachment->path),
                    $attachment->original_name,
                );
                $attachment->update(['glpi_document_id' => $documentId]);
            } catch (\Throwable $e) {
                Log::warning('[GLPI] Pièce jointe non envoyée', [
                    'ticket_id'     => $ticket->id,
                    'attachment_id' => $attachment->id,
                    'error'         => $e->getMessage(),
                ]);
            }
        }

        return $result;
    }

    /**
     * Après un échec de publication directe : le ticket passe en file d'attente.
     */
    public function queue(SupportTicket $ticket, \Throwable $error): void
    {
        $ticket->update([
            'status'          => TicketStatus::Queued->value,
            'glpi_last_error' => mb_substr($error->getMessage(), 0, 1000),
        ]);

        \App\Jobs\CreateGlpiTicket::dispatch($ticket->id)->delay(now()->addMinute());
    }
}
