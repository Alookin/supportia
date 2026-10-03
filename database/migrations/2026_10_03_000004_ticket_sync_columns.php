<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Synchronisation périodique avec GLPI :
 * - glpi_category_id_final : catégorie dans GLPI (éventuellement corrigée par le technicien)
 *   → mesure de la précision de l'IA ;
 * - resolved_notified_at : le demandeur a été prévenu de la résolution ;
 * - glpi_synced_at : dernière synchro.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('support_tickets', function (Blueprint $table) {
            $table->unsignedInteger('glpi_category_id_final')->nullable();
            $table->timestamp('resolved_notified_at')->nullable();
            $table->timestamp('glpi_synced_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('support_tickets', function (Blueprint $table) {
            $table->dropColumn(['glpi_category_id_final', 'resolved_notified_at', 'glpi_synced_at']);
        });
    }
};
