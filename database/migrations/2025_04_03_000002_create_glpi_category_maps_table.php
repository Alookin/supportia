<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('glpi_category_maps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->integer('glpi_category_id');
            $table->string('slug', 100);
            $table->string('label');
            $table->text('description')->nullable();     // Description longue pour le LLM
            $table->jsonb('keywords')->default('[]');     // Mots-clés pour le fallback
            $table->string('parent_slug', 100)->nullable();
            $table->integer('glpi_entity_id')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['organization_id', 'slug']);
            $table->index(['organization_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('glpi_category_maps');
    }
};
