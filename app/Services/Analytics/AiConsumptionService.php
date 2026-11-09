<?php

namespace App\Services\Analytics;

use App\Models\AiUsageLog;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Consumo de IA por provedor, por usuário e por chamada, a partir de ai_usage_logs.
 *
 * Os números vêm do `usage` que cada API devolveu (normalizado em input/cache/output/raciocínio, com o original em
 * provider_usage). Tokens totais = entrada total (inclui cache) + saída. Custo = soma de estimated_cost_usd, calculado
 * na hora da chamada pela tabela de preços do driver; chamadas bem-sucedidas sem preço configurado contam em `unpriced`.
 * Chamadas sem usuário (avaliações, rotinas, comandos) ficam fora das médias por usuário e aparecem à parte.
 */
final class AiConsumptionService
{
    /** @var array<string, string> */
    public const PERIODS = ['7d' => 'Últimos 7 dias', '30d' => 'Últimos 30 dias', 'mes' => 'Este mês', 'mes-anterior' => 'Mês passado', 'tudo' => 'Todo o período'];

    /** Somatórios de uma agregação; colunas qualificadas para valerem também com o join em users. */
    private const SUMS = 'count(*) as calls, '
        ."sum(case when ai_usage_logs.status = 'failed' then 1 else 0 end) as failed, "
        .'coalesce(sum(ai_usage_logs.input_tokens), 0) as input_tokens, '
        .'coalesce(sum(ai_usage_logs.cache_read_tokens), 0) as cache_read_tokens, '
        .'coalesce(sum(ai_usage_logs.cache_write_tokens), 0) as cache_write_tokens, '
        .'coalesce(sum(ai_usage_logs.output_tokens), 0) as output_tokens, '
        .'coalesce(sum(ai_usage_logs.reasoning_tokens), 0) as reasoning_tokens, '
        .'coalesce(sum(ai_usage_logs.estimated_cost_usd), 0) as cost, '
        ."sum(case when ai_usage_logs.status = 'success' and ai_usage_logs.estimated_cost_usd is null then 1 else 0 end) as unpriced";

    /** @return array{0: CarbonImmutable|null, 1: CarbonImmutable|null} */
    public static function period(string $key): array
    {
        $now = CarbonImmutable::now();

        return match ($key) {
            '7d' => [$now->subDays(6)->startOfDay(), null],
            'mes' => [$now->startOfMonth(), null],
            'mes-anterior' => [$now->subMonthNoOverflow()->startOfMonth(), $now->subMonthNoOverflow()->endOfMonth()],
            'tudo' => [null, null],
            default => [$now->subDays(29)->startOfDay(), null],
        };
    }

    /** @return list<string> */
    public function providers(): array
    {
        return AiUsageLog::query()->distinct()->orderBy('provider')->pluck('provider')->all();
    }

    /**
     * Totais por provedor, cada um com os modelos usados. Cada IA aparece separada, com o próprio detalhe.
     *
     * @return list<array{provider: string, users: int, totals: array<string, int|float>, models: list<array<string, mixed>>}>
     */
    public function byProvider(?CarbonImmutable $from, ?CarbonImmutable $to, ?string $provider = null): array
    {
        $models = $this->base($from, $to, $provider)
            ->selectRaw('provider, model, capability, '.self::SUMS)
            ->groupBy('provider', 'model', 'capability')
            ->orderBy('provider')->orderByDesc('cost')->orderByDesc('calls')
            ->toBase()->get()
            ->groupBy('provider');

        $providers = $this->base($from, $to, $provider)
            ->selectRaw('provider, count(distinct user_id) as users, '.self::SUMS)
            ->groupBy('provider')->orderByDesc('cost')->orderBy('provider')
            ->toBase()->get();

        return $providers->map(fn (object $row): array => [
            'provider' => $row->provider,
            'users' => (int) $row->users,
            'totals' => $this->totals($row),
            'models' => ($models[$row->provider] ?? collect())->map(fn (object $model): array => [
                'model' => $model->model,
                'capability' => $model->capability,
                ...$this->totals($model),
            ])->values()->all(),
        ])->values()->all();
    }

    /**
     * Médias por usuário (só quem teve chamadas atribuídas) e o consumo sem usuário.
     *
     * @return array{users: int, cost_avg: float, cost_median: float, cost_max: float, tokens_avg: float, tokens_median: float, calls_avg: float, cost_per_call: float|null, tokens_per_call: float|null, unattributed: array<string, int|float>}
     */
    public function averages(?CarbonImmutable $from, ?CarbonImmutable $to, ?string $provider = null): array
    {
        $perUser = $this->base($from, $to, $provider)
            ->whereNotNull('user_id')
            ->selectRaw('user_id, count(*) as calls, coalesce(sum(input_tokens), 0) + coalesce(sum(output_tokens), 0) as tokens, coalesce(sum(estimated_cost_usd), 0) as cost')
            ->groupBy('user_id')
            ->toBase();

        $row = DB::query()->fromSub($perUser, 'per_user')
            ->selectRaw('count(*) as users, sum(calls) as calls, sum(tokens) as tokens, sum(cost) as cost, avg(cost) as cost_avg, max(cost) as cost_max, avg(tokens) as tokens_avg, avg(calls) as calls_avg, '
                .'percentile_cont(0.5) within group (order by cost) as cost_median, percentile_cont(0.5) within group (order by tokens) as tokens_median')
            ->first();

        $calls = (int) ($row->calls ?? 0);
        $unattributed = $this->base($from, $to, $provider)->whereNull('user_id')->selectRaw(self::SUMS)->toBase()->first();

        return [
            'users' => (int) ($row->users ?? 0),
            'cost_avg' => (float) ($row->cost_avg ?? 0),
            'cost_median' => (float) ($row->cost_median ?? 0),
            'cost_max' => (float) ($row->cost_max ?? 0),
            'tokens_avg' => (float) ($row->tokens_avg ?? 0),
            'tokens_median' => (float) ($row->tokens_median ?? 0),
            'calls_avg' => (float) ($row->calls_avg ?? 0),
            'cost_per_call' => $calls > 0 ? (float) $row->cost / $calls : null,
            'tokens_per_call' => $calls > 0 ? (float) $row->tokens / $calls : null,
            'unattributed' => $this->totals($unattributed),
        ];
    }

