<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Claude API
    |--------------------------------------------------------------------------
    */
    // Moteur de classification : « claude » (API Anthropic) ou « local » (serveur compatible
    // OpenAI : Ollama → http://127.0.0.1:11434/v1, LM Studio → http://127.0.0.1:1234/v1).
    'ai_provider' => env('AI_PROVIDER', 'claude'),
    'local_ai' => [
        'base_url' => env('LOCAL_AI_BASE_URL', 'http://127.0.0.1:11434/v1'),
        'model'    => env('LOCAL_AI_MODEL'),
        'api_key'  => env('LOCAL_AI_API_KEY'),
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
