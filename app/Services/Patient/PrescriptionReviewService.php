<?php

namespace App\Services\Patient;

use App\Jobs\ExtractPrescription;
use App\Models\Knowledge\AnvisaMedicine;
use App\Models\PatientMedication;
use App\Models\PatientPrescription;
use App\Models\User;
use App\Services\Treatment\DoseScheduleParser;
use App\Services\Treatment\TreatmentScheduleService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Receita por foto, do envio à confirmação, compartilhada pelas telas do site (Receita\Nova e Receita\Revisar) e pelo
 * aplicativo nativo (`/api/mobile/v1/receitas`): a foto vai para a área transitória e a leitura é disparada; a revisão
 * recebe os itens lidos com horários sugeridos; nada entra no tratamento sem a confirmação da pessoa.
 *
 * Um item que o catálogo não reconhece NÃO impede a confirmação: ele entra pelo nome escrito, sinalizado como "não
 * encontrado", e vira pendência para os administradores ligarem ao catálogo depois.
 */
final class PrescriptionReviewService
{
    /** Nome provisório enquanto a leitura não termina (não é de quem prescreveu). */
    public const READING_NAME = 'Receita em leitura';

    public const IMAGE_MIMES = 'jpg,jpeg,png,webp';

    public function __construct(
        private PrescriptionExtractionService $extraction,
        private DoseScheduleParser $parser,
        private TreatmentScheduleService $schedule,
    ) {}

    /** @return list<string> regras da foto enviada (formato e tamanho) */
    public static function photoRules(): array
    {
        return ['required', 'mimes:'.self::IMAGE_MIMES, 'image', 'max:'.(int) config('bulai.prescription.max_image_kilobytes')];
    }

    /** Limite de receitas lidas por hora, o mesmo no site e no aplicativo. Conta a tentativa quando ainda há vaga. */
    public static function attempt(User $user): bool
    {
        $key = 'receita:'.$user->id;
        if (RateLimiter::tooManyAttempts($key, (int) config('bulai.prescription.rate_limit_per_hour'))) {
            return false;
        }
        RateLimiter::hit($key, 3600);

        return true;
    }

    /** Guarda a foto na área transitória, cria a receita "em leitura" e dispara a leitura. */
    public function start(User $user, string $imagePath, string $extension): PatientPrescription
    {
        $stored = $this->extraction->stash($imagePath, $extension !== '' ? $extension : 'jpg');
        $prescription = PatientPrescription::query()->create([
            'user_id' => $user->id,
            'source' => PatientPrescription::SOURCE_PHOTO,
            'status' => PatientPrescription::STATUS_PENDING,
            'prescriber_name' => self::READING_NAME,
        ]);
        match (config('bulai.consultation.execution')) {
            'sync' => ExtractPrescription::dispatchSync($prescription->id, $stored),
            'queue' => ExtractPrescription::dispatch($prescription->id, $stored)->onQueue((string) config('bulai.consultation.queue')),
            default => ExtractPrescription::dispatchAfterResponse($prescription->id, $stored),
        };

        return $prescription->refresh();
    }

    public static function isReading(PatientPrescription $prescription): bool
    {
        return in_array($prescription->status, [PatientPrescription::STATUS_PENDING, PatientPrescription::STATUS_PROCESSING], true);
    }

    /** Leitura parada há tempo demais vira falha (a pessoa pode tentar outra foto). */
    public function expireIfStale(PatientPrescription $prescription): PatientPrescription
    {
        if (self::isReading($prescription) && $prescription->updated_at->lt(now()->subSeconds((int) config('bulai.consultation.stale_after_seconds')))) {
            $prescription->update(['status' => PatientPrescription::STATUS_FAILED, 'error_code' => 'PRESCRIPTION_TIMEOUT']);
        }

        return $prescription;
    }

