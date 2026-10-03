<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('teams', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('slug', 100);
            $table->timestamps();

            $table->unique(['organization_id', 'slug']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('team_id')->nullable()->after('organization_id')->constrained()->nullOnDelete();
            $table->string('role', 20)->default('member')->after('team_id');
        });

        // Équipe à la création du ticket (figée : un changement d'équipe ne déplace pas l'historique)
        Schema::table('support_tickets', function (Blueprint $table) {
            $table->foreignId('team_id')->nullable()->after('user_id')->constrained()->nullOnDelete();
            $table->index(['organization_id', 'team_id']);
        });
    }

    public function down(): void
    {
        Schema::table('support_tickets', function (Blueprint $table) {
            $table->dropIndex(['organization_id', 'team_id']);
            $table->dropConstrainedForeignId('team_id');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('team_id');
            $table->dropColumn('role');
        });

        Schema::dropIfExists('teams');
    }
};
