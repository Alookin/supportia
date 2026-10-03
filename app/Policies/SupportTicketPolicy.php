<?php

namespace App\Policies;

use App\Models\SupportTicket;
use App\Models\User;

class SupportTicketPolicy
{
    /** Auteur, admin de son équipe ou admin de l'organisation. */
    public function view(User $user, SupportTicket $ticket): bool
    {
        return $ticket->isVisibleTo($user);
    }

    public function addComment(User $user, SupportTicket $ticket): bool
    {
        return $ticket->isVisibleTo($user);
    }

    /** Valider l'envoi vers GLPI : l'auteur, ou un admin de l'organisation. */
    public function confirm(User $user, SupportTicket $ticket): bool
    {
        return (int) $ticket->organization_id === (int) $user->organization_id
            && ((int) $ticket->user_id === (int) $user->id || $user->isAdmin());
    }
}
