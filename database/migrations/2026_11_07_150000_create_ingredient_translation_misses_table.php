<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Nomes de princípios ativos que a tradução por IA já tentou e que não existem no DDInter (ex.: dipirona, bromoprida).
 * Sem este registro eles voltavam a ser enviados à IA em toda execução e, com o teto de nomes por execução, os que
 * ainda não foram tentados nunca eram alcançados.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ingredient_translation_misses', function (Blueprint $table): void {
            $table->id();
            $table->string('normalized_name', 200)->unique();
            $table->string('proposed', 200)->nullable();
            $table->unsignedSmallInteger('attempts')->default(1);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ingredient_translation_misses');
    }
};
