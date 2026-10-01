<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('knowledge_documents', function (Blueprint $table): void {
            $table->string('storage_disk')->nullable()->after('storage_path');
            $table->string('storage_key')->nullable()->after('storage_disk');
        });

        Schema::table('ingestion_batch_items', function (Blueprint $table): void {
            $table->string('storage_disk')->nullable()->after('storage_path');
            $table->string('storage_key')->nullable()->after('storage_disk');
        });

        DB::table('knowledge_documents')->whereNotNull('storage_path')->update([
            'storage_disk' => 'knowledge',
            'storage_key' => DB::raw('storage_path'),
        ]);
        DB::table('ingestion_batch_items')->whereNotNull('storage_path')->update([
            'storage_disk' => 'knowledge',
            'storage_key' => DB::raw('storage_path'),
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ingestion_batch_items', function (Blueprint $table): void {
            $table->dropColumn(['storage_disk', 'storage_key']);
        });

        Schema::table('knowledge_documents', function (Blueprint $table): void {
            $table->dropColumn(['storage_disk', 'storage_key']);
        });
    }
};
