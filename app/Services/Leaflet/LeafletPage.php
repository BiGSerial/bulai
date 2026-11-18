<?php

namespace App\Services\Leaflet;

use App\Models\Consultation\MedicineLeafletSummary;
use App\Models\Knowledge\AnvisaMedicine;
use App\Models\User;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Página da bula humanizada, compartilhada pela tela do site (Livewire `Consulta\Bula`) e pelo aplicativo nativo
 * (`/api/mobile/v1/bulas/{medicine}`): em que estado a página está, a geração com limite por hora, as seções com as
 * fontes que as sustentam (sem trechos brutos) e o estilo de cada seção.
 */
final class LeafletPage
{
    public const NOT_IDENTIFIED = 'nao_identificado';

    public const NO_LEAFLET = 'sem_bula';

    public const LOADING = 'carregando';

    public const RATE_LIMITED = 'limite';

    public const READY = 'pronto';

    public function __construct(private LeafletSummaryService $summaries) {}

    /**
     * Ao abrir: o resumo pronto (do cache), ou `carregando` quando ainda precisa gerar, ou por que não há bula.
     *
     * @return array{state: string, summary: MedicineLeafletSummary|null}
     */
    public function open(AnvisaMedicine $medicine): array
    {
        // Bulas pelo princípio ativo ligado (ligando o catálogo na hora se preciso) ou pelo nome; sem nada, explica qual é o caso.
        if (! $this->summaries->isAvailable($medicine)) {
            return ['state' => $medicine->ingredients()->exists() ? self::NO_LEAFLET : self::NOT_IDENTIFIED, 'summary' => null];
        }
        $cached = $this->summaries->cached($medicine);
        if ($cached === null) {
            return ['state' => self::LOADING, 'summary' => null];
        }
        $cached->increment('views');

        return ['state' => self::READY, 'summary' => $cached];
    }

    /**
     * Gera (ou reaproveita) o resumo; gerar um novo conta no limite por hora da pessoa.
     *
     * @return array{state: string, summary: MedicineLeafletSummary|null}
     */
    public function generate(User $user, AnvisaMedicine $medicine): array
    {
        $key = 'bula:'.$user->id;
        if ($this->summaries->cached($medicine) === null && RateLimiter::tooManyAttempts($key, (int) config('bulai.consultation.rate_limit_per_hour'))) {
            return ['state' => self::RATE_LIMITED, 'summary' => null];
        }
        RateLimiter::hit($key, 3600);

        $summary = $this->summaries->forMedicine($medicine);

        return $summary === null ? ['state' => self::NO_LEAFLET, 'summary' => null] : ['state' => self::READY, 'summary' => $summary];
    }

    /**
     * Seções com afirmações válidas e as fontes (documento, seção, página) que as sustentam, sem trechos brutos.
     *
     * @return array{com: list<array{key: string, label: string, claims: list<string>, sources: list<string>}>, sem: list<string>}
     */
    public static function sections(MedicineLeafletSummary $summary): array
    {
        $byChunk = [];
        foreach ($summary->evidences ?? [] as $evidence) {
            $byChunk[$evidence['chunk_id']] = $evidence['document_title'].' · '.($evidence['section'] ?? 'seção não identificada').' · p. '.($evidence['page_start'] ?? '?');
        }

        $com = [];
        $sem = [];
        // Ordem das seções vem da configuração (o jsonb do PostgreSQL não preserva a ordem das chaves).
        foreach (array_keys((array) config('bulai.leaflet.sections')) as $key) {
            $section = $summary->sections[$key] ?? null;
            if ($section === null) {
                continue;
            }
            if ($section['claims'] === []) {
                $sem[] = $section['label'];

                continue;
            }
            $sources = [];
            foreach ($section['claims'] as $claim) {
                foreach ($claim['evidence_chunk_ids'] as $chunkId) {
                    $sources[] = $byChunk[$chunkId] ?? 'Bula cadastrada';
                }
            }
            $com[] = ['key' => $key, 'label' => $section['label'], 'claims' => array_column($section['claims'], 'text'), 'sources' => array_values(array_unique($sources))];
        }

        return ['com' => $com, 'sem' => $sem];
    }

    /**
     * Tom e ícone de cada seção ("Para que serve" fica na capa).
     *
     * @return array{0: string, 1: string}
     */
    public static function style(string $key): array
    {
        return match ($key) {
            'para_que_serve' => ['brand', 'info'],
            'beneficios' => ['success', 'check'],
            'riscos', 'interacoes' => ['warning', 'alerta'],
            'nao_use_se', 'procure_ajuda' => ['danger', 'alerta'],
            'efeitos_colaterais' => ['warning', 'coracao'],
            'como_usar' => ['brand', 'relogio'],
            default => ['brand', 'bula'],
        };
    }

    /** Detentor do registro sem o CNPJ do começo. */
    public static function holder(AnvisaMedicine $medicine): string
    {
        return trim((string) preg_replace('/^\d{14}\s*-\s*/', '', (string) $medicine->registration_holder));
    }

    /** "Baseado na bula oficial…": quando e de quais bulas o resumo foi preparado. */
    public static function preparedNote(MedicineLeafletSummary $summary): string
    {
        return 'Baseado na bula oficial, conferido automaticamente. Preparado em '.$summary->created_at->timezone(config('app.timezone'))->format('d/m/Y')
            .' a partir de '.count($summary->pipeline['documents'] ?? []).' bula(s) ('.(($summary->pipeline['document_type'] ?? '') === 'bula_paciente' ? 'do paciente' : 'do profissional').').';
    }
}
