<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Claude API
    |--------------------------------------------------------------------------
    */
    'claude_api_key' => env('CLAUDE_API_KEY'),
    'claude_model' => env('CLAUDE_MODEL', 'claude-sonnet-4-20250514'),
    'ai_timeout' => (int) env('SUPPORTIA_AI_TIMEOUT', 5),

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

];
