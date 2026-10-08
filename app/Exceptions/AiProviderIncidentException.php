<?php

namespace App\Exceptions;

/**
 * Échec du moteur d'IA qui demande une intervention (clé absente ou refusée, quota épuisé),
 * à distinguer d'un timeout ou d'une réponse mal formée.
 *
 * Le message commence par « [CODE] » : il est enregistré tel quel dans ai_request_logs.error,
 * ce qui permet de compter les incidents en SQL (error LIKE '[OPENAI_%').
 */
class AiProviderIncidentException extends \RuntimeException
{
    public const OPENAI_KEY_MISSING    = 'OPENAI_KEY_MISSING';
    public const OPENAI_KEY_REJECTED   = 'OPENAI_KEY_REJECTED';
    public const OPENAI_QUOTA_EXCEEDED = 'OPENAI_QUOTA_EXCEEDED';

    public function __construct(public readonly string $incident, string $message)
    {
        parent::__construct("[{$incident}] {$message}");
    }
}
