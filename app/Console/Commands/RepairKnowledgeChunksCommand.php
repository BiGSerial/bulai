<?php

namespace App\Console\Commands;

use App\Services\Knowledge\ChunkCleaner;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

#[Signature('knowledge:repair-chunks {--document= : Só este documento} {--dry-run : Mostra o que mudaria sem gravar}')]
#[Description('Limpa os trechos já indexados (rodapés, repetições, fragmentos de título), renumera e preenche token_count, preservando o id dos trechos mantidos')]
class RepairKnowledgeChunksCommand extends Command
{
    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $documents = DB::table('knowledge_chunks')->when($this->option('document'), fn ($query, $id) => $query->where('document_id', (int) $id))->distinct()->orderBy('document_id')->pluck('document_id');
        $totals = ['documents' => 0, 'dropped' => 0, 'merged' => 0, 'changed' => 0, 'tokens' => 0, 'renumbered' => 0];

        foreach ($documents as $documentId) {
            $rows = DB::table('knowledge_chunks')->where('document_id', $documentId)->orderBy('chunk_index')->get(['id', 'chunk_index', 'content', 'page_start', 'page_end', 'token_count']);
            $cleaned = ChunkCleaner::clean($rows->map(fn (object $row): array => ['content' => (string) $row->content, 'page_start' => (int) ($row->page_start ?? 1), 'page_end' => (int) ($row->page_end ?? $row->page_start ?? 1)])->all());
            $removed = array_merge($cleaned['dropped'], ...array_column($cleaned['kept'], 'merged'));
            $changed = array_filter($cleaned['kept'], fn (array $entry): bool => $entry['changed']);
            $totals['documents']++;
            $totals['dropped'] += count($cleaned['dropped']);
            $totals['merged'] += count($removed) - count($cleaned['dropped']);
            $totals['changed'] += count($changed);

            if ($dryRun) {
                continue;
            }

            DB::transaction(function () use ($documentId, $rows, $cleaned, $removed, $changed, &$totals): void {
                if ($removed !== []) {
                    DB::table('knowledge_chunks')->whereIn('id', array_map(fn (int $position): int => (int) $rows[$position]->id, $removed))->delete();
                }
                foreach ($changed as $entry) {
                    DB::table('knowledge_chunks')->where('id', $rows[$entry['position']]->id)->update(['content' => $entry['content'], 'page_start' => $entry['page_start'], 'page_end' => $entry['page_end'], 'updated_at' => now()]);
                }
                // O índice único (documento, chunk_index) impede renumerar direto: passa por valores negativos.
                $kept = array_map(fn (array $entry): object => $rows[$entry['position']], $cleaned['kept']);
                foreach ($kept as $index => $row) {
                    DB::table('knowledge_chunks')->where('id', $row->id)->update(['chunk_index' => -($index + 1)]);
                }
                foreach ($cleaned['kept'] as $index => $entry) {
                    $row = $rows[$entry['position']];
                    DB::table('knowledge_chunks')->where('id', $row->id)->update(['chunk_index' => $index, 'token_count' => ChunkCleaner::estimateTokens($entry['content'])]);
                    $totals['tokens']++;
                    $totals['renumbered'] += $row->chunk_index !== $index ? 1 : 0;
                }
                if ($changed !== []) {
                    DB::table('knowledge_documents')->where('id', $documentId)->update(['embedding_status' => 'PENDING']);
                }
            });
        }

        $this->table(['Documentos', 'Descartados', 'Fundidos', 'Trechos alterados', 'token_count gravado', 'Renumerados'], [array_values($totals)]);
        $this->components->info($dryRun ? 'Simulação: nada foi gravado.' : 'Pronto. Rode `php artisan knowledge:index-embeddings` para reindexar os trechos alterados (o hash do texto mudou).');

        return self::SUCCESS;
    }
}
