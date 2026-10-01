<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('knowledge_sources', function (Blueprint $table) {
            $table->string('collection')->nullable()->after('name');
            $table->string('country', 100)->nullable()->after('url');
            $table->string('normalized_name')->nullable()->after('name');
            $table->string('normalized_collection')->nullable()->after('collection');
        });

        $canonicalIds = [];
        DB::table('knowledge_sources')->orderBy('id')->each(function (object $source) use (&$canonicalIds): void {
            $collection = $source->organization;
            $normalizedName = $this->normalize((string) $source->name);
            $normalizedCollection = $collection === null ? null : $this->normalize((string) $collection);
            $identity = $normalizedName."\0".($normalizedCollection ?? '');

            if (isset($canonicalIds[$identity])) {
                DB::table('ingestion_batches')->where('source_id', $source->id)->update(['source_id' => $canonicalIds[$identity]]);
                DB::table('knowledge_documents')->where('source_id', $source->id)->update(['source_id' => $canonicalIds[$identity]]);
                DB::table('knowledge_sources')->where('id', $source->id)->delete();

                return;
            }

            $canonicalIds[$identity] = $source->id;
            DB::table('knowledge_sources')->where('id', $source->id)->update([
                'collection' => $collection,
                'normalized_name' => $normalizedName,
                'normalized_collection' => $normalizedCollection,
            ]);
        });

        Schema::table('knowledge_sources', function (Blueprint $table): void {
            $table->unique(['normalized_name', 'normalized_collection'], 'knowledge_sources_normalized_identity_unique');
            $table->index(['active', 'name']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('knowledge_sources', function (Blueprint $table) {
            $table->dropIndex(['active', 'name']);
            $table->dropUnique('knowledge_sources_normalized_identity_unique');
            $table->dropColumn(['collection', 'country', 'normalized_name', 'normalized_collection']);
        });
    }

    private function normalize(string $value): string
    {
        return Str::lower(Str::squish($value));
    }
};
