<?php

namespace App\Livewire\Consulta\Cuidado;

use App\Models\CaregiverLink;
use App\Services\Caregiving\CaregiverInviteException;
use App\Services\Caregiving\CaregiverLinkService;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * "Cuidar de alguém" (lado de quem cuida no modo cuidador): digitar o código do convite (o link do convite já traz o
 * código em `?codigo=`), ver os pedidos aguardando aprovação, abrir os remédios de quem acompanha e sair do vínculo.
 * Código errado, usado ou vencido mostra uma frase clara; tentativas erradas demais esperam (limite no serviço).
 */
class CuidarDeAlguem extends Component
{
    public string $codigo = '';

    public ?string $mensagem = null;

    public ?string $erro = null;

    public function mount(): void
    {
        $codigo = request()->query('codigo');
        $this->codigo = is_string($codigo) ? mb_substr(preg_replace('/[^\d ]+/', '', $codigo) ?? '', 0, 12) : '';
    }

    public function usarCodigo(CaregiverLinkService $service): void
    {
        $this->reset('mensagem', 'erro');
        try {
            $link = $service->acceptInvite(auth()->user(), $this->codigo);
            $this->codigo = '';
            $this->mensagem = 'Pronto! Agora falta '.CaregiverLink::firstName($link->patient).' aprovar. Avisamos no aplicativo de quem convidou.';
        } catch (CaregiverInviteException $exception) {
            $this->erro = $exception->getMessage();
        }
    }

    public function sair(CaregiverLinkService $service, int $id): void
    {
        $this->reset('mensagem', 'erro');
        $link = CaregiverLink::query()->with('patient:id,name')->findOrFail($id);
        $this->authorize('leave', $link);
        $service->leave($link, auth()->user());
        $this->mensagem = 'Você deixou de acompanhar '.CaregiverLink::firstName($link->patient).'.';
    }

    public function render(): View
    {
        $vinculos = CaregiverLink::query()->with('patient.patientProfile')->where('caregiver_id', auth()->id())
            ->whereIn('status', [CaregiverLink::STATUS_PENDING, CaregiverLink::STATUS_ACTIVE])->orderBy('id')->get();

        return view('livewire.consulta.cuidado.cuidar-de-alguem', [
            'pendentes' => $vinculos->where('status', CaregiverLink::STATUS_PENDING)->values(),
            'ativos' => $vinculos->where('status', CaregiverLink::STATUS_ACTIVE)->values(),
            'frases' => [
                CaregiverLink::PERMISSION_ALERTS => ['Você recebe aviso quando um remédio atrasar', 'Sem avisos de atraso'],
                CaregiverLink::PERMISSION_VIEW => ['Você vê os remédios e horários', 'Sem ver os remédios'],
                CaregiverLink::PERMISSION_MANAGE => ['Você pode marcar doses e mudar horários', 'Sem marcar doses nem mudar horários'],
            ],
        ]);
    }
}
