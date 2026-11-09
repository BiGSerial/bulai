<?php

namespace App\Livewire\Admin\ApiTokens;

use App\Models\Knowledge\IngestionToken;
use App\Models\UserAuditEvent;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Acesso à API (/api/v1): emite tokens de leitura ou de avaliação com validade, mostra cada um uma única vez e revoga na hora.
 * O banco guarda só o hash SHA-256; tokens de ingestão (knowledge:ingest) continuam sendo emitidos pelo comando.
 */
class Index extends Component
{
    use WithPagination;

    /** Unidades da validade => minutos de cada uma. */
    public const UNITS = ['minutos' => 1, 'horas' => 60, 'dias' => 1440];

    /** Validade máxima de um token: 90 dias. */
    public const MAX_MINUTES = 90 * 1440;

    public string $nome = '';

    public string $escopo = IngestionToken::SCOPE_API_READ;

    /** Validade: quantidade + unidade (ex.: 2 horas). O token expira sozinho ao fim desse tempo. */
    public int $validade = 1;

    public string $unidade = 'horas';

    /** Token recém-emitido, exibido uma única vez. */
    public ?string $tokenNovo = null;

    /** Quando o token recém-emitido expira (data e hora no fuso da aplicação). */
    public ?string $expiraNovo = null;

    public ?string $mensagem = null;

    public function boot(): void
    {
        abort_unless(auth()->user()?->isAdmin(), 403);
    }

    public function emitir(): void
    {
        $this->validate([
            'nome' => ['required', 'string', 'max:120'],
            'escopo' => ['required', 'in:'.implode(',', IngestionToken::API_SCOPES)],
            'unidade' => ['required', 'in:'.implode(',', array_keys(self::UNITS))],
            'validade' => ['required', 'integer', 'min:1', 'max:'.intdiv(self::MAX_MINUTES, self::UNITS[$this->unidade] ?? 1)],
        ], ['validade.max' => 'A validade máxima é de 90 dias.'], ['nome' => 'nome', 'escopo' => 'permissão', 'validade' => 'validade', 'unidade' => 'unidade']);

        [$token, $plain] = IngestionToken::issue($this->nome, $this->escopo, now()->addMinutes($this->validade * self::UNITS[$this->unidade]));
        $this->audit(UserAuditEvent::EVENT_API_TOKEN_ISSUED, $token);
        $this->tokenNovo = $plain;
        $this->expiraNovo = $token->expires_at->timezone(config('app.timezone'))->format('d/m/Y H:i');
        $this->mensagem = null;
        $this->reset('nome');
        $this->resetPage();
    }

    public function revogar(int $id): void
    {
        $token = IngestionToken::query()->whereIn('scope', IngestionToken::API_SCOPES)->find($id);
        if ($token !== null && $token->revoked_at === null) {
            $token->revoke();
            $this->audit(UserAuditEvent::EVENT_API_TOKEN_REVOKED, $token);
            $this->mensagem = 'Token "'.$token->name.'" revogado. Chamadas com ele passam a receber 401.';
        }
        $this->tokenNovo = null;
    }

    public function render(): View
    {
        return view('livewire.admin.api-tokens.index', [
            'tokens' => IngestionToken::query()->whereIn('scope', IngestionToken::API_SCOPES)->latest('id')->paginate(20),
        ])->title('Acesso à API · BULAI');
    }

    private function audit(string $event, IngestionToken $token): void
    {
        UserAuditEvent::query()->create([
            'user_id' => auth()->id(),
            'actor_id' => auth()->id(),
            'event' => $event,
            'details' => ['token_id' => $token->id, 'name' => $token->name, 'scope' => $token->scope, 'expires_at' => $token->expires_at->toIso8601String()],
            'ip' => request()->ip(),
        ]);
    }
}
