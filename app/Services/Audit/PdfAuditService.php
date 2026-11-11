<?php

namespace App\Services\Audit;

use App\Models\Knowledge\KnowledgeDocument;
use App\Services\Evaluation\Metrics;
use App\Services\Knowledge\KnowledgeFileService;
use App\Services\Knowledge\OriginalLeafletReader;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Lê os PDFs guardados no armazenamento privado e os confronta com o que foi indexado: o arquivo existe e tem o hash
 * gravado, quantas páginas e quanto texto têm, se a extração foi nativa ou por OCR e com que qualidade, e quanto do texto de
 * cada trecho (chunk) realmente aparece nas páginas que ele declara. O PDF nunca sai do servidor; a resposta só traz texto se
 * pedido (`include_text`), por página e truncado.
 */
final class PdfAuditService
{
    private const PAGE_TEXT_LIMIT = 6_000;

    public function __construct(private readonly KnowledgeFileService $files, private readonly OriginalLeafletReader $reader) {}

    /** @return list<array<string, mixed>> */
    public function documents(): array
    {
        $chunks = DB::table('knowledge_chunks')->selectRaw('document_id, count(*) as chunks, max(page_end) as last_page')->groupBy('document_id')->get()->keyBy('document_id');

        return KnowledgeDocument::query()->orderBy('id')->get()->map(function (KnowledgeDocument $document) use ($chunks): array {
            $storage = $this->files->documentStorage($document);
            $exists = false;
            $size = null;
            try {
                $exists = $storage !== null && $this->files->objectExists($storage['disk'], $storage['key']);
                $size = $exists ? $this->files->objectSize($storage['disk'], $storage['key']) : null;
            } catch (Throwable) {
            }

            return [
                'id' => $document->id,
                'title' => $document->title,
                'type' => $document->document_type,
                'content_status' => $document->content_status,
                'pdf_stored' => $exists,
                'size_bytes' => $size,
                'size_matches_record' => $size !== null && $document->file_size !== null ? $size === (int) $document->file_size : null,
                'sha256' => $document->sha256,
                'chunks' => (int) ($chunks[$document->id]->chunks ?? 0),
                'last_chunk_page' => isset($chunks[$document->id]->last_page) ? (int) $chunks[$document->id]->last_page : null,
            ];
        })->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function inspect(KnowledgeDocument $document, bool $includeText = false, ?int $page = null): array
    {
        $storage = $this->files->documentStorage($document);
        if ($storage === null) {
            return ['status' => 'unavailable', 'reason' => 'NO_ORIGINAL_FILE', 'document' => $this->identity($document)];
        }

        $fileHashMatches = null;
        try {
            $fileHashMatches = $document->sha256 === null ? null : $this->files->hashObject($storage['disk'], $storage['key']) === $document->sha256;
        } catch (Throwable) {
            return ['status' => 'unavailable', 'reason' => 'DOCUMENT_FILE_MISSING', 'document' => $this->identity($document)];
        }

        $read = $this->reader->read($document);
        if ($read['status'] !== 'ok') {
            return ['status' => 'unavailable', 'reason' => $read['reason'] ?? 'ORIGINAL_READ_FAILED', 'document' => $this->identity($document), 'file_hash_matches' => $fileHashMatches];
        }

        $pages = $read['pages'];
        $pageCount = count($pages);
        $report = [
            'status' => 'ok',
            'document' => $this->identity($document),
            'file_hash_matches' => $fileHashMatches,
            'extraction' => ['method' => $read['method'], 'quality' => $read['quality'], 'reliable' => $read['reliable'], 'cached' => $read['cached']],
            'pages' => [
                'count' => $pageCount,
                'characters' => array_sum(array_map(fn (array $item): int => mb_strlen($item['text']), $pages)),
                'empty' => array_values(array_map(fn (array $item): int => $item['number'], array_filter($pages, fn (array $item): bool => mb_strlen(trim($item['text'])) < 20))),
                'characters_by_page' => array_map(fn (array $item): array => ['number' => $item['number'], 'characters' => mb_strlen($item['text'])], $pages),
            ],
            'index_coverage' => $this->coverage($document, $pages),
        ];

        if ($includeText) {
            $selected = $page === null ? $pages : array_values(array_filter($pages, fn (array $item): bool => $item['number'] === $page));
            $report['text'] = array_map(fn (array $item): array => ['number' => $item['number'], 'text' => mb_substr($item['text'], 0, self::PAGE_TEXT_LIMIT), 'truncated' => mb_strlen($item['text']) > self::PAGE_TEXT_LIMIT], $selected);
        }

        return $report;
    }

    /** @return array{id: int, title: string, type: string, sha256: string|null} */
    private function identity(KnowledgeDocument $document): array
    {
        return ['id' => $document->id, 'title' => $document->title, 'type' => $document->document_type, 'sha256' => $document->sha256];
    }

    /**
     * Para cada chunk: fração das palavras (4+ letras) que aparece no texto das páginas declaradas pelo chunk (com uma de folga).
     *
     * @param  list<array{number: int, text: string}>  $pages
     * @return array<string, mixed>
     */
    private function coverage(KnowledgeDocument $document, array $pages): array
    {
        $byPage = [];
        foreach ($pages as $item) {
            $byPage[$item['number']] = $this->words($item['text']);
        }
        $all = array_merge(...array_values($byPage ?: [[]]));
        $allSet = array_flip($all);
        $maxPage = $byPage === [] ? 0 : max(array_keys($byPage));

        $scores = [];
        $weak = [];
        $outOfRange = 0;
        $chunkCharacters = 0;
        $chunks = DB::table('knowledge_chunks')->where('document_id', $document->id)->orderBy('chunk_index')->get(['id', 'chunk_index', 'page_start', 'page_end', 'content']);
        foreach ($chunks as $chunk) {
            $chunkCharacters += mb_strlen((string) $chunk->content);
            $start = (int) ($chunk->page_start ?? 0);
            $end = (int) ($chunk->page_end ?? $start);
            if ($start < 1 || $end > $maxPage) {
                $outOfRange++;
            }
            $words = array_unique($this->words((string) $chunk->content));
            if ($words === []) {
                continue;
            }
            $declared = [];
            for ($number = max(1, $start - 1); $number <= $end + 1; $number++) {
                $declared += array_flip($byPage[$number] ?? []);
            }
            $inPages = count(array_filter($words, fn (string $word): bool => isset($declared[$word]))) / count($words);
            $inDocument = count(array_filter($words, fn (string $word): bool => isset($allSet[$word]))) / count($words);
            $scores[] = ['page' => $inPages, 'document' => $inDocument];
            if ($inPages < 0.8) {
                $weak[] = (int) $chunk->id;
            }
        }
        $pdfCharacters = array_sum(array_map(fn (array $item): int => mb_strlen($item['text']), $pages));

        return [
            'chunks' => count($chunks),
            'chunks_scored' => count($scores),
            'words_in_declared_pages_mean' => $scores === [] ? null : round(array_sum(array_column($scores, 'page')) / count($scores), 4),
            'words_in_document_mean' => $scores === [] ? null : round(array_sum(array_column($scores, 'document')) / count($scores), 4),
            'chunks_below_80_percent_in_declared_pages' => count($weak),
            'chunks_with_page_out_of_range' => $outOfRange,
            'chunks_at_least_80_percent_in_declared_pages' => Metrics::wilson(count($scores) - count($weak), count($scores)),
            'weak_chunk_ids' => array_slice($weak, 0, 10),
            'indexed_characters' => $chunkCharacters,
            'pdf_characters' => $pdfCharacters,
            'indexed_to_pdf_ratio' => $pdfCharacters > 0 ? round($chunkCharacters / $pdfCharacters, 3) : null,
        ];
    }

    /** @return list<string> */
    private function words(string $text): array
    {
        preg_match_all('/\p{L}{4,}/u', mb_strtolower($text), $matches);

        return $matches[0];
    }
}
