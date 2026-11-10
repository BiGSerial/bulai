<?php

namespace App\Services\Knowledge\External;

use App\Models\Knowledge\ActiveIngredient;
use App\Models\Knowledge\ExternalLabel;
use App\Models\Knowledge\ExternalLabelSection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Importa a bula de prescrição da FDA de um princípio ativo, pelo openFDA (principal) ou pela DailyMed (reserva): só bula de prescrição para uso humano, de
 * produto com um único princípio ativo igual ao nosso (pelo nome em inglês do catálogo), a mais recente com texto real de
 * interações. Guarda a origem, as seções de interações e de reações adversas em trechos, o original e a tradução conferida.
 */
final class ExternalLabelImporter
{
    /** Texto mínimo absoluto da seção de interações para a bula contar como "com texto". */
    private const MIN_INTERACTION_CHARS = 400;

    public function __construct(
        private readonly OpenFdaLabelClient $openFda,
        private readonly DailyMedLabelClient $dailyMed,
        private readonly LabelTranslator $translator,
    ) {}

    public function import(ActiveIngredient $ingredient, bool $translate = true): ExternalLabel
    {
        $names = $this->englishNames($ingredient);
        $chosen = null;
        $searched = null;
        $via = null;
        $errors = [];
        // Redundância: o openFDA é a fonte principal; a DailyMed (mesmas bulas, outra API) entra quando ele falha ou não tem
        // bula aceitável para o princípio ativo.
        foreach ([ExternalLabel::VIA_OPENFDA => [$this->openFda, 'openfda'], ExternalLabel::VIA_DAILYMED => [$this->dailyMed, 'dailymed']] as $source => [$client, $config]) {
            try {
                foreach ($names as $name) {
                    $searched = $name;
                    $chosen = $this->choose($client->candidates($name, (int) config('bulai.external_labels.'.$config.'.max_candidates')), $name);
                    if ($chosen !== null) {
                        $via = $source;
                        break 2;
                    }
                }
            } catch (Throwable $exception) {
                $errors[] = $source.': '.$exception->getMessage();
                Log::warning('external_label_source_failed', ['ingredient_id' => $ingredient->id, 'source' => $source, 'error' => $exception->getMessage()]);
            }
        }
        if ($chosen === null && count($errors) === 2) {
            return ExternalLabel::query()->updateOrCreate(['source' => ExternalLabel::SOURCE_FDA, 'ingredient_id' => $ingredient->id], ['status' => ExternalLabel::STATUS_FAILED, 'retrieved_via' => null, 'searched_name' => $searched, 'error' => Str::limit(implode(' | ', $errors), 280), 'fetched_at' => now()]);
        }

        if ($chosen === null) {
            return ExternalLabel::query()->updateOrCreate(['source' => ExternalLabel::SOURCE_FDA, 'ingredient_id' => $ingredient->id], ['status' => ExternalLabel::STATUS_NOT_FOUND, 'retrieved_via' => null, 'searched_name' => $searched, 'error' => $errors !== [] ? Str::limit(implode(' | ', $errors), 280) : null, 'fetched_at' => now()]);
        }

        $label = DB::transaction(function () use ($ingredient, $chosen, $searched, $via): ExternalLabel {
            $label = ExternalLabel::query()->firstOrNew(['source' => ExternalLabel::SOURCE_FDA, 'ingredient_id' => $ingredient->id]);
            $changed = $label->set_id !== $chosen['set_id'] || $label->spl_version !== $chosen['version'];
            $label->fill([
                'status' => ExternalLabel::STATUS_READY, 'retrieved_via' => $via, 'searched_name' => $searched, 'set_id' => $chosen['set_id'], 'spl_version' => $chosen['version'],
                'title' => Str::limit($chosen['title'] !== '' ? $chosen['title'] : mb_strtoupper((string) $searched), 480), 'labeler' => $chosen['labeler'],
                'published_at' => $chosen['effective_date'], 'source_url' => ExternalLabel::PUBLIC_URL.$chosen['set_id'], 'unii' => $chosen['unii'][0] ?? null,
                'error' => null, 'fetched_at' => now(),
            ])->save();
            if ($changed) {
                $label->sections()->delete();
                foreach ($chosen['sections'] as $code => $text) {
                    foreach (array_slice($this->chunks($text), 0, (int) config('bulai.external_labels.max_chunks_per_section')) as $position => $chunk) {
                        $label->sections()->create(['section_code' => $code, 'section_name' => ExternalLabelSection::SECTIONS[$code], 'position' => $position, 'text_original' => $chunk, 'translation_status' => ExternalLabelSection::TRANSLATION_PENDING]);
                    }
                }
            }

            return $label;
        });

        if ($translate) {
            foreach ($label->sections()->where('translation_status', ExternalLabelSection::TRANSLATION_PENDING)->get() as $section) {
                $this->translate($section);
            }
        }

        return $label->fresh();
    }

