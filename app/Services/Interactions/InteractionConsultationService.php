<?php

namespace App\Services\Interactions;

use App\Jobs\RunInteractionConsultation;
use App\Models\Consultation\InteractionConsultation;
use App\Models\Knowledge\AnvisaMedicine;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

final class InteractionConsultationService
{
    public const TOO_FEW = 'CONSULTATION_TOO_FEW_MEDICINES';

    public const TOO_MANY = 'CONSULTATION_TOO_MANY_MEDICINES';

    public const UNKNOWN_MEDICINE = 'CONSULTATION_UNKNOWN_MEDICINE';

    public function __construct(private readonly PairAnalyzer $analyzer) {}

    /**
     * Cria a consulta (PENDING) e a enfileira. Guarda apenas ids/nomes de medicamentos, nunca dados pessoais além do vínculo ao usuário.
     *
     * @param  list<int>  $medicineIds
     */
    public function start(User $user, array $medicineIds): InteractionConsultation
    {
        $consultation = $this->create($user, $medicineIds);
        match (config('bulai.consultation.execution')) {
            'sync' => RunInteractionConsultation::dispatchSync($consultation->id),
            'queue' => RunInteractionConsultation::dispatch($consultation->id)->onQueue((string) config('bulai.consultation.queue')),
            default => RunInteractionConsultation::dispatchAfterResponse($consultation->id),
        };

        return $consultation;
    }

    /**
     * @param  list<int>  $medicineIds
     */
    public function create(User $user, array $medicineIds): InteractionConsultation
    {
        $medicineIds = array_values(array_unique(array_map('intval', $medicineIds)));
        if (count($medicineIds) < 2) {
            throw new InvalidArgumentException(self::TOO_FEW);
        }
        if (count($medicineIds) > (int) config('bulai.consultation.max_medicines')) {
            throw new InvalidArgumentException(self::TOO_MANY);
        }

        $medicines = AnvisaMedicine::query()->with('ingredients')->whereIn('id', $medicineIds)->get()->keyBy('id');
        if ($medicines->count() !== count($medicineIds)) {
            throw new InvalidArgumentException(self::UNKNOWN_MEDICINE);
        }

        return InteractionConsultation::query()->create([
            'uuid' => (string) Str::uuid(),
            'user_id' => $user->id,
            'status' => InteractionConsultation::PENDING,
            'medicines' => array_map(fn (int $id): array => [
                'id' => $id,
                'name' => $medicines[$id]->product_name,
                'ingredients' => $medicines[$id]->ingredients->map(fn ($ingredient): array => ['id' => $ingredient->id, 'name' => $ingredient->canonical_name])->values()->all(),
            ], $medicineIds),
            'expires_at' => now()->addDays((int) config('bulai.consultation.retention_days')),
        ]);
    }

    public function run(InteractionConsultation $consultation): InteractionConsultation
    {
        if ($consultation->status !== InteractionConsultation::PENDING) {
            return $consultation;
        }

        $started = hrtime(true);
        $mode = (string) config('bulai.retrieval.mode');
        $consultation->update([
            'status' => InteractionConsultation::RUNNING,
            'started_at' => now(),
            'run_id' => (string) Str::uuid(),
            'pipeline' => [
                'retrieval_mode' => $mode,
                'generation_enabled' => (bool) config('bulai.generation.enabled'),
                'text_provider' => config('ai.text.default'),
                'embedding_provider' => config('ai.embedding.default'),
                'prompt_version' => config('bulai.generation.prompt_version'),
                'code_version' => config('bulai.code_version'),
            ],
        ]);

        try {
            $position = 0;
            $medicines = $consultation->medicines;
            for ($i = 0; $i < count($medicines); $i++) {
                for ($j = $i + 1; $j < count($medicines); $j++) {
                    $attributes = $this->analyzePair($medicines[$i], $medicines[$j], $mode);
                    $consultation->results()->create(['position' => $position++] + $attributes);
                }
            }
            $consultation->update(['status' => InteractionConsultation::COMPLETED]);
        } catch (Throwable $exception) {
            Log::error('consultation_failed', ['consultation_id' => $consultation->id, 'exception' => $exception::class]);
            $consultation->update(['status' => InteractionConsultation::FAILED, 'error_code' => 'CONSULTATION_PIPELINE_FAILED']);
        }

        $consultation->update(['completed_at' => now(), 'duration_ms' => (int) ((hrtime(true) - $started) / 1_000_000)]);
        Log::info('consultation_finished', ['consultation_id' => $consultation->id, 'status' => $consultation->status, 'medicines' => count($consultation->medicines), 'duration_ms' => $consultation->duration_ms]);

        return $consultation->refresh();
    }

    /**
     * @param  array<string, mixed>  $a
     * @param  array<string, mixed>  $b
     * @return array<string, mixed>
     */
    private function analyzePair(array $a, array $b, string $mode): array
    {
        try {
            return $this->analyzer->analyze($a, $b, $mode);
        } catch (Throwable $exception) {
            Log::error('pair_analysis_failed', ['exception' => $exception::class]);

            return [
                'medicine_a_id' => $a['id'], 'medicine_b_id' => $b['id'], 'label_a' => $a['name'], 'label_b' => $b['name'],
                'ingredients_a' => $a['ingredients'], 'ingredients_b' => $b['ingredients'],
                'verdict' => PairVerdict::TechnicalError, 'error_code' => 'PAIR_ANALYSIS_FAILED',
                'limitations' => ['Ocorreu um erro técnico ao analisar este par. Tente novamente mais tarde.'],
            ];
        }
    }

    /** Marca como falha as consultas que ficaram paradas além do limite (fila indisponível ou processo interrompido). */
    public function failStale(InteractionConsultation $consultation): InteractionConsultation
    {
        $limit = now()->subSeconds((int) config('bulai.consultation.stale_after_seconds'));
        if (! $consultation->isFinished() && $consultation->updated_at->lt($limit)) {
            $consultation->update(['status' => InteractionConsultation::FAILED, 'error_code' => 'CONSULTATION_TIMEOUT', 'completed_at' => now()]);
        }

        return $consultation;
    }

    /** Remove consultas vencidas; devolve quantas foram apagadas. */
    public function purgeExpired(): int
    {
        return DB::table('interaction_consultations')->where('expires_at', '<', now())->delete();
    }
}
