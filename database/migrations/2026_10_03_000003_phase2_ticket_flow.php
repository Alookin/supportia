<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2 :
 * - statuts explicites : l'ancien « pending » couvrait à la fois « en attente de validation »
 *   (jamais envoyé) et « en attente de GLPI » (envoi échoué) ;
 * - pièces jointes envoyées dans GLPI (glpi_document_id) ;
 * - délai de résolution médian par catégorie GLPI (calculé par glpi:sync-resolution-stats).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('support_tickets')->where('status', 'pending')->whereNull('glpi_ticket_id')
            ->where('glpi_retry_count', 0)->update(['status' => 'needs_review']);
        DB::table('support_tickets')->where('status', 'pending')
            ->update(['status' => 'queued']);

        Schema::table('support_tickets', function (Blueprint $table) {
            $table->string('status', 30)->default('needs_review')->change();
        });

        Schema::table('ticket_attachments', function (Blueprint $table) {
            $table->unsignedBigInteger('glpi_document_id')->nullable()->after('path');
        });

        Schema::table('glpi_category_maps', function (Blueprint $table) {
            $table->unsignedInteger('median_resolution_seconds')->nullable();
            $table->unsignedInteger('resolution_sample_count')->default(0);
            $table->timestamp('resolution_stats_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('glpi_category_maps', function (Blueprint $table) {
            $table->dropColumn(['median_resolution_seconds', 'resolution_sample_count', 'resolution_stats_at']);
        });

        Schema::table('ticket_attachments', function (Blueprint $table) {
            $table->dropColumn('glpi_document_id');
        });

        DB::table('support_tickets')->whereIn('status', ['needs_review', 'queued'])->update(['status' => 'pending']);

        Schema::table('support_tickets', function (Blueprint $table) {
            $table->string('status', 30)->default('pending')->change();
        });
    }
};
