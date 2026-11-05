@extends('layouts.auth')
@section('title', 'Histórico da conta')
@section('width', 'lg')
@section('heading', 'Histórico da conta')
@section('lead', 'Eventos de acesso e segurança da sua conta, do mais recente para o mais antigo.')
@section('content')
    <x-knowledge-admin.section>
        <ol class="grid gap-3">
            @forelse ($events as $event)
                <li class="border-l-2 border-brand-200 pl-3">
                    <p class="text-sm font-medium text-slate-900">{{ \App\Models\UserAuditEvent::label($event->event) }}</p>
                    @if (! empty($event->details['reason']))<p class="text-sm text-slate-600">Motivo: {{ $event->details['reason'] }}</p>@endif
                    <p class="text-sm text-slate-500">{{ $event->created_at?->timezone(config('app.timezone'))->format('d/m/Y H:i') }}@if ($event->ip) · IP {{ $event->ip }}@endif @if ($event->actor && $event->actor_id !== $user->id) · por {{ $event->actor->name }}@endif</p>
                </li>
            @empty
                <li class="text-sm text-slate-500">Nenhum evento registrado.</li>
            @endforelse
        </ol>
        {{ $events->links() }}
    </x-knowledge-admin.section>
    <div><a class="text-sm font-medium text-brand-700 transition hover:text-brand-800" href="{{ route('account') }}">← Voltar para Minha conta</a></div>
@endsection
