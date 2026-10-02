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
        Schema::table('ingestion_batches', function (Blueprint $table) {
            $table->unsignedInteger('ignored_items')->default(0)->after('error_items');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ingestion_batches', function (Blueprint $table) {
            $table->dropColumn('ignored_items');
        });
    }
};
