<?php

namespace App\Services\Knowledge\External;

/** Fonte de bulas estrangeiras: devolve as bulas candidatas de um princípio ativo (nome genérico em inglês), normalizadas. */
interface LabelSource
{
    /**
     * Só bulas de prescrição para uso humano.
     *
     * @return list<array{set_id: string, version: int|null, effective_date: string|null, title: string, labeler: string|null, substances: list<string>, unii: list<string>, sections: array<string, string>}>
     */
    public function candidates(string $genericName, int $limit): array;
}
