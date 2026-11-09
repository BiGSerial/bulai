<?php

namespace App\Livewire\Consulta\Cuidado;

use App\Models\CaregiverAuditEvent;
use App\Models\CaregiverLink;
use App\Services\Caregiving\CaregiverInviteException;
use App\Services\Caregiving\CaregiverLinkService;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * "Quem cuida de mim" (lado do paciente no modo cuidador): gerar o convite (código de uso único, 48 h), aprovar ou
 * recusar quem usou o código escolhendo as permissões, mudar as permissões, retirar o acesso e ver o histórico de cada
 * vínculo. Toda ação confere a policy `CaregiverLinkPolicy::manage` (só o paciente).
 */
class QuemCuidaDeMim extends Component
{
    /**
     * Permissões marcadas na tela, por vínculo (pendente: o padrão é tudo marcado; ativo: o que está valendo).
     *
     * @var array<int|string, array<string, bool>>
     */
    public array $permissoes = [];

    /** Vínculo cujo histórico está aberto. */
    public ?int $historicoDe = null;

    public ?string $mensagem = null;

    public ?string $erro = null;

    public function gerarConvite(CaregiverLinkService $service): void
    {
        $this->reset('mensagem', 'erro');
        try {
            $service->createInvite(auth()->user());
            $this->mensagem = 'Convite criado. Envie o código para quem vai cuidar de você.';
        } catch (CaregiverInviteException $exception) {
            $this->erro = $exception->getMessage();
        }
    }

    public function cancelarConvite(CaregiverLinkService $service): void
    {
        $this->reset('mensagem', 'erro');
        $service->cancelOpenInvites(auth()->user());
        $this->mensagem = 'Convite cancelado. O código não vale mais.';
    }

    public function aprovar(CaregiverLinkService $service, int $id): void
    {
        $link = $this->vinculo($id);
        $service->approve($link, auth()->user(), $this->permissoes[$id] ?? array_fill_keys(array_keys(CaregiverLink::PERMISSIONS), true));
        $this->permissoes[$id] = $link->only(array_keys(CaregiverLink::PERMISSIONS));
        $this->mensagem = CaregiverLink::firstName($link->caregiver).' agora acompanha você, com as permissões que você marcou.';
    }

    public function recusar(CaregiverLinkService $service, int $id): void
    {
        $link = $this->vinculo($id);
        $service->reject($link, auth()->user());
        $this->mensagem = 'Pedido recusado. Nada foi compartilhado.';
    }

    public function salvarPermissoes(CaregiverLinkService $service, int $id): void
    {
        $link = $this->vinculo($id);
        $service->updatePermissions($link, auth()->user(), $this->permissoes[$id] ?? []);
        $this->permissoes[$id] = $link->refresh()->only(array_keys(CaregiverLink::PERMISSIONS));
        $this->mensagem = 'Permissões de '.CaregiverLink::firstName($link->caregiver).' atualizadas.';
    }

    public function revogar(CaregiverLinkService $service, int $id): void
    {
        $link = $this->vinculo($id);
        $service->revoke($link, auth()->user());
        $this->mensagem = CaregiverLink::firstName($link->caregiver).' não tem mais acesso aos seus remédios.';
    }

    public function alternarHistorico(int $id): void
    {
        $this->vinculo($id);
        $this->historicoDe = $this->historicoDe === $id ? null : $id;
    }

    /** Vínculo do próprio paciente (403 para qualquer outro). */
    private function vinculo(int $id): CaregiverLink
    {
        $this->reset('mensagem', 'erro');
        $link = CaregiverLink::query()->with('caregiver:id,name')->findOrFail($id);
        $this->authorize('manage', $link);

        return $link;
    }

    public function render(): View
    {
        $user = auth()->user();
        $vinculos = CaregiverLink::query()->with('caregiver:id,name,email')->where('patient_id', $user->id)
            ->whereIn('status', [CaregiverLink::STATUS_PENDING, CaregiverLink::STATUS_ACTIVE, CaregiverLink::STATUS_REVOKED, CaregiverLink::STATUS_LEFT])
            ->whereNotNull('caregiver_id')->latest('id')->get();
        foreach ($vinculos->whereIn('status', [CaregiverLink::STATUS_PENDING, CaregiverLink::STATUS_ACTIVE]) as $link) {
            $this->permissoes[$link->id] ??= $link->isPending()
                ? array_fill_keys(array_keys(CaregiverLink::PERMISSIONS), true)
                : $link->only(array_keys(CaregiverLink::PERMISSIONS));
        }
        $convite = CaregiverLink::query()->where('patient_id', $user->id)->where('status', CaregiverLink::STATUS_INVITED)->latest('id')->first();

        return view('livewire.consulta.cuidado.quem-cuida-de-mim', [
            'consentido' => $user->hasHealthConsent(),
            'fuso' => $user->treatmentTimezone(),
            'convite' => $convite?->isUsableInvite() ? $convite : null,
            'conviteVencido' => $convite !== null && ! $convite->isUsableInvite(),
            'pendentes' => $vinculos->where('status', CaregiverLink::STATUS_PENDING)->values(),
            'ativos' => $vinculos->where('status', CaregiverLink::STATUS_ACTIVE)->values(),
            'encerrados' => $vinculos->whereIn('status', [CaregiverLink::STATUS_REVOKED, CaregiverLink::STATUS_LEFT])->take(10)->values(),
            'historico' => $this->historicoDe !== null
                ? CaregiverAuditEvent::query()->with(['actor:id,name', 'link.caregiver:id,name'])->where('patient_id', $user->id)->where('caregiver_link_id', $this->historicoDe)->latest('id')->limit(50)->get()
                : collect(),
            'rotulos' => CaregiverLink::PERMISSIONS,
        ]);
    }
}
