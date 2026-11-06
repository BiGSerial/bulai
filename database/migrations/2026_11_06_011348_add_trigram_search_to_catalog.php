<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Busca tolerante a erros de digitação no catálogo (pg_trgm): "losartam" encontra Losartana, "omeprasol" encontra
 * Omeprazol. Índices de trigramas sobre os nomes normalizados e sobre a primeira palavra do nome comercial (é ela que o
 * paciente costuma digitar). A extensão vem com o PostgreSQL (contrib) e está na imagem pgvector usada em produção.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');
        DB::statement('CREATE INDEX IF NOT EXISTS anvisa_medicines_product_name_trgm ON anvisa_medicines USING gin (normalized_product_name gin_trgm_ops)');
        DB::statement("CREATE INDEX IF NOT EXISTS anvisa_medicines_product_first_word_trgm ON anvisa_medicines USING gin (split_part(normalized_product_name, ' ', 1) gin_trgm_ops)");
        DB::statement('CREATE INDEX IF NOT EXISTS anvisa_medicines_active_ingredient_trgm ON anvisa_medicines USING gin (normalized_active_ingredient gin_trgm_ops)');
        DB::statement("CREATE INDEX IF NOT EXISTS anvisa_medicines_ingredient_first_word_trgm ON anvisa_medicines USING gin (split_part(coalesce(normalized_active_ingredient, ''), ' ', 1) gin_trgm_ops)");
        DB::statement('CREATE INDEX IF NOT EXISTS active_ingredients_normalized_name_trgm ON active_ingredients USING gin (normalized_name gin_trgm_ops)');
        DB::statement('CREATE INDEX IF NOT EXISTS active_ingredient_aliases_normalized_alias_trgm ON active_ingredient_aliases USING gin (normalized_alias gin_trgm_ops)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS active_ingredient_aliases_normalized_alias_trgm');
        DB::statement('DROP INDEX IF EXISTS active_ingredients_normalized_name_trgm');
        DB::statement('DROP INDEX IF EXISTS anvisa_medicines_ingredient_first_word_trgm');
        DB::statement('DROP INDEX IF EXISTS anvisa_medicines_active_ingredient_trgm');
        DB::statement('DROP INDEX IF EXISTS anvisa_medicines_product_first_word_trgm');
        DB::statement('DROP INDEX IF EXISTS anvisa_medicines_product_name_trgm');
    }
};