    /** Explicação da falha para a pessoa (o código técnico aparece à parte). */
    public static function failureMessage(?string $errorCode): string
    {
        return match ($errorCode) {
            'PRESCRIPTION_IMAGE_INVALID' => 'A imagem não pôde ser lida (formato ou tamanho). Tente outra foto em JPG ou PNG.',
            'PRESCRIPTION_OUTPUT_INVALID' => 'A transcrição veio incompleta e foi descartada por segurança.',
            'PRESCRIPTION_TIMEOUT' => 'A leitura demorou mais que o esperado e foi interrompida.',
            'AI_NOT_CONFIGURED', 'AI_UNAVAILABLE', 'AI_BUDGET_EXCEEDED', 'AI_RATE_LIMITED', 'AI_TIMEOUT' => 'O serviço de leitura está indisponível no momento.',
            default => 'Ocorreu um erro técnico.',
        };
    }

    /**
     * Quem receitou, como a leitura entendeu (a pessoa corrige antes de confirmar).
     *
     * @return array{prescriber: string, registration: string, date: string|null}
     */
    public function header(PatientPrescription $prescription): array
    {
        return [
            'prescriber' => $prescription->prescriber_name === self::READING_NAME ? '' : (string) $prescription->prescriber_name,
            'registration' => (string) $prescription->prescriber_registration,
            'date' => $prescription->issued_at?->format('Y-m-d'),
        ];
    }

    /**
     * Itens lidos, com o primeiro candidato do catálogo escolhido e os horários sugeridos a partir de "quando tomar".
     *
     * @return list<array{name_as_written: string, medicine_id: int|null, medicine_name: string|null, dosage: string, frequency: string, duration: string, notes: string, confidence: float, candidates: list<array<string, mixed>>, skip: bool, times: list<string>, as_needed: bool, interval_hours: int|null, schedule_mode: string}>
     */
    public function items(PatientPrescription $prescription): array
    {
        return array_map(function (array $item): array {
            $first = $item['candidates'][0] ?? null;

            return [
                'name_as_written' => (string) $item['name_as_written'],
                'medicine_id' => $first['id'] ?? null,
                'medicine_name' => $first['name'] ?? null,
                'dosage' => (string) ($item['dosage'] ?? ''),
                'frequency' => (string) ($item['frequency'] ?? ''),
                'duration' => (string) ($item['duration'] ?? ''),
                'notes' => (string) ($item['notes'] ?? ''),
                'confidence' => (float) ($item['confidence'] ?? 0),
                'candidates' => array_values($item['candidates'] ?? []),
                'skip' => false,
            ] + $this->suggestedSchedule($item['frequency'] ?? null);
        }, $prescription->extraction['items'] ?? []);
    }

    /**
     * Horários sugeridos para um "quando tomar" ("8 em 8 horas", "2x ao dia", "se tiver dor").
     *
     * @return array{times: list<string>, as_needed: bool, interval_hours: int|null, schedule_mode: string}
     */
    public function suggestedSchedule(?string $frequency): array
    {
        $schedule = $this->parser->parse($frequency);

        return [
            'times' => $schedule['times'] ?? [],
            'as_needed' => (bool) ($schedule['as_needed'] ?? false),
            'interval_hours' => $schedule['interval_hours'] ?? null,
            'schedule_mode' => ($schedule['interval_hours'] ?? null) !== null ? PatientMedication::MODE_INTERVAL : PatientMedication::MODE_FIXED,
        ];
    }

