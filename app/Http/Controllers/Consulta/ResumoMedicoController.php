<?php

namespace App\Http\Controllers\Consulta;

use App\Http\Controllers\Controller;
use App\Models\UserAuditEvent;
use App\Services\Patient\MedicalSummaryService;
use Barryvdh\DomPDF\Facade\Pdf;
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

        $resumo = $summaries->build($user);
        UserAuditEvent::query()->create(['user_id' => $user->id, 'actor_id' => $user->id, 'event' => 'medical_summary_downloaded', 'details' => ['medications' => count($resumo['em_uso'])], 'ip' => request()->ip()]);

        return Pdf::loadView('pdf.resumo-medico', ['resumo' => $resumo])
            ->setPaper('a4')
            ->download('resumo-para-o-medico-'.$resumo['gerado_em']->format('Y-m-d').'.pdf');
    }
}
