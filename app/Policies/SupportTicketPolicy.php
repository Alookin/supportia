<?php

namespace App\Policies;

use App\Models\SupportTicket;
use App\Models\User;

class SupportTicketPolicy
{
    public function view(User $user, SupportTicket $ticket): bool
    {
        return $ticket->organization_id === $user->organization_id;
    }

    public function addComment(User $user, SupportTicket $ticket): bool
    {
        return $ticket->organization_id === $user->organization_id;
    }

    public function confirm(User $user, SupportTicket $ticket): bool
    {
        return $ticket->organization_id === $user->organization_id;
    }
}
