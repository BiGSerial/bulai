<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Controllers\Controller;
use App\Services\Interactions\MedicationSearchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Busca no catálogo de remédios (Anvisa), a mesma do site: corrigir o remédio de uma pergunta ou de um item da receita. */
final class MedicineController extends Controller
{
    public function search(Request $request, MedicationSearchService $search): JsonResponse
    {
        $data = $request->validate(['q' => ['required', 'string', 'max:80'], 'limit' => ['nullable', 'integer', 'between:1,10'], 'exclude' => ['nullable', 'string', 'max:200']]);
        $exclude = array_map('intval', array_filter(explode(',', (string) ($data['exclude'] ?? ''))));

        return response()->json(['items' => array_values(array_filter($search->search($data['q'], (int) ($data['limit'] ?? 6) + count($exclude)), fn (array $item): bool => ! in_array($item['id'], $exclude, true)))]);
    }
}
