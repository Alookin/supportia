<?php

namespace App\Notifications;

use App\Models\SupportTicket;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Prévient le demandeur que le support a traité sa demande.
 */
class TicketResolvedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public SupportTicket $ticket) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $label = $this->ticket->status === 'closed' ? 'clôturée' : 'résolue';

        return (new MailMessage)
            ->subject("Votre demande est {$label} : {$this->ticket->ai_title}")
            ->greeting('Bonjour ' . explode(' ', trim($notifiable->name))[0] . ',')
            ->line("Le support a {$label} votre demande « {$this->ticket->ai_title} »"
                . ($this->ticket->client_name ? " (client {$this->ticket->client_name})" : '') . '.')
            ->action('Voir la réponse du support', route('support.ticket-detail', $this->ticket->id))
            ->line("Si le problème persiste, répondez directement depuis la page du ticket.");
    }
}
