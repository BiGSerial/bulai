<?php

namespace App\Services\Reports\Types;

use App\Services\Reports\CsvWriter;
use App\Services\Reports\ReportType;
use Illuminate\Support\Facades\DB;

/** Inventário da base de conhecimento: um documento por linha, com origem, tipo, estados, trechos, vetores e princípios ativos ligados. */
final class KnowledgeInventoryReport implements ReportType
{
    public function key(): string
    {
        return 'knowledge_inventory';
    }

    public function label(): string
    {
        return 'Inventário da base de conhecimento';
    }

    public function description(): string
    {
        return 'Documentos cadastrados com origem, tipo, situação do PDF/texto/índice, trechos, vetores e princípios ativos vinculados.';
    }

    public function formats(): array
    {
        return ['csv'];
    }

    public function acceptsPeriod(): bool
    {
        return false;
    }

    public function build(string $format, array $parameters): array
    {
        $rows = DB::table('knowledge_documents as d')
            ->leftJoin('knowledge_sources as s', 's.id', '=', 'd.source_id')
            ->leftJoin(DB::raw('(select document_id, count(*) as chunks, count(embedding_model) as embedded from knowledge_chunks group by document_id) as c'), 'c.document_id', '=', 'd.id')
            ->leftJoin(DB::raw("(select kdi.document_id, string_agg(ai.canonical_name, '; ' order by ai.canonical_name) as ingredients from knowledge_document_ingredients kdi join active_ingredients ai on ai.id = kdi.ingredient_id group by kdi.document_id) as i"), 'i.document_id', '=', 'd.id')
            ->orderBy('d.id')
            ->get(['d.id', 'd.title', 'd.external_key', 'd.document_type', 's.name as source', 'd.language', 'd.file_status', 'd.content_status', 'd.embedding_status', 'c.chunks', 'c.embedded', 'i.ingredients', 'd.content_hash', 'd.processed_at', 'd.created_at'])
            ->map(fn (object $row): array => [
                'id' => $row->id, 'title' => $row->title, 'external_key' => $row->external_key, 'document_type' => $row->document_type, 'source' => $row->source,
                'language' => $row->language, 'file_status' => $row->file_status, 'content_status' => $row->content_status, 'embedding_status' => $row->embedding_status,
                'chunks' => (int) ($row->chunks ?? 0), 'chunks_embedded' => (int) ($row->embedded ?? 0), 'ingredients' => $row->ingredients,
                'content_hash' => $row->content_hash, 'processed_at' => $row->processed_at, 'created_at' => $row->created_at,
            ])->all();

        return CsvWriter::write(['id', 'title', 'external_key', 'document_type', 'source', 'language', 'file_status', 'content_status', 'embedding_status', 'chunks', 'chunks_embedded', 'ingredients', 'content_hash', 'processed_at', 'created_at'], $rows);
    }
}
