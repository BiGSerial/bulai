<?php

namespace App\Livewire\Consulta;

use Illuminate\Contracts\View\View;
use Illuminate\Notifications\DatabaseNotification;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Sino do cabeçalho do app do paciente: conta os avisos não lidos (uma consulta) e, a cada verificação (`wire:poll`
 * só neste fragmento), anuncia ao navegador os avisos que chegaram desde a última vez (evento `bulai-aviso`).
 */
class SinoDeAvisos extends Component
{
    /** Instante (UTC, ISO 8601) da última verificação: só avisos criados depois dele viram notificação do navegador. */
    #[Locked]
    public string $desde = '';

    public function mount(): void
    {
        $this->desde = now()->toIso8601String();
    }

    public function verificar(): void
    {
        $novo = auth()->user()->unreadNotifications()->where('created_at', '>', $this->desde)->latest()->first();
        if (! $novo instanceof DatabaseNotification) {
            return;
        }
        $this->desde = $novo->created_at->toIso8601String();
        $this->dispatch('bulai-aviso', title: (string) ($novo->data['title'] ?? 'Aviso'), body: (string) ($novo->data['body'] ?? ''), tag: $novo->id);
    }

    public function render(): View
    {
        return view('livewire.consulta.sino-de-avisos', ['naoLidos' => auth()->user()->unreadNotifications()->count()]);
    }
}
