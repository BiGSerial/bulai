<?php

namespace App\Services\Reports\Types;

use App\Services\Reports\CsvWriter;
use App\Services\Reports\ReportType;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** Auditoria de usuários: eventos de conta (criação, perfil, ativação, senha provisória, consentimentos, exclusões). Nunca inclui senhas ou tokens. */
final class UserAuditReport implements ReportType
{
    public function key(): string
    {
        return 'user_audit';
    }

    public function label(): string
    {
        return 'Auditoria de usuários';
    }

    public function description(): string
    {
        return 'Eventos de conta e de dados de saúde (quem fez, com quem, quando e de onde), sem senhas nem tokens.';
    }

    public function formats(): array
    {
        return ['csv'];
    }

    public function acceptsPeriod(): bool
    {
        return true;
    }

    public function build(string $format, array $parameters): array
    {
        $query = DB::table('user_audit_events as e')
            ->leftJoin('users as u', 'u.id', '=', 'e.user_id')
            ->leftJoin('users as a', 'a.id', '=', 'e.actor_id');
        if ($parameters['from']) {
            $query->where('e.created_at', '>=', CarbonImmutable::parse($parameters['from'])->startOfDay());
        }
        if ($parameters['to']) {
            $query->where('e.created_at', '<=', CarbonImmutable::parse($parameters['to'])->endOfDay());
        }
        $rows = $query->orderBy('e.id')
            ->get(['e.id', 'e.created_at', 'e.event', 'u.email as user_email', 'u.role as user_role', 'a.email as actor_email', 'e.ip', 'e.details'])
            ->map(fn (object $row): array => [
                'id' => $row->id, 'created_at' => $row->created_at, 'event' => $row->event, 'user_email' => $row->user_email, 'user_role' => $row->user_role,
                'actor_email' => $row->actor_email, 'ip' => $row->ip, 'details' => $this->details($row->details),
            ])->all();

        return CsvWriter::write(['id', 'created_at', 'event', 'user_email', 'user_role', 'actor_email', 'ip', 'details'], $rows);
    }

    /** Detalhes sem campos sensíveis, mesmo que algum evento os tenha gravado por engano. */
    private function details(mixed $raw): ?string
    {
        $details = is_string($raw) ? json_decode($raw, true) : $raw;
        if (! is_array($details)) {
            return null;
        }
        foreach (array_keys($details) as $key) {
            if (preg_match('/pass|senha|token|secret|hash/i', (string) $key) === 1) {
                unset($details[$key]);
            }
        }

        return $details === [] ? null : json_encode($details, JSON_UNESCAPED_UNICODE);
    }
}
