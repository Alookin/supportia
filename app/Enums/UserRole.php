<?php

namespace App\Enums;

/**
 * Rôles Zeno (au sein d'une organisation).
 *
 * - Member    : voit uniquement ses propres tickets (commercial, marketeur…).
 * - TeamAdmin : voit les tickets de toute son équipe + le dashboard de l'équipe
 *               (admin commercial, admin marketing…).
 * - Admin     : voit tous les tickets de l'organisation + le dashboard global.
 */
enum UserRole: string
{
    case Member    = 'member';
    case TeamAdmin = 'team_admin';
    case Admin     = 'admin';

    public function label(): string
    {
        return match ($this) {
            self::Member    => 'Membre',
            self::TeamAdmin => "Admin d'équipe",
            self::Admin     => 'Administrateur',
        };
    }
}
