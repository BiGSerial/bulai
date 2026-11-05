<?php

namespace App\Console\Commands;

use App\Services\Ai\AiProviderManager;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

#[Signature('bulai:pipeline-check {--json : Saída em JSON}')]
#[Description('Verifica se o pipeline de consulta tem dados e provedores prontos (catálogo, ingredientes, interações, bulas, embeddings, IA)')]
class CheckPipelineCommand extends Command
{
    public function handle(AiProviderManager $providers): int
    {
        $count = fn (string $table, ?string $where = null): int => (int) DB::table($table)->when($where, fn ($query) => $query->whereRaw($where))->count();

        $checks = [
            ['Medicamentos no catálogo ANVISA', $count('anvisa_medicines'), $count('anvisa_medicines') > 0],
            ['Medicamentos ligados a princípio ativo', $count('anvisa_medicine_ingredients') > 0 ? (int) DB::table('anvisa_medicine_ingredients')->distinct()->count('medicine_id') : 0, $count('anvisa_medicine_ingredients') > 0],
            ['Princípios ativos', $count('active_ingredients'), $count('active_ingredients') > 0],
            ['Interações (DDInter)', $count('drug_interactions'), $count('drug_interactions') > 0],
            ['Interações com os dois ativos resolvidos', $count('drug_interactions', 'drug_a_ingredient_id is not null and drug_b_ingredient_id is not null'), $count('drug_interactions', 'drug_a_ingredient_id is not null and drug_b_ingredient_id is not null') > 0],
            ['Bulas prontas (READY)', $count('knowledge_documents', "content_status = 'READY'"), $count('knowledge_documents', "content_status = 'READY'") > 0],
            ['Bulas ligadas a princípio ativo', (int) DB::table('knowledge_document_ingredients')->distinct()->count('document_id'), $count('knowledge_document_ingredients') > 0],
            ['Chunks com embedding', $count('knowledge_chunks', 'embedding_model is not null'), true],
            ['Provedor de texto configurado', $providers->isConfigured('text') ? 1 : 0, true],
            ['Provedor de embeddings configurado', $providers->isConfigured('embedding') ? 1 : 0, true],
        ];

        $required = array_filter($checks, fn (array $check): bool => ! $check[2]);
        if ($this->option('json')) {
            $this->line((string) json_encode(array_map(fn (array $check): array => ['check' => $check[0], 'value' => $check[1], 'ok' => $check[2]], $checks), JSON_UNESCAPED_UNICODE));
        } else {
            $this->table(['Verificação', 'Valor', 'Situação'], array_map(fn (array $check): array => [$check[0], $check[1], $check[2] ? 'ok' : 'FALTA'], $checks));
            $this->components->info('Provedor de texto: '.(config('ai.text.default') ?: 'não configurado').' | embeddings: '.(config('ai.embedding.default') ?: 'não configurado').' | recuperação: '.config('bulai.retrieval.mode').' | execução: '.config('bulai.consultation.execution'));
        }

        return $required === [] ? self::SUCCESS : self::FAILURE;
    }
}
