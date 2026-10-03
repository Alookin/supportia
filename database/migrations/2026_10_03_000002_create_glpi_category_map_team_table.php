<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Catégories par équipe.
 * Une catégorie sans aucune équipe rattachée est commune à toutes les équipes
 * (comportement actuel : rien ne change pour les catégories existantes).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('glpi_category_map_team', function (Blueprint $table) {
            $table->id();
            $table->foreignId('glpi_category_map_id')->constrained()->cascadeOnDelete();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->unique(['glpi_category_map_id', 'team_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('glpi_category_map_team');
    }
};