    /**
     * Confirma a receita: grava quem receitou e cada item não pulado entra no tratamento.
     *
     * @param  array{prescriber?: string|null, registration?: string|null, date?: string|null}  $header
     * @param  list<array<string, mixed>>  $items
     * @return array{confirmed: int, unmatched: int, message: string}
     *
     * @throws ValidationException
     */
    public function confirm(User $user, PatientPrescription $prescription, array $header, array $items): array
    {
        Validator::make(['prescriber' => $header['prescriber'] ?? null, 'registration' => $header['registration'] ?? null, 'date' => $header['date'] ?? null, 'items' => $items], [
            'prescriber' => ['required', 'string', 'min:3', 'max:120'],
            'registration' => ['nullable', 'string', 'max:40'],
            'date' => ['nullable', 'date', 'before_or_equal:today'],
            'items' => ['array', 'max:'.max(1, (int) config('bulai.prescription.max_items'))],
            'items.*.name_as_written' => ['required', 'string', 'max:255'],
            'items.*.medicine_id' => ['nullable', 'integer', Rule::exists(AnvisaMedicine::class, 'id')],
            'items.*.skip' => ['boolean'],
            'items.*.dosage' => ['nullable', 'string', 'max:120'],
            'items.*.frequency' => ['nullable', 'string', 'max:120'],
            'items.*.duration' => ['nullable', 'string', 'max:80'],
            'items.*.notes' => ['nullable', 'string', 'max:255'],
            'items.*.times' => ['array', 'max:12'],
            'items.*.times.*' => ['nullable', 'string', 'max:5'],
            'items.*.as_needed' => ['boolean'],
            'items.*.interval_hours' => ['nullable', 'integer', 'between:1,72'],
            'items.*.schedule_mode' => ['required', 'in:'.PatientMedication::MODE_FIXED.','.PatientMedication::MODE_INTERVAL],
        ], [
            'prescriber.required' => 'Informe quem prescreveu a receita.',
            'prescriber.min' => 'O nome de quem prescreveu precisa ter pelo menos 3 letras.',
        ], [
            'registration' => 'CRM', 'date' => 'data da receita', 'items.*.dosage' => 'dose', 'items.*.frequency' => 'quando tomar',
            'items.*.duration' => 'duração', 'items.*.notes' => 'observações', 'items.*.times.*' => 'horário',
            'items.*.name_as_written' => 'nome do remédio', 'items.*.medicine_id' => 'remédio do catálogo', 'items.*.schedule_mode' => 'modo dos horários',
            'items.*.interval_hours' => 'intervalo entre as doses',
        ])->validate();

        $active = array_values(array_filter($items, fn (array $item): bool => ! ($item['skip'] ?? false)));
        $unmatched = count(array_filter($active, fn (array $item): bool => ($item['medicine_id'] ?? null) === null));
        $issued = filled($header['date'] ?? null) ? Carbon::parse($header['date']) : null;
        $prescription->update([
            'prescriber_name' => trim((string) $header['prescriber']),
            'prescriber_registration' => filled($header['registration'] ?? null) ? trim((string) $header['registration']) : null,
            'issued_at' => $issued,
            'status' => PatientPrescription::STATUS_CONFIRMED,
            'confirmed_at' => now(),
            'extraction' => ($prescription->extraction ?? []) + ['confirmed_items' => count($active), 'skipped_items' => count($items) - count($active), 'unmatched_items' => $unmatched],
        ]);

        $start = $issued ?? now()->startOfDay();
        foreach ($active as $item) {
            $asNeeded = (bool) ($item['as_needed'] ?? false);
            $this->schedule->register($user, [
                'medicine_id' => $item['medicine_id'] ?? null,
                'name_as_written' => $item['name_as_written'],
                'prescription_id' => $prescription->id,
                'dosage' => $item['dosage'] ?? null,
                'frequency' => $item['frequency'] ?? null,
                'duration' => $item['duration'] ?? null,
                'dose_times' => $asNeeded ? [] : TreatmentScheduleService::cleanTimes($item['times'] ?? []),
                'interval_minutes' => ($item['interval_hours'] ?? null) !== null && ! $asNeeded ? (int) $item['interval_hours'] * 60 : null,
                'schedule_mode' => $item['schedule_mode'],
                'started_at' => $start,
                'notes' => trim(implode(' · ', array_filter([filled($item['duration'] ?? null) ? 'Duração: '.trim($item['duration']) : null, filled($item['notes'] ?? null) ? trim($item['notes']) : null]))) ?: null,
            ]);
        }

        $message = count($active) === 1 ? '1 remédio da receita entrou no seu tratamento.' : count($active).' remédios da receita entraram no seu tratamento.';
        if ($unmatched > 0) {
            $message .= ' '.($unmatched === 1 ? '1 deles ainda não está no nosso catálogo: nossa equipe vai conferir.' : "{$unmatched} deles ainda não estão no nosso catálogo: nossa equipe vai conferir.");
        }

        return ['confirmed' => count($active), 'unmatched' => $unmatched, 'message' => $message];
    }
}
