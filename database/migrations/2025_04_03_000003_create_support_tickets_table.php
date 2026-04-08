<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('support_tickets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete(); // commercial

            // Client
            $table->string('client_identifier')->nullable(); // ID client dans le SI de l'orga
            $table->string('client_name')->nullable();

            // Saisie brute
            $table->text('raw_description');

            // Résultat IA
            $table->string('ai_title', 500)->nullable();
            $table->text('ai_body')->nullable();
            $table->string('ai_category_slug', 100)->nullable();
            $table->smallInteger('ai_priority')->nullable();
            $table->float('ai_confidence')->nullable();
            $table->string('ai_provider', 50)->nullable(); // claude, fallback_keywords, manual

            // GLPI
            $table->integer('glpi_ticket_id')->nullable();
            $table->string('glpi_status', 50)->nullable();
            $table->timestamp('glpi_created_at')->nullable();
            $table->smallInteger('glpi_retry_count')->default(0);
            $table->text('glpi_last_error')->nullable();

            // Suivi
            $table->boolean('was_modified_by_user')->default(false);
            $table->string('status', 30)->default('pending');
            // pending → created → failed → retry

            $table->timestamps();

            $table->index(['organization_id', 'status']);
            $table->index(['organization_id', 'client_identifier', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('support_tickets');
    }
};
