<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Moteurs d'IA
    |--------------------------------------------------------------------------
    */
    // Moteur de classification : « openai » (API OpenAI, par défaut), « claude » (API Anthropic)
    // ou « local » (serveur auto-hébergé compatible OpenAI : Ollama → http://127.0.0.1:11434/v1,
    // LM Studio → http://127.0.0.1:1234/v1 ; interdit en production).
    'ai_provider' => env('AI_PROVIDER', 'openai'),
    'openai' => [
        'base_url' => env('OPENAI_BASE_URL', 'https://api.openai.com/v1'),
        'api_key'  => env('OPENAI_API_KEY'),
        'model'    => env('OPENAI_MODEL', 'gpt-5.4-mini'),
        // gpt-5.4-mini répond en ~2 s (benchmark du 08/10/2026) : 10 s laissent de la marge
        'timeout'  => (int) env('OPENAI_TIMEOUT', 10),
    ],
    'local_ai' => [
        'base_url' => env('LOCAL_AI_BASE_URL', 'http://127.0.0.1:11434/v1'),
        'model'    => env('LOCAL_AI_MODEL'),
        'api_key'  => env('LOCAL_AI_API_KEY'),
    ],

    // Tarifs des modèles, en dollars US par million de tokens, pour le coût estimé de chaque appel
    // (ai_request_logs.estimated_cost, figé au moment de l'appel). À renseigner : tant qu'un tarif
    // vaut null, le coût du modèle n'est pas estimé. cached_input null = même tarif que input.
    // Après saisie, `php artisan zeno:estimate-ai-costs` complète les appels déjà enregistrés.
    'ai_pricing' => [
        // Source tierce datée du 06/10/2026, à confirmer dans le tableau de bord OpenAI
        'gpt-5.4-mini' => [
            'input'        => 0.375,
            'cached_input' => 0.037,
            'output'       => 2.25,
        ],
        // De mémoire, à vérifier (moteur plus utilisé, enjeu faible)
        'claude-sonnet-4-20250514' => [
            'input'        => 3.00,
            'cached_input' => null,
            'output'       => 15.00,
        ],
    ],

    'claude_api_key' => env('CLAUDE_API_KEY'),
    'claude_model' => env('CLAUDE_MODEL', 'claude-sonnet-4-20250514'),
    // Sonnet met souvent plus de 5 s à répondre : un timeout trop court déclenche le fallback mots-clés
    'ai_timeout' => (int) env('SUPPORTIA_AI_TIMEOUT', 25),
    'glpi_timeout' => (int) env('SUPPORTIA_GLPI_TIMEOUT', 15),
    'claude_verify_ssl' => env('CLAUDE_VERIFY_SSL', true),

    /*
    |--------------------------------------------------------------------------
    | Classification
    |--------------------------------------------------------------------------
    */
    'confidence_threshold' => (float) env('SUPPORTIA_CONFIDENCE_THRESHOLD', 0.7),

    /*
    |--------------------------------------------------------------------------
    | GLPI defaults
    |--------------------------------------------------------------------------
    */
    'glpi_ticket_type' => 1, // 1 = Incident, 2 = Demande
    'glpi_retry_attempts' => 3,
    'glpi_retry_delay' => 300, // secondes entre chaque retry
    'glpi_verify_ssl' => env('GLPI_VERIFY_SSL', true),

    // Mode simulation (tests en local) : aucune écriture dans GLPI. Les créations de tickets,
    // pièces jointes et commentaires sont journalisées et reçoivent un faux numéro.
    // Les lectures (statistiques, export) restent réelles. Ignoré en production.
    'glpi_dry_run' => (bool) env('GLPI_DRY_RUN', false),

    // Prévenir le demandeur par email quand son ticket est résolu dans GLPI
    // (désactiver si les notifications GLPI le font déjà, pour éviter les doublons).
    'notify_resolved' => (bool) env('ZENO_NOTIFY_RESOLVED', true),

    // Contenu des logs IA (raw_response, error : texte rédigé à partir de la saisie du client) purgé
    // au-delà de ce délai par zeno:prune-ai-log-content ; les compteurs (tokens, coût, durée, cause)
    // sont conservés indéfiniment. Purge immédiate à la suppression du ticket (SupportTicket::boot).
    'ai_log_content_retention_days' => (int) env('ZENO_AI_LOG_CONTENT_RETENTION_DAYS', 90),

    // Au-delà, le délai habituel n'est pas affiché au commercial (médianes de 12 à 21 jours
    // sur Bug mails, Traductions, Évolutions : décourageant et peu informatif).
    'estimate_max_hours' => (int) env('ZENO_ESTIMATE_MAX_HOURS', 120),

    /*
    |--------------------------------------------------------------------------
    | Pièces jointes
    |--------------------------------------------------------------------------
    |
    | Limites et types autorisés pour les uploads (création de tickets et
    | commentaires). La validation serveur applique les règles Laravel
    | "mimes:" (extension + magic bytes) ET "mimetypes:" (MIME finfo,
    | indépendant de l'extension) — la double règle bloque les fichiers
    | à extension trompeuse (.php renommé en .txt, etc.).
    |
    */
    'attachments' => [
        'max_size_kb' => (int) env('SUPPORTIA_ATTACHMENT_MAX_KB', 10240),

        'allowed_extensions' => [
            'jpg', 'jpeg', 'png', 'gif', 'webp',
            'pdf',
            'csv', 'txt', 'log',
            'xls', 'xlsx',
        ],

        'allowed_mimetypes' => [
            'image/jpeg',
            'image/png',
            'image/gif',
            'image/webp',
            'application/pdf',
            'text/csv',
            'text/plain',
            'text/x-log',
            'application/vnd.ms-excel',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ],
    ],

];
