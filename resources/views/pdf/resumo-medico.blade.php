{{--
    Resumo para a consulta (PDF, dompdf): identidade visual do BULA-IA (azul-marinho #12344D, laranja #D9661F, creme #F7F4EE).
    Layout em tabelas (o dompdf não tem flex/grid); cabeçalho e rodapé fixos se repetem em cada página.
--}}
@php
    use App\Services\Patient\MedicalSummaryService as S;
    $r = $resumo;
    $tom = fn (?int $p): string => $p === null ? 'cinza' : ($p >= 80 ? 'verde' : ($p >= 50 ? 'ambar' : 'vermelho'));
@endphp
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<title>Resumo para o médico — {{ $r['paciente']['nome'] }}</title>
<style>
    @page { margin: 118px 40px 70px 40px; }
    * { box-sizing: border-box; }
    body { font-family: 'DejaVu Sans', sans-serif; font-size: 10px; line-height: 1.45; color: #0f172a; }
    header { position: fixed; top: -98px; left: 0; right: 0; height: 78px; }
    footer { position: fixed; bottom: -50px; left: 0; right: 0; height: 36px; border-top: 1px solid #ece6da; padding-top: 6px; color: #475569; font-size: 8px; }
    footer .pagina:after { content: "Página " counter(page); }
    .faixa { width: 100%; background: #12344d; color: #fff; border-radius: 10px; border-bottom: 4px solid #d9661f; }
    .faixa td { padding: 12px 16px; vertical-align: middle; }
    .faixa .marca { font-size: 17px; font-weight: bold; letter-spacing: 0.5px; }
    .faixa .titulo { font-size: 12px; color: #d8e3ec; }
    .faixa .meta { text-align: right; font-size: 9px; color: #d8e3ec; }
    .faixa .meta strong { color: #fff; font-size: 11px; }
    h2 { font-size: 12px; color: #12344d; margin: 16px 0 6px; padding-bottom: 4px; border-bottom: 2px solid #d9661f; text-transform: uppercase; letter-spacing: 0.6px; }
    .paciente { width: 100%; background: #f7f4ee; border-radius: 8px; }
    .paciente td { padding: 8px 12px; vertical-align: top; }
    .rotulo { color: #475569; font-size: 8px; text-transform: uppercase; letter-spacing: 0.5px; }
    .valor { font-size: 11px; font-weight: bold; color: #091d2c; }
    .caixa { border-radius: 8px; padding: 8px 12px; margin-bottom: 6px; }
    .caixa .topo { font-size: 8px; font-weight: bold; text-transform: uppercase; letter-spacing: 0.6px; padding-bottom: 4px; margin-bottom: 4px; border-bottom: 1px solid; }
    .alergia { background: #fff1f2; border: 1.5px solid #fb7185; }
    .alergia .topo { color: #9f1239; border-color: #fda4af; }
    .interacao { background: #fffbeb; border: 1.5px solid #fbbf24; }
    .interacao .topo { color: #78350f; border-color: #fcd34d; }
    .nada { background: #f7f4ee; border: 1px solid #ece6da; color: #334155; }
    ul.lista { margin: 0; padding-left: 14px; }
    ul.lista li { margin: 1px 0; }
    table.dados { width: 100%; border-collapse: collapse; }
    table.dados th { background: #eef3f7; color: #12344d; font-size: 8px; text-transform: uppercase; letter-spacing: 0.4px; text-align: left; padding: 6px 6px; border-bottom: 1.5px solid #12344d; }
    table.dados td { padding: 6px 6px; border-bottom: 1px solid #e2e8f0; vertical-align: top; }
    table.dados tr { page-break-inside: avoid; }
    .nome { font-weight: bold; color: #091d2c; font-size: 10.5px; }
    .sub { color: #475569; font-size: 8.5px; }
    .selo { display: inline-block; border-radius: 9px; padding: 1px 6px; font-size: 8px; font-weight: bold; }
    .verde { background: #d1fae5; color: #065f46; }
    .ambar { background: #fef3c7; color: #78350f; }
    .vermelho { background: #ffe4e6; color: #9f1239; }
    .cinza { background: #f1f5f9; color: #334155; }
    .alta { background: #ffe4e6; color: #9f1239; }
    .media { background: #fef3c7; color: #78350f; }
    .baixa { background: #fef9c3; color: #713f12; }
    .barra { width: 70px; height: 5px; background: #e2e8f0; border-radius: 3px; margin-top: 3px; }
    .barra div { height: 5px; border-radius: 3px; }
    .barra .verde { background: #059669; } .barra .ambar { background: #d97706; } .barra .vermelho { background: #e11d48; }
    .duas td { vertical-align: top; width: 50%; }
    .linhas div { border-bottom: 1px solid #cbd5e1; height: 20px; }
    .nota { color: #475569; font-size: 8px; margin-top: 4px; }
    .vazio { color: #64748b; font-style: italic; }
</style>
</head>
<body>
<header>
    <table class="faixa"><tr>
        <td style="width: 46px;"><img src="{{ public_path('brand/bulai-icone-192.png') }}" width="38" height="38" alt=""></td>
        <td><div class="marca">BULA-IA</div><div class="titulo">Resumo de saúde para a consulta</div></td>
        <td class="meta">Paciente<br><strong>{{ $r['paciente']['nome'] }}</strong><br>Gerado em {{ $r['gerado_em']->format('d/m/Y \à\s H:i') }}</td>
    </tr></table>
</header>
<footer>
    <table style="width: 100%;"><tr>
        <td>Informações registradas pelo próprio paciente no BULA-IA. Não substituem a avaliação clínica. Interações conforme a base DDInter; a ausência de registro não garante segurança.</td>
        <td style="text-align: right; width: 70px;" class="pagina"></td>
    </tr></table>
</footer>

<main>
    <table class="paciente"><tr>
        <td><div class="rotulo">Paciente</div><div class="valor">{{ $r['paciente']['nome'] }}</div><div class="sub">{{ $r['paciente']['email'] }}</div></td>
        <td><div class="rotulo">Remédios em uso</div><div class="valor">{{ count($r['em_uso']) }}</div></td>
        <td><div class="rotulo">Alergias registradas</div><div class="valor">{{ count($r['alergias']) }}</div></td>
        <td><div class="rotulo">Adesão considerada</div><div class="valor">últimos {{ $r['periodo']['dias'] }} dias</div><div class="sub">{{ $r['periodo']['inicio']->format('d/m') }} a {{ $r['periodo']['fim']->format('d/m/Y') }}</div></td>
    </tr></table>

    <h2>Alergias e alertas</h2>
    @if ($r['alergias'] !== [])
        <div class="caixa alergia">
            <div class="topo">Alergias registradas</div>
            <ul class="lista">
                @foreach ($r['alergias'] as $alergia)<li><strong>{{ $alergia['substancia'] }}</strong>@if ($alergia['reacao']) — {{ $alergia['reacao'] }}@endif</li>@endforeach
            </ul>
        </div>
    @endif
    @php($deAlergia = array_values(array_filter($r['alertas'], fn ($a) => $a['tipo'] === 'alergia')))
    @php($deInteracao = array_values(array_filter($r['alertas'], fn ($a) => $a['tipo'] !== 'alergia')))
    @if ($deAlergia !== [])
        <div class="caixa alergia">
            <div class="topo">Alerta de reação alérgica — remédio em uso</div>
            <ul class="lista">@foreach ($deAlergia as $alerta)<li><strong>{{ $alerta['texto'] }}</strong></li>@endforeach</ul>
        </div>
    @endif
    @if ($deInteracao !== [])
        <div class="caixa interacao">
            <div class="topo">Interações entre os remédios em uso</div>
            <ul class="lista">
                @foreach ($deInteracao as $alerta)
                    <li><strong>{{ $alerta['texto'] }}</strong>@if ($alerta['gravidade']) <span class="selo {{ S::severityTone($alerta['gravidade']) }}">Gravidade: {{ $alerta['gravidade'] }}</span>@else <span class="selo ambar">Possível duplicidade</span>@endif</li>
                @endforeach
            </ul>
        </div>
    @endif
    @if ($r['alergias'] === [] && $r['alertas'] === [])
        <div class="caixa nada">Nenhuma alergia registrada e nenhuma interação registrada entre os remédios em uso.</div>
    @endif

    <h2>Remédios em uso</h2>
    @if ($r['em_uso'] === [])
        <p class="vazio">Nenhum remédio em uso registrado.</p>
    @else
        <table class="dados">
            <thead><tr><th style="width: 27%;">Remédio</th><th style="width: 15%;">Dose</th><th style="width: 22%;">Como toma</th><th style="width: 17%;">Período e receita</th><th style="width: 19%;">Adesão ({{ $r['periodo']['dias'] }} dias)</th></tr></thead>
            <tbody>
                @foreach ($r['em_uso'] as $item)
                    <tr>
                        <td><div class="nome">{{ $item['nome'] }}</div>@if ($item['principio_ativo'])<div class="sub">{{ $item['principio_ativo'] }}</div>@endif @if ($item['observacoes'])<div class="sub">Obs.: {{ $item['observacoes'] }}</div>@endif</td>
                        <td>{{ $item['dose'] ?: '—' }}</td>
                        <td>{{ $item['como'] }}</td>
                        <td><div>{{ $item['inicio'] ? 'desde '.$item['inicio'] : 'início não informado' }}</div>@if ($item['fim'])<div>até {{ $item['fim'] }}</div>@else<div class="sub">uso contínuo</div>@endif @if ($item['prescritor'])<div class="sub">Receita: {{ $item['prescritor'] }}</div>@endif</td>
                        <td>
                            @if ($item['adesao'] === null)
                                <span class="sub">{{ str_contains($item['como'], 'precisar') ? 'Uso conforme necessidade' : 'Sem doses no período' }}</span>
                            @else
                                @php($p = $item['adesao']['percentual'])
                                <span class="selo {{ $tom($p) }}">{{ $p }}% tomadas</span>
                                <div class="barra"><div class="{{ $tom($p) }}" style="width: {{ max(2, $p) }}%;"></div></div>
                                <div class="sub">{{ $item['adesao']['tomadas'] }} de {{ $item['adesao']['previstas'] }} doses · {{ $item['adesao']['puladas'] }} puladas · {{ $item['adesao']['sem_marcacao'] }} sem marcação</div>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        <p class="nota">Adesão = doses marcadas como “Tomei” no aplicativo. “Sem marcação” significa que o horário passou sem registro, não necessariamente que a dose não foi tomada.</p>
    @endif

    <table class="duas" style="width: 100%; margin-top: 4px;"><tr>
        <td style="padding-right: 10px;">
            <h2>Encerrados nos últimos {{ S::ENDED_DAYS }} dias</h2>
            @forelse ($r['encerrados'] as $item)
                <div><span class="nome">{{ $item['nome'] }}</span>@if ($item['dose']) <span class="sub">· {{ $item['dose'] }}</span>@endif <span class="sub">· terminou em {{ $item['fim'] }}</span></div>
            @empty
                <p class="vazio">Nenhum tratamento encerrado no período.</p>
            @endforelse
        </td>
        <td style="padding-left: 10px;">
            <h2>Receitas registradas</h2>
            @forelse ($r['receitas'] as $receita)
                <div style="margin-bottom: 5px;"><span class="nome">{{ $receita['prescritor'] }}</span> <span class="sub">{{ collect([$receita['registro'], $receita['data']])->filter()->join(' · ') }}</span>@if ($receita['remedios'] !== [])<div class="sub">{{ implode(', ', $receita['remedios']) }}</div>@endif</div>
            @empty
                <p class="vazio">Nenhuma receita registrada.</p>
            @endforelse
        </td>
    </tr></table>

    <h2>Dúvidas recentes do paciente</h2>
    @forelse ($r['duvidas'] as $duvida)
        <div style="margin-bottom: 3px;"><span class="sub">{{ $duvida['data'] }}</span> — “{{ $duvida['pergunta'] }}”</div>
    @empty
        <p class="vazio">Nenhuma pergunta feita no período.</p>
    @endforelse

    <h2>Anotações da consulta</h2>
    <div class="linhas"><div></div><div></div><div></div><div></div><div></div></div>
</main>
</body>
</html>
