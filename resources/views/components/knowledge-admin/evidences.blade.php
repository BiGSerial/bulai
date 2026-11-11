{{-- Lista completa de evidências (trechos) usadas em uma resposta, com quais afirmações cada uma sustenta: índice (pares e perguntas) ou "seção#índice" (bula humanizada, "Para que serve 1"). --}}
@props(['evidences' => []])
@if (empty($evidences))
    <p class="text-sm text-slate-500">Nenhuma evidência recuperada.</p>
@else
    <ol {{ $attributes->class('grid gap-3') }}>
        @foreach ($evidences as $index => $evidence)
            <li class="grid gap-1 rounded-lg border border-slate-200 bg-slate-50 p-3 text-sm">
                <p class="font-semibold text-slate-900">E{{ $index + 1 }} · {{ $evidence['document_title'] ?? 'Documento' }} <span class="font-normal text-slate-500">({{ $evidence['document_type'] ?? '—' }})</span></p>
                <p class="text-xs text-slate-600">Trecho #{{ $evidence['chunk_id'] ?? '?' }} · Documento #{{ $evidence['document_id'] ?? '?' }} · Seção: {{ $evidence['section'] ?? 'não identificada' }} · Página {{ $evidence['page_start'] ?? '?' }}@if (($evidence['page_end'] ?? null) && $evidence['page_end'] !== ($evidence['page_start'] ?? null))–{{ $evidence['page_end'] }}@endif · Origem: {{ $evidence['source'] ?? '—' }} · Versão: {{ $evidence['document_version'] ?? '—' }} · Relevância ({{ $evidence['method'] ?? '—' }}): {{ isset($evidence['score']) ? number_format((float) $evidence['score'], 4, ',', '.') : '—' }}</p>
                <blockquote class="max-h-72 overflow-y-auto whitespace-pre-line rounded bg-white px-3 py-2 text-xs leading-relaxed text-slate-700 ring-1 ring-slate-200">{{ $evidence['excerpt'] ?? '' }}</blockquote>
                <p class="text-xs text-slate-500">
                    @if (! empty($evidence['supports_claims']))Sustenta a(s) afirmação(ões) {{ collect($evidence['supports_claims'])->map(fn ($claim) => is_numeric($claim) ? (int) $claim + 1 : (preg_match('/^(.+)#(\d+)$/', (string) $claim, $m) === 1 ? (config('bulai.leaflet.sections.'.$m[1].'.label') ?? $m[1]).' '.((int) $m[2] + 1) : $claim))->join(', ') }}.
                    @else Recuperada, mas não citada em nenhuma afirmação aceita.@endif
                </p>
            </li>
        @endforeach
    </ol>
@endif
