// Gráficos do painel administrativo (Chart.js + chartjs-plugin-datalabels).
// O Chart.js só é baixado quando existe um gráfico na página (import dinâmico): o app do paciente não paga por ele.
// Uso: <div x-data="bulaiChart({...})"><canvas x-ref="canvas"></canvas></div> (ver components/knowledge-admin/chart.blade.php).

const TONES = {
    default: '#1f4a66',
    brand: '#1f4a66',
    soft: '#5a83a1',
    accent: '#d9661f',
    danger: '#e11d48',
    warning: '#f59e0b',
    info: '#0ea5e9',
    success: '#10b981',
    muted: '#94a3b8',
};
const CATEGORICAL = ['#1f4a66', '#d9661f', '#0ea5e9', '#10b981', '#f59e0b', '#8b5cf6', '#e11d48', '#94a3b8'];
const INK = '#334155';
const GRID = '#e2e8f0';

const nf = new Intl.NumberFormat('pt-BR', { maximumFractionDigits: 0 });
const formatters = {
    int: (v) => nf.format(v),
    usd: (v) => 'US$ ' + new Intl.NumberFormat('pt-BR', { minimumFractionDigits: 4, maximumFractionDigits: 4 }).format(v),
    ms: (v) => nf.format(v) + ' ms',
    percent: (v) => new Intl.NumberFormat('pt-BR', { maximumFractionDigits: 1 }).format(v) + '%',
};

let loading = null;

function loadChartJs() {
    loading ??= Promise.all([import('chart.js'), import('chartjs-plugin-datalabels')]).then(([lib, labels]) => {
        const { Chart, BarController, LineController, DoughnutController, BarElement, LineElement, PointElement, ArcElement, CategoryScale, LinearScale, Tooltip, Legend, Filler } = lib;
        Chart.register(BarController, LineController, DoughnutController, BarElement, LineElement, PointElement, ArcElement, CategoryScale, LinearScale, Tooltip, Legend, Filler, labels.default);
        Chart.defaults.font.family = getComputedStyle(document.body).fontFamily;
        Chart.defaults.font.size = 12;
        Chart.defaults.color = INK;
        Chart.defaults.plugins.datalabels.display = false;
        Chart.defaults.plugins.tooltip.backgroundColor = '#0f172a';
        Chart.defaults.plugins.tooltip.padding = 10;
        Chart.defaults.plugins.tooltip.cornerRadius = 8;

        return Chart;
    });

    return loading;
}

function toneColor(tone, index) {
    return TONES[tone] ?? CATEGORICAL[index % CATEGORICAL.length];
}

function withAlpha(hex, alpha) {
    const n = parseInt(hex.slice(1), 16);

    return `rgba(${(n >> 16) & 255}, ${(n >> 8) & 255}, ${n & 255}, ${alpha})`;
}

/**
 * @param {{kind: 'bar'|'column'|'line'|'doughnut', labels: string[], series: {label: string, data: number[], tone?: string, tones?: string[]}[], format?: string, stacked?: boolean, legend?: boolean, valueLabels?: boolean}} config
 */
