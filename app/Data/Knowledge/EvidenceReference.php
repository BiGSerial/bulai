<?php

namespace App\Data\Knowledge;

/** Trecho recuperado com tudo o que é necessário para citá-lo e auditá-lo (documento, seção, página, versão e score). */
final readonly class EvidenceReference
{
    public function __construct(
        public int $chunkId,
        public int $documentId,
        public string $documentTitle,
        public string $documentType,
        public ?string $sourceName,
        public ?string $section,
        public ?int $pageStart,
        public ?int $pageEnd,
        public string $excerpt,
        public float $score,
        public string $method,
        public ?string $documentVersion,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'chunk_id' => $this->chunkId,
            'document_id' => $this->documentId,
            'document_title' => $this->documentTitle,
            'document_type' => $this->documentType,
            'source' => $this->sourceName,
            'section' => $this->section,
            'page_start' => $this->pageStart,
            'page_end' => $this->pageEnd,
            'excerpt' => $this->excerpt,
            'score' => round($this->score, 6),
            'method' => $this->method,
            'document_version' => $this->documentVersion,
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            (int) $data['chunk_id'], (int) $data['document_id'], (string) $data['document_title'], (string) $data['document_type'],
            $data['source'] ?? null, $data['section'] ?? null, $data['page_start'] ?? null, $data['page_end'] ?? null,
            (string) $data['excerpt'], (float) $data['score'], (string) $data['method'], $data['document_version'] ?? null,
        );
    }
}
