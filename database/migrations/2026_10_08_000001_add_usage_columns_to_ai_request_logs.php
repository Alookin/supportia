<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Traçabilité de la consommation IA (observabilité uniquement, la classification ne change pas).
 *
 * Permet de répondre à :
 * - combien d'appels et de tokens par jour, par équipe, par utilisateur ;
 * - quel coût estimé (tarifs par modèle dans config/supportia.php, figés au moment de l'appel) ;
 * - quelle part part en fallback, et pourquoi.
 *
 * Changements :
 * - support_ticket_id devient nullable, en nullOnDelete au lieu de cascadeOnDelete : l'annulation
 *   d'un brouillon et la purge zeno:prune-drafts ne suppriment plus la trace de la consommation ;
 * - organization_id / user_id / team_id recopiés sur le log : l'attribution survit au ticket ;
 * - attempted_provider / attempted_model : moteur réellement tenté, y compris en cas de fallback
 *   (provider/model gardent leur sens actuel : fallback_keywords / keywords) ;
 * - fallback_reason : cause normalisée du fallback (key_missing, key_rejected, quota_exceeded,
 *   timeout, connection_error, http_error, invalid_response, other), null sans fallback ;
 * - total_tokens, cached_tokens, reasoning_tokens + bloc usage brut de l'API (usage_raw) ;
 * - estimated_cost : coût en dollars US (10 décimales : un appel coûte moins d'un millième de dollar),
 *   null tant que les tarifs du modèle ne sont pas renseignés.
 *
 * La durée de l'appel existe déjà (latency_ms) : elle n'est pas dupliquée.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_request_logs', function (Blueprint $table) {
            $table->dropForeign(['support_ticket_id']);
        });

        Schema::table('ai_request_logs', function (Blueprint $table) {
            $table->unsignedBigInteger('support_ticket_id')->nullable()->change();
            $table->foreign('support_ticket_id')->references('id')->on('support_tickets')->nullOnDelete();

            $table->foreignId('organization_id')->nullable()->after('support_ticket_id')->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->after('organization_id')->constrained()->nullOnDelete();
            $table->foreignId('team_id')->nullable()->after('user_id')->constrained()->nullOnDelete();

            $table->string('attempted_provider', 50)->nullable()->after('model');
            $table->string('attempted_model', 100)->nullable()->after('attempted_provider');
            $table->string('fallback_reason', 30)->nullable()->after('attempted_model');

            $table->integer('total_tokens')->nullable()->after('completion_tokens');
            $table->integer('cached_tokens')->nullable()->after('total_tokens');
            $table->integer('reasoning_tokens')->nullable()->after('cached_tokens');
            $table->jsonb('usage_raw')->nullable()->after('reasoning_tokens');
            $table->decimal('estimated_cost', 16, 10)->nullable()->after('usage_raw');

            $table->index('created_at');
            $table->index(['user_id', 'created_at']);
            $table->index(['team_id', 'created_at']);
        });

        // ─── Reprise des lignes existantes ───────────────────────────────────
        // SQL portable (PostgreSQL en production, SQLite en tests).
        foreach (['organization_id', 'user_id', 'team_id'] as $column) {
            DB::statement("
                UPDATE ai_request_logs
                SET {$column} = (SELECT t.{$column} FROM support_tickets t WHERE t.id = ai_request_logs.support_ticket_id)
                WHERE support_ticket_id IS NOT NULL
            ");
        }

        DB::table('ai_request_logs')
            ->whereNotNull('prompt_tokens')->whereNotNull('completion_tokens')
            ->update(['total_tokens' => DB::raw('prompt_tokens + completion_tokens')]);

        // Appels réussis : le moteur tenté est celui qui a répondu
        DB::table('ai_request_logs')
            ->where('provider', '!=', 'fallback_keywords')
            ->update(['attempted_provider' => DB::raw('provider'), 'attempted_model' => DB::raw('model')]);

        // Fallbacks : cause déduite du message enregistré (préfixes d'incident, puis messages
        // antérieurs aux préfixes). Premier motif reconnu gagnant ; le moteur tenté reste inconnu.
        $reasons = [
            '[OPENAI_KEY_MISSING]%'                  => 'key_missing',
            '[OPENAI_KEY_REJECTED]%'                 => 'key_rejected',
            '[OPENAI_QUOTA_EXCEEDED]%'               => 'quota_exceeded',
            'OPENAI_API_KEY non configurée%'         => 'key_missing',
            'Aucune clé Claude%'                     => 'key_missing',
            'HTTP request returned status code 401%' => 'key_rejected',
            'HTTP request returned status code 429%' => 'quota_exceeded',
            '%cURL error 28%'                        => 'timeout',
            '%timed out%'                            => 'timeout',
            '%cURL error 7:%'                        => 'connection_error',
            '%Connection refused%'                   => 'connection_error',
            'HTTP request returned status code%'     => 'http_error',
            'Impossible de parser%'                  => 'invalid_response',
            'Syntax error%'                          => 'invalid_response',
        ];
        foreach ($reasons as $pattern => $reason) {
            DB::table('ai_request_logs')
                ->where('provider', 'fallback_keywords')
                ->whereNull('fallback_reason')
                ->where('error', 'like', $pattern)
                ->update(['fallback_reason' => $reason]);
        }
        DB::table('ai_request_logs')
            ->where('provider', 'fallback_keywords')
            ->whereNull('fallback_reason')
            ->update(['fallback_reason' => 'other']);
    }

    public function down(): void
    {
        Schema::table('ai_request_logs', function (Blueprint $table) {
            $table->dropIndex(['created_at']);
            $table->dropIndex(['user_id', 'created_at']);
            $table->dropIndex(['team_id', 'created_at']);

            $table->dropConstrainedForeignId('organization_id');
            $table->dropConstrainedForeignId('user_id');
            $table->dropConstrainedForeignId('team_id');

            $table->dropColumn([
                'attempted_provider', 'attempted_model', 'fallback_reason',
                'total_tokens', 'cached_tokens', 'reasoning_tokens', 'usage_raw', 'estimated_cost',
            ]);

            $table->dropForeign(['support_ticket_id']);
        });

        // Retour à support_ticket_id obligatoire : les logs orphelins (ticket supprimé) sont perdus
        DB::table('ai_request_logs')->whereNull('support_ticket_id')->delete();

        Schema::table('ai_request_logs', function (Blueprint $table) {
            $table->unsignedBigInteger('support_ticket_id')->nullable(false)->change();
            $table->foreign('support_ticket_id')->references('id')->on('support_tickets')->cascadeOnDelete();
        });
    }
};
