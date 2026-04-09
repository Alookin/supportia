<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ticket_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('support_ticket_id')->constrained()->cascadeOnDelete();
            $table->string('filename');         // Nom de stockage (UUID.ext) — jamais le nom original
            $table->string('original_name');    // Nom d'origine affiché à l'utilisateur
            $table->string('mime_type', 100);
            $table->unsignedBigInteger('size'); // Taille en octets
            $table->string('path');             // Chemin relatif dans le disk 'local' (private)
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_attachments');
    }
};
