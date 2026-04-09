<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Lier une pièce jointe à un commentaire spécifique
        // (nullable : les PJ de ticket sans commentaire restent NULL)
        Schema::table('ticket_attachments', function (Blueprint $table) {
            $table->foreignId('ticket_comment_id')
                ->nullable()
                ->after('support_ticket_id')
                ->constrained('ticket_comments')
                ->nullOnDelete();
        });

        // Autoriser un commentaire sans texte (cas : fichier seul)
        Schema::table('ticket_comments', function (Blueprint $table) {
            $table->text('content')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('ticket_comments', function (Blueprint $table) {
            $table->text('content')->nullable(false)->change();
        });

        Schema::table('ticket_attachments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('ticket_comment_id');
        });
    }
};
