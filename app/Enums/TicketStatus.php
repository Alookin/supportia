<?php

namespace App\Enums;

/**
 * Cycle de vie d'un ticket Zeno.
 *
 *   needs_review → (validation du commercial) → queued → created → resolved / closed
 *                                                  └──→ failed (après épuisement des tentatives)
 */
enum TicketStatus: string
{
    /** Confiance IA trop basse : le commercial doit valider avant envoi. Jamais envoyé à GLPI. */
    case NeedsReview = 'needs_review';
    /** Validé, en attente d'envoi (GLPI indisponible : nouvelles tentatives automatiques). */
    case Queued = 'queued';
    /** Créé dans GLPI. */
    case Created = 'created';
    /** Envoi abandonné après toutes les tentatives. */
    case Failed = 'failed';
    case Resolved = 'resolved';
    case Closed = 'closed';

    public function label(): string
    {
        return match ($this) {
            self::NeedsReview => 'À valider',
            self::Queued      => 'En file',
            self::Created     => 'Dans GLPI',
            self::Failed      => 'Échec',
            self::Resolved    => 'Résolu',
            self::Closed      => 'Fermé',
        };
    }
}
