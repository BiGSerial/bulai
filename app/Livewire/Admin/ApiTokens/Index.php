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

    public const VALIDITY_DAYS = [7, 30, 90];

    public string $nome = '';

    public string $escopo = IngestionToken::SCOPE_API_READ;

    public int $dias = 30;

    /** Token recém-emitido, exibido uma única vez. */
    public ?string $tokenNovo = null;

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
            'dias' => ['required', 'integer', 'in:'.implode(',', self::VALIDITY_DAYS)],
        ], [], ['nome' => 'nome', 'escopo' => 'permissão', 'dias' => 'validade']);

        [$token, $plain] = IngestionToken::issue($this->nome, $this->escopo, now()->addDays($this->dias));
        $this->audit(UserAuditEvent::EVENT_API_TOKEN_ISSUED, $token);
        $this->tokenNovo = $plain;
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
            'details' => ['token_id' => $token->id, 'name' => $token->name, 'scope' => $token->scope],
            'ip' => request()->ip(),
        ]);
    }
}
