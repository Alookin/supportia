<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organizations', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug', 100)->unique();

            // Connexion GLPI
            $table->string('glpi_api_url', 500)->nullable();
            $table->text('glpi_app_token')->nullable();   // chiffré via cast
            $table->text('glpi_user_token')->nullable();   // chiffré via cast

            // Optionnel : clé Claude propre au client (sinon clé globale)
            $table->text('claude_api_key')->nullable();

            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // Ajouter organization_id à la table users (créée par Breeze)
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('organization_id')
                  ->nullable()
                  ->constrained()
                  ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('organization_id');
        });
        Schema::dropIfExists('organizations');
    }
};
