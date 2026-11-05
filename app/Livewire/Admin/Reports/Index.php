<?php

namespace App\Livewire\Admin\Reports;

use App\Models\AdminReport;
use App\Services\Reports\ReportGenerator;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\RateLimiter;
use InvalidArgumentException;
use Livewire\Component;
use Livewire\WithPagination;

/** Relatórios: catálogo de tipos, geração sob demanda (com período opcional) e lista dos gerados para baixar ou apagar. */
class Index extends Component
{
    use WithPagination;

    public string $tipo = 'analytics';

    public string $formato = 'json';

    public ?string $de = null;

    public ?string $ate = null;

    public ?string $mensagem = null;

    public ?string $tipoMensagem = null;

    public function updatedTipo(ReportGenerator $generator): void
    {
        $formats = $generator->type($this->tipo)->formats();
        if (! in_array($this->formato, $formats, true)) {
            $this->formato = $formats[0];
        }
    }

    public function gerar(ReportGenerator $generator): void
    {
        $this->mensagem = null;
        $this->validate([
            'tipo' => ['required', 'string', 'in:'.implode(',', array_keys($generator->types()))],
            'formato' => ['required', 'string', 'in:json,csv'],
            'de' => ['nullable', 'date'],
            'ate' => ['nullable', 'date', 'after_or_equal:de'],
        ], [], ['tipo' => 'tipo', 'formato' => 'formato', 'de' => 'data inicial', 'ate' => 'data final']);

        $key = 'relatorios:'.auth()->id();
        if (RateLimiter::tooManyAttempts($key, 20)) {
            $this->feedback('Muitos relatórios em pouco tempo. Aguarde alguns minutos.', 'warning');

            return;
        }

        try {
            $report = $generator->start($this->tipo, $this->formato, ['from' => $this->de ?: null, 'to' => $this->ate ?: null], auth()->user());
        } catch (InvalidArgumentException $exception) {
            $this->feedback($exception->getMessage() === ReportGenerator::INVALID_FORMAT ? 'Este relatório não está disponível no formato escolhido.' : 'Tipo de relatório desconhecido.', 'danger');

            return;
        }
        RateLimiter::hit($key, 600);
        $this->resetPage();
        $this->feedback('Relatório "'.$generator->type($report->type)->label().'" em geração. A lista abaixo se atualiza sozinha.', 'success');
    }

    public function apagar(ReportGenerator $generator, string $uuid): void
    {
        $report = AdminReport::query()->where('uuid', $uuid)->first();
        if ($report !== null) {
            $generator->delete($report);
            $this->feedback('Relatório apagado.', 'success');
        }
    }

    public function render(ReportGenerator $generator): View
    {
        $reports = AdminReport::query()->with('generator:id,name')->latest('id')->paginate(15);

        return view('livewire.admin.reports.index', [
            'tipos' => $generator->types(),
            'tipoAtual' => $generator->type($this->tipo),
            'reports' => $reports,
            'gerando' => $reports->getCollection()->contains(fn (AdminReport $report): bool => ! $report->isFinished()),
        ])->title('Relatórios · BULAI');
    }

    private function feedback(string $message, string $type): void
    {
        $this->mensagem = $message;
        $this->tipoMensagem = $type;
    }
}