    /** Traduz e confere um trecho; recusado, fica guardado em inglês e fora das evidências. */
    public function translate(ExternalLabelSection $section): ExternalLabelSection
    {
        $result = $this->translator->translate($section->text_original);
        $section->forceFill([
            'text_translated' => $result['text'],
            'translation_status' => $result['text'] !== null ? ExternalLabelSection::TRANSLATED : ($result['reason'] !== null && str_starts_with($result['reason'], 'provider_') ? ExternalLabelSection::TRANSLATION_PENDING : ExternalLabelSection::TRANSLATION_REJECTED),
            'translation_model' => $result['model'],
            'translated_at' => $result['text'] !== null ? now() : null,
        ])->save();

        return $section;
    }

    /**
     * Nomes em inglês do princípio ativo (catálogo DDInter), do mais curto (nome base) para o mais longo.
     *
     * @return list<string>
     */
    public function englishNames(ActiveIngredient $ingredient): array
    {
        $names = DB::table('active_ingredient_aliases')->where('ingredient_id', $ingredient->id)->where('language', 'en')->pluck('alias')
            ->map(fn (string $alias): string => trim((string) preg_replace('/\s*\(.*\)\s*$/', '', $alias)))
            ->filter(fn (string $alias): bool => mb_strlen($alias) >= 4)->unique()->sortBy(fn (string $alias): int => mb_strlen($alias))->values()->all();

        return array_slice($names, 0, 3);
    }

    /**
     * Bula de prescrição de princípio ativo único igual ao procurado: a mais recente com texto real de interações; sem
     * nenhuma assim, a mais recente que tenha alguma das seções.
     *
     * @param  list<array<string, mixed>>  $candidates
     * @return array<string, mixed>|null
     */
    private function choose(array $candidates, string $name): ?array
    {
        $wanted = $this->words($name);
        $valid = array_values(array_filter($candidates, function (array $label) use ($wanted): bool {
            if (count($label['substances']) !== 1 || $label['sections'] === []) {
                return false;
            }
            $substance = $this->words($label['substances'][0]);

            return array_slice($substance, 0, count($wanted)) === $wanted;
        }));
        // "Com texto": pelo menos metade do texto de interações da candidata mais completa (e 400 caracteres). Bulas em que a
        // tabela de interações é só imagem ficam abaixo disso; entre as com texto, vale a mais recente.
        $interactions = fn (array $label): int => mb_strlen($label['sections'][ExternalLabelSection::INTERACTIONS] ?? '');
        $threshold = max(self::MIN_INTERACTION_CHARS, (int) (0.5 * max([0, ...array_map($interactions, $valid)])));
        usort($valid, fn (array $a, array $b): int => [(int) ($interactions($b) >= $threshold), (string) $b['effective_date'], array_sum(array_map('mb_strlen', $b['sections']))]
            <=> [(int) ($interactions($a) >= $threshold), (string) $a['effective_date'], array_sum(array_map('mb_strlen', $a['sections']))]);

        return $valid[0] ?? null;
    }

    /** @return list<string> */
    private function words(string $text): array
    {
        return array_values(array_filter(explode(' ', (string) preg_replace('/[^a-z0-9]+/', ' ', Str::lower(Str::ascii($text))))));
    }

    /** @return list<string> */
    private function chunks(string $text): array
    {
        $limit = (int) config('bulai.external_labels.chunk_chars');
        $chunks = [];
        $current = '';
        foreach (preg_split('/(?<=[.;:])\s+(?=[A-Z0-9•(\[])|\n+/u', $text) ?: [] as $piece) {
            $piece = trim((string) preg_replace('/\s+/u', ' ', $piece));
            if ($piece === '') {
                continue;
            }
            if ($current !== '' && mb_strlen($current) + mb_strlen($piece) + 1 > $limit) {
                $chunks[] = $current;
                $current = '';
            }
            $current = $current === '' ? $piece : $current.' '.$piece;
        }
        if ($current !== '') {
            $chunks[] = $current;
        }

        return array_values(array_filter($chunks, fn (string $chunk): bool => mb_strlen($chunk) >= 40));
    }
}
