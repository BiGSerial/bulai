<x-knowledge-admin.shell title="Acesso à API" subtitle="Tokens para ler estatísticas, consumo de IA e o rastreio das respostas, ou para avaliar o Perguntar por /api/v1. Guia em docs/api.md.">
    @if ($mensagem)<x-knowledge-admin.alert tone="success">{{ $mensagem }}</x-knowledge-admin.alert>@endif

    @if ($tokenNovo)
        <x-knowledge-admin.alert tone="warning">
            <p class="font-semibold">Copie o token agora: ele não será mostrado de novo.</p>
            <code class="mt-2 block select-all break-all rounded-lg bg-white px-3 py-2 font-mono text-xs text-slate-900 ring-1 ring-slate-200" data-test="token-novo">{{ $tokenNovo }}</code>
            <p class="mt-2 text-xs">Use no cabeçalho <span class="font-mono">Authorization: Bearer &lt;token&gt;</span>.</p>
        </x-knowledge-admin.alert>
    @endif

    <x-knowledge-admin.section title="Emitir token" description="Leitura só consulta números e respostas. Avaliação também faz perguntas reais ao Perguntar e consome IA.">
        <form class="grid gap-3 sm:flex sm:flex-wrap sm:items-end" wire:submit="emitir" aria-label="Emitir token da API">
            <x-knowledge-admin.input label="Nome (para quem ou para quê)" wire:model="nome" wrapper-class="sm:w-72" maxlength="120" />
            <x-knowledge-admin.select label="Permissão" compact wire:model="escopo" wrapper-class="sm:w-56">
                <option value="{{ \App\Models\Knowledge\IngestionToken::SCOPE_API_READ }}">Leitura</option>
                <option value="{{ \App\Models\Knowledge\IngestionToken::SCOPE_API_EVALUATE }}">Leitura e avaliação</option>
            </x-knowledge-admin.select>
            <x-knowledge-admin.select label="Validade" compact wire:model="dias" wrapper-class="sm:w-36">
                @foreach (\App\Livewire\Admin\ApiTokens\Index::VALIDITY_DAYS as $days)<option value="{{ $days }}">{{ $days }} dias</option>@endforeach
            </x-knowledge-admin.select>
            <x-knowledge-admin.button type="submit" loading-target="emitir" loading="Emitindo…">Emitir token</x-knowledge-admin.button>
        </form>
        @error('nome')<p class="text-xs text-rose-700">{{ $message }}</p>@enderror
    </x-knowledge-admin.section>

    <x-knowledge-admin.section title="Tokens emitidos" description="Revogar vale na hora: a próxima chamada com o token recebe 401.">
        <x-knowledge-admin.data-table :columns="[['label' => 'Nome'], ['label' => 'Permissão'], ['label' => 'Emitido'], ['label' => 'Expira'], ['label' => 'Último uso'], ['label' => 'Situação'], ['label' => 'Ações', 'align' => 'right']]" dense>
            @foreach ($tokens as $token)
                @php $format = fn ($date) => $date?->timezone(config('app.timezone'))->format('d/m/Y H:i') ?? '—'; @endphp
                <tr wire:key="token-{{ $token->id }}">
                    <td>{{ $token->name }} <span class="text-xs font-normal text-slate-500">#{{ $token->id }}</span></td>
                    <td class="text-slate-600">{{ $token->scope === \App\Models\Knowledge\IngestionToken::SCOPE_API_EVALUATE ? 'Leitura e avaliação' : 'Leitura' }}</td>
                    <td class="tabular text-slate-600">{{ $format($token->created_at) }}</td>
                    <td class="tabular text-slate-600">{{ $format($token->expires_at) }}</td>
                    <td class="tabular text-slate-600">{{ $format($token->last_used_at) }}</td>
                    <td>
                        @if ($token->revoked_at)
                            <x-knowledge-admin.badge tone="danger">Revogado</x-knowledge-admin.badge>
                        @elseif ($token->isActive())
                            <x-knowledge-admin.badge tone="success">Ativo</x-knowledge-admin.badge>
                        @else
                            <x-knowledge-admin.badge tone="neutral">Expirado</x-knowledge-admin.badge>
                        @endif
                    </td>
                    <td><x-knowledge-admin.row-actions>
                        @if ($token->isActive())
                            <x-knowledge-admin.confirm-modal action="revogar({{ $token->id }})" tone="danger" title="Revogar token?" message="Quem usa este token perde o acesso à API imediatamente. Não dá para desfazer; emita outro se precisar." confirm-label="Revogar token" loading="Revogando…">
                                <x-knowledge-admin.button variant="quiet" size="sm">Revogar</x-knowledge-admin.button>
                            </x-knowledge-admin.confirm-modal>
                        @endif
                    </x-knowledge-admin.row-actions></td>
                </tr>
            @endforeach
            @if ($tokens->isEmpty())
                <x-slot:empty>Nenhum token emitido ainda.</x-slot:empty>
            @endif
        </x-knowledge-admin.data-table>
        {{ $tokens->links() }}
    </x-knowledge-admin.section>
</x-knowledge-admin.shell>
