<?php

namespace App\Console\Commands;

use App\Models\Knowledge\IngestionToken;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('tokens:revoke {id? : ID do token} {--list : Lista os tokens ativos}')]
#[Description('Revoga na hora um Bearer Token (API /api/v1 ou ingestão). Com --list mostra os tokens ativos.')]
class RevokeTokenCommand extends Command
{
    public function handle(): int
    {
        if ($this->option('list') || $this->argument('id') === null) {
            $this->table(['ID', 'Nome', 'Escopo', 'Expira', 'Último uso'], IngestionToken::query()->whereNull('revoked_at')->where('expires_at', '>', now())->orderBy('id')->get()
                ->map(fn (IngestionToken $token): array => [$token->id, $token->name, $token->scope, $token->expires_at->toDateTimeString(), $token->last_used_at?->toDateTimeString() ?? '—'])->all());

            return self::SUCCESS;
        }

        $token = IngestionToken::query()->find((int) $this->argument('id'));
        if ($token === null) {
            $this->components->error('Token não encontrado.');

            return self::FAILURE;
        }
        $token->revoke();
        $this->components->info("Token #{$token->id} ({$token->name}, {$token->scope}) revogado.");

        return self::SUCCESS;
    }
}