function buildConfig(config) {
    const format = formatters[config.format ?? 'int'] ?? formatters.int;
    const kind = config.kind;
    const horizontal = kind === 'bar';
    const isLine = kind === 'line';
    const isDoughnut = kind === 'doughnut';
    const multi = config.series.length > 1;

    const datasets = config.series.map((serie, i) => {
        // Sem tom informado: rosca usa a paleta categórica (uma cor por fatia); barras mantêm a cor da marca.
        const perPoint = serie.tones?.map((tone, j) => (tone ?? (isDoughnut ? null : 'brand')) === null ? CATEGORICAL[j % CATEGORICAL.length] : toneColor(tone ?? 'brand', j));
        const color = toneColor(serie.tone, i);
        if (isDoughnut) {
            return { label: serie.label, data: serie.data, backgroundColor: perPoint ?? serie.data.map((_, j) => toneColor(undefined, j)), borderColor: '#fff', borderWidth: 2, hoverOffset: 6 };
        }
        if (isLine) {
            return { label: serie.label, data: serie.data, borderColor: color, backgroundColor: withAlpha(color, multi ? 0.08 : 0.16), fill: true, tension: 0.35, borderWidth: 2, pointRadius: serie.data.length > 45 ? 0 : 3, pointHoverRadius: 5, pointBackgroundColor: '#fff', pointBorderColor: color, pointBorderWidth: 2 };
        }

        return { label: serie.label, data: serie.data, backgroundColor: perPoint ?? color, borderRadius: 6, borderSkipped: false, maxBarThickness: horizontal ? 22 : 38 };
    });

    const total = (values) => values.reduce((sum, v) => sum + v, 0);
    const dataLabels = config.valueLabels === false ? { display: false } : isDoughnut
        ? { display: (ctx) => ctx.dataset.data[ctx.dataIndex] / total(ctx.dataset.data) >= 0.06, color: '#fff', font: { weight: 600 }, formatter: (v, ctx) => formatters.percent((v / total(ctx.dataset.data)) * 100) }
        : isLine
            ? { display: false }
            : config.stacked
                ? { display: (ctx) => ctx.dataset.data[ctx.dataIndex] > 0, color: '#fff', font: { weight: 600, size: 11 }, formatter: (v) => format(v) }
                : { display: (ctx) => ctx.dataset.data[ctx.dataIndex] > 0, anchor: 'end', align: horizontal ? 'right' : 'top', offset: 2, color: INK, font: { weight: 600, size: 11 }, clamp: true, formatter: (v, ctx) => (horizontal && !multi ? `${format(v)}  ·  ${formatters.percent((v / total(ctx.dataset.data)) * 100)}` : format(v)) };

    const valueAxis = { beginAtZero: true, grace: horizontal && !config.stacked ? '38%' : '12%', border: { display: false }, grid: { color: GRID }, ticks: { precision: 0, callback: (v) => format(v) }, stacked: Boolean(config.stacked) };
    const labelAxis = { grid: { display: false }, border: { color: GRID }, stacked: Boolean(config.stacked), ticks: { autoSkip: true, maxRotation: 0, ...(horizontal ? { callback(value) { const label = this.getLabelForValue(value); return label.length > 34 ? label.slice(0, 33) + '…' : label; } } : {}) } };

    return {
        type: isDoughnut ? 'doughnut' : isLine ? 'line' : 'bar',
        data: { labels: config.labels, datasets },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            indexAxis: horizontal ? 'y' : 'x',
            interaction: { mode: isLine ? 'index' : 'nearest', intersect: !isLine },
            animation: { duration: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 0 : 500 },
            cutout: isDoughnut ? '62%' : undefined,
            layout: { padding: { top: 8, right: 4 } },
            scales: isDoughnut ? {} : horizontal ? { x: valueAxis, y: labelAxis } : { x: labelAxis, y: valueAxis },
            plugins: {
                legend: { display: config.legend ?? (multi || isDoughnut), position: isDoughnut ? 'right' : 'bottom', labels: { usePointStyle: true, pointStyle: 'circle', boxWidth: 8, boxHeight: 8, padding: 14, color: INK } },
                datalabels: dataLabels,
                tooltip: {
                    callbacks: {
                        label(ctx) {
                            const value = ctx.parsed.y ?? ctx.parsed.x ?? ctx.parsed;
                            const name = ctx.dataset.label && !isDoughnut ? `${ctx.dataset.label}: ` : isDoughnut ? `${ctx.label}: ` : '';
                            const share = isDoughnut || (horizontal && !multi) ? `  (${formatters.percent((value / total(ctx.dataset.data)) * 100)})` : '';

                            return name + format(value) + share;
                        },
                    },
                },
            },
        },
    };
}

function bulaiChart(config) {
    return {
        chart: null,
        async init() {
            if (!config.series?.some((serie) => serie.data.some((v) => v > 0))) {
                return;
            }
            const Chart = await loadChartJs();
            if (!this.$refs.canvas?.isConnected) {
                return;
            }
            this.chart = new Chart(this.$refs.canvas, buildConfig(config));
            this.$el.dataset.ready = 'true';
        },
        destroy() {
            this.chart?.destroy();
            this.chart = null;
        },
    };
}

document.addEventListener('alpine:init', () => {
    window.Alpine?.data('bulaiChart', bulaiChart);
});
