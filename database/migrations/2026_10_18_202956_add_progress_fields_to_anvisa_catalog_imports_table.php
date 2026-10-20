<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('anvisa_catalog_imports', function (Blueprint $table): void {
            $table->foreignId('uploaded_by')->nullable()->after('source_id')->constrained('users')->nullOnDelete();
            $table->string('stored_path')->nullable()->after('delimiter');
            $table->string('error_reference', 36)->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('anvisa_catalog_imports', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('uploaded_by');
            $table->dropColumn(['stored_path', 'error_reference']);
        });
    }
};
