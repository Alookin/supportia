<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('glpi_category_maps', function (Blueprint $table) {
            $table->boolean('is_visible_to_users')->default(true)->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('glpi_category_maps', function (Blueprint $table) {
            $table->dropColumn('is_visible_to_users');
        });
    }
};