    /**
     * Uma linha por usuário com chamadas no período, do maior custo para o menor.
     *
     * @return LengthAwarePaginator<int, object>
     */
    public function byUser(?CarbonImmutable $from, ?CarbonImmutable $to, ?string $provider = null, int $perPage = 25): LengthAwarePaginator
    {
        $page = $this->base($from, $to, $provider)
            ->join('users', 'users.id', '=', 'ai_usage_logs.user_id')
            ->selectRaw('users.id as user_id, users.name, users.email, count(distinct (ai_usage_logs.created_at::date)) as active_days, max(ai_usage_logs.created_at) as last_used_at, '
                .self::SUMS)
            ->groupBy('users.id', 'users.name', 'users.email')
            ->orderByDesc('cost')->orderByDesc('calls')->orderBy('users.id')
            ->toBase()
            ->paginate($perPage);

        $page->getCollection()->transform(fn (object $row): object => (object) [
            'user_id' => (int) $row->user_id,
            'name' => $row->name,
            'email' => $row->email,
            'active_days' => (int) $row->active_days,
            'last_used_at' => CarbonImmutable::parse($row->last_used_at),
            ...$this->totals($row),
        ]);

        return $page;
    }

    /**
     * Consumo de um usuário: por provedor e modelo, por origem (tela ou rotina que gerou a chamada) e por dia.
     *
     * @return array{totals: array<string, int|float>, models: list<array<string, mixed>>, origins: list<array<string, mixed>>, daily: list<array<string, mixed>>}
     */
    public function forUser(int $userId, ?CarbonImmutable $from, ?CarbonImmutable $to, ?string $provider = null): array
    {
        $query = fn (): Builder => $this->base($from, $to, $provider)->where('user_id', $userId);
        $rows = fn (Builder $builder, array $columns): array => $builder->toBase()->get()
            ->map(fn (object $row): array => [...array_combine($columns, array_map(fn (string $column): mixed => $row->{$column}, $columns)), ...$this->totals($row)])
            ->all();

        return [
            'totals' => $this->totals($query()->selectRaw(self::SUMS)->toBase()->first()),
            'models' => $rows($query()->selectRaw('provider, model, capability, '.self::SUMS)->groupBy('provider', 'model', 'capability')->orderBy('provider')->orderByDesc('cost'), ['provider', 'model', 'capability']),
            'origins' => $rows($query()->selectRaw('origin, '.self::SUMS)->groupBy('origin')->orderByDesc('cost')->orderByDesc('calls'), ['origin']),
            'daily' => $rows($query()->selectRaw("to_char(created_at, 'YYYY-MM-DD') as day, ".self::SUMS)->groupByRaw("to_char(created_at, 'YYYY-MM-DD')")->orderByDesc('day')->limit(31), ['day']),
        ];
    }

    /** @return LengthAwarePaginator<int, AiUsageLog> */
    public function callsForUser(int $userId, ?CarbonImmutable $from, ?CarbonImmutable $to, ?string $provider = null, int $perPage = 20): LengthAwarePaginator
    {
        return $this->base($from, $to, $provider)->where('user_id', $userId)->latest('created_at')->latest('id')->paginate($perPage, pageName: 'chamadas');
    }

    /** @return Builder<AiUsageLog> */
    private function base(?CarbonImmutable $from, ?CarbonImmutable $to, ?string $provider): Builder
    {
        return AiUsageLog::query()
            ->when($from, fn (Builder $query) => $query->where('ai_usage_logs.created_at', '>=', $from))
            ->when($to, fn (Builder $query) => $query->where('ai_usage_logs.created_at', '<=', $to))
            ->when($provider, fn (Builder $query) => $query->where('ai_usage_logs.provider', $provider));
    }

    /** @return array{calls: int, failed: int, input_tokens: int, cache_read_tokens: int, cache_write_tokens: int, output_tokens: int, reasoning_tokens: int, total_tokens: int, cost: float, unpriced: int} */
    private function totals(?object $row): array
    {
        $input = (int) ($row->input_tokens ?? 0);
        $output = (int) ($row->output_tokens ?? 0);

        return [
            'calls' => (int) ($row->calls ?? 0),
            'failed' => (int) ($row->failed ?? 0),
            'input_tokens' => $input,
            'cache_read_tokens' => (int) ($row->cache_read_tokens ?? 0),
            'cache_write_tokens' => (int) ($row->cache_write_tokens ?? 0),
            'output_tokens' => $output,
            'reasoning_tokens' => (int) ($row->reasoning_tokens ?? 0),
            'total_tokens' => $input + $output,
            'cost' => (float) ($row->cost ?? 0),
            'unpriced' => (int) ($row->unpriced ?? 0),
        ];
    }
}
