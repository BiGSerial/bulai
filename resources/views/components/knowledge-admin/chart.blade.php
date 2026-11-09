{{--
    Gráfico Chart.js (barras horizontais/verticais, linha ou rosca) com rótulos de valor (chartjs-plugin-datalabels).
    Dois jeitos de informar os dados:
      · `items` (chave => valor) + `labels` (chave => rótulo) + `tones` (chave => tom): uma série, usada nas distribuições;
      · `categories` (lista de rótulos do eixo) + `series` (lista de ['label', 'data', 'tone']): várias séries, usada em evolução diária.
    `kind`: bar (horizontal) | column | line | doughnut. `format`: int | usd | ms | percent. `height`: classe de altura do Tailwind.
    O gráfico é montado por `resources/js/charts.js` (Alpine `bulaiChart`). O quadro é recriado quando os dados mudam (`wire:key` com hash)
    e fica sob `wire:ignore`, para o Livewire não apagar o canvas. Tabela `sr-only` espelha os números para leitores de tela.
--}}
@props(['kind' => 'bar', 'items' => null, 'labels' => [], 'tones' => [], 'categories' => [], 'series' => [], 'format' => 'int', 'stacked' => false, 'legend' => null, 'height' => 'h-56', 'empty' => 'Sem dados no período.', 'ariaLabel' => 'Gráfico', 'valueLabels' => true])
@php
    if ($items !== null) {
        $rows = collect($items)->filter(fn ($value) => (float) $value > 0);
        $categories = $rows->keys()->map(fn ($key) => (string) ($labels[$key] ?? $key))->values()->all();
        $series = [['label' => $ariaLabel, 'data' => $rows->values()->map(fn ($value) => $value + 0)->all(), 'tones' => $rows->keys()->map(fn ($key) => $tones[$key] ?? null)->values()->all()]];
    }
    $hasData = collect($series)->contains(fn (array $serie) => collect($serie['data'])->contains(fn ($value) => $value > 0));
    $config = ['kind' => $kind, 'labels' => array_values($categories), 'series' => array_values($series), 'format' => $format, 'stacked' => $stacked, 'legend' => $legend, 'valueLabels' => $valueLabels];
    $fingerprint = md5(json_encode($config));
@endphp
@if (! $hasData)
    <p class="text-sm text-slate-500">{{ $empty }}</p>
@else
    <figure {{ $attributes->class('m-0') }} wire:key="chart-{{ $fingerprint }}" wire:ignore>
        <div class="relative {{ $height }}" x-data="bulaiChart(@js($config))">
            <canvas x-ref="canvas" role="img" aria-label="{{ $ariaLabel }}"></canvas>
        </div>
        <table class="sr-only">
            <caption>{{ $ariaLabel }}</caption>
            <thead><tr><th scope="col">Categoria</th>@foreach ($series as $serie)<th scope="col">{{ $serie['label'] }}</th>@endforeach</tr></thead>
            <tbody>
                @foreach ($categories as $i => $category)
                    <tr><th scope="row">{{ $category }}</th>@foreach ($series as $serie)<td>{{ $serie['data'][$i] ?? 0 }}</td>@endforeach</tr>
                @endforeach
            </tbody>
        </table>
    </figure>
@endif
