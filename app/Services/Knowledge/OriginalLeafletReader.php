<?php

namespace App\Services\Knowledge;

use App\Data\Knowledge\DocumentExtractionResult;
use App\Models\Knowledge\KnowledgeDocument;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Relê o PDF original de uma bula, guardado no armazenamento privado, quando o texto indexado (chunks) não basta para
 * responder e há sinal de que o índice está incompleto ou foi extraído com baixa qualidade. Usa a mesma extração do
 * processamento (`DocumentTextExtractionService`: texto nativo e, se ele não for confiável, OCR) e devolve o texto por página.
 *
 * O PDF nunca sai do servidor: é copiado para um arquivo temporário, lido e apagado. O texto extraído fica em cache pelo
 * hash do arquivo (o mesmo PDF não é relido nem passa por OCR de novo), e uma trava por documento impede leituras simultâneas.
 */
final class OriginalLeafletReader
{
    private const CACHE_SECONDS = 86_400;

    private const LOCK_SECONDS = 180;

    public function __construct(
        private readonly KnowledgeFileService $files,
        private readonly DocumentTextExtractionService $extraction,
    ) {}

    /**
     * @return array{status: string, reason?: string, pages: list<array{number: int, text: string}>, method: string|null, quality: float|null, reliable: bool, cached: bool}
     */
    public function read(KnowledgeDocument $document): array
    {
        $storage = $this->files->documentStorage($document);
        if ($storage === null || $document->sha256 === null) {
            return $this->unavailable('NO_ORIGINAL_FILE');
        }
        $key = 'bulai:original-leaflet:'.$document->sha256;
        $cached = Cache::get($key);
        if (is_array($cached)) {
            return ['cached' => true] + $cached;
        }

        try {
            return Cache::lock($key.':lock', self::LOCK_SECONDS)->block(30, function () use ($key, $storage): array {
                $cached = Cache::get($key);
                if (is_array($cached)) {
                    return ['cached' => true] + $cached;
                }
                $result = $this->files->withLocalCopy($storage['disk'], $storage['key'], fn (string $path): DocumentExtractionResult => $this->extraction->extract($path));
                $quality = $result->method === DocumentExtractionResult::METHOD_OCR ? ($result->ocrQuality ?? $result->quality) : $result->quality;
                $read = [
                    'status' => 'ok',
                    'pages' => array_map(fn ($page): array => ['number' => $page->number, 'text' => $page->text], $result->document->pages),
                    'method' => $result->method,
                    'quality' => $quality->score,
                    'reliable' => $quality->isAcceptable,
                ];
                Cache::put($key, $read, self::CACHE_SECONDS);

                return ['cached' => false] + $read;
            });
        } catch (Throwable $exception) {
            Log::warning('original_leaflet_unavailable', ['document_id' => $document->id, 'error' => $exception->getMessage()]);

            return $this->unavailable('ORIGINAL_READ_FAILED');
        }
    }

    /** @return array{status: string, reason: string, pages: list<array{number: int, text: string}>, method: null, quality: null, reliable: false, cached: false} */
    private function unavailable(string $reason): array
    {
        return ['status' => 'unavailable', 'reason' => $reason, 'pages' => [], 'method' => null, 'quality' => null, 'reliable' => false, 'cached' => false];
    }
}
