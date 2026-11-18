<?php

namespace App\Http\Controllers\Consulta;

use App\Http\Controllers\Controller;
use App\Services\Patient\MedicalSummaryService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;

/**
 * Resumo de saúde em PDF para o paciente levar ao médico (Meus remédios › "Resumo para o médico"). Só com o consentimento
 * de dados de saúde, só os dados do próprio paciente; cada download fica na auditoria da conta.
 */
class ResumoMedicoController extends Controller
{
    public function __invoke(MedicalSummaryService $summaries): Response|RedirectResponse
    {
        $user = auth()->user();
        if (! $user->hasHealthConsent()) {
            return redirect()->route('consulta.perfil');
        }

        return $summaries->download($user, request()->ip());
    }
}
