<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Controllers\Controller;
use App\Models\Consultation\MedicineLeafletSummary;
use App\Models\Knowledge\AnvisaMedicine;
use App\Models\User;
use App\Services\Leaflet\LeafletPage;
use App\Services\Patient\TreatmentContextService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Bula humanizada no aplicativo nativo, com as mesmas regras da tela do site (LeafletPage). `GET` responde na hora: o
 * resumo pronto ou `carregando`; nesse caso o aplicativo mostra o esqueleto e pede `POST …/gerar`, que prepara o resumo.
 */
final class LeafletController extends Controller
{
    public function show(Request $request, AnvisaMedicine $medicine, LeafletPage $page, TreatmentContextService $treatment): JsonResponse
    {
        return response()->json(['leaflet' => $this->payload($request->user(), $medicine, $page->open($medicine), $treatment)]);
    }

    public function generate(Request $request, AnvisaMedicine $medicine, LeafletPage $page, TreatmentContextService $treatment): JsonResponse
    {
        $opened = $page->open($medicine);
        $result = $opened['state'] === LeafletPage::LOADING ? $page->generate($request->user(), $medicine) : $opened;

        return response()->json(['leaflet' => $this->payload($request->user(), $medicine, $result, $treatment)]);
    }

    /**
     * @param  array{state: string, summary: MedicineLeafletSummary|null}  $result
     * @return array<string, mixed>
     */
    private function payload(User $user, AnvisaMedicine $medicine, array $result, TreatmentContextService $treatment): array
    {
        $context = $treatment->for($user, $medicine);
        $summary = $result['summary'];
        $usable = $summary !== null && ! in_array($summary->status, [MedicineLeafletSummary::STATUS_UNAVAILABLE, MedicineLeafletSummary::STATUS_INVALID], true);
        $sections = $usable ? LeafletPage::sections($summary) : ['com' => [], 'sem' => []];
        $section = fn (array $item): array => [
            'key' => $item['key'],
            'label' => $item['label'],
            'tone' => LeafletPage::style($item['key'])[0],
            'claims' => array_slice($item['claims'], 0, 3),
            'sources' => $item['sources'],
        ];
        $cover = collect($sections['com'])->firstWhere('key', 'para_que_serve');

        return [
            'medicine' => ['id' => $medicine->id, 'name' => $medicine->product_name, 'ingredient' => $medicine->active_ingredient ?: null, 'holder' => LeafletPage::holder($medicine) ?: null],
            'state' => $result['state'],
            'failure' => $summary !== null && ! $usable ? ($summary->status === MedicineLeafletSummary::STATUS_UNAVAILABLE ? 'unavailable' : 'invalid') : null,
            'cover' => $cover === null ? null : $section($cover),
            'sections' => array_values(array_map($section, array_filter($sections['com'], fn (array $item): bool => $item['key'] !== 'para_que_serve'))),
            'missing' => $sections['sem'],
            'prepared' => $usable ? LeafletPage::preparedNote($summary) : null,
            'risk' => TreatmentContextService::riskSentence([['medicine' => $medicine->product_name, 'context' => $context]]),
            'treatment' => TreatmentContextService::panel(['medicine' => $medicine->product_name, 'context' => $context]),
        ];
    }
}
