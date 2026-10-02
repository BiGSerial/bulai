@props(['columns'])
<div {{ $attributes->class('overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm') }}>
    <div class="overflow-x-auto">
        <table class="ui-table min-w-full divide-y divide-slate-200 text-sm">
            <thead><tr>@foreach ($columns as $column)<th scope="col">{{ $column }}</th>@endforeach</tr></thead>
            <tbody class="divide-y divide-slate-100">{{ $slot }}</tbody>
        </table>
    </div>
</div>
