"""Tabelas em Markdown do relatório docs/qa a partir de analise.json. Uso: python3 relatorio.py ANALISE.json"""
import json, sys

a = json.load(open(sys.argv[1]))
STATUS = ['answered', 'needs_info', 'no_evidence', 'no_leaflet', 'no_medicine', 'urgent', 'out_of_scope']


def pct(w):
    if not w or w.get('rate') is None:
        return '—'
    f = lambda v: f"{v * 100:.1f}".replace('.', ',')
    return f"{w['k']}/{w['n']} = {f(w['rate'])}% (IC 95%: {f(w['low'])}–{f(w['high'])})"


def table_status(groups, title):
    statuses = [s for s in STATUS if any(s in g for g in groups.values())] + sorted({s for g in groups.values() for s in g} - set(STATUS))
    print(f'| {title} | ' + ' | '.join(statuses) + ' | Total |')
    print('| --- | ' + ' | '.join('---' for _ in statuses) + ' | --- |')
    for name, counts in groups.items():
        print(f'| {name} | ' + ' | '.join(str(counts.get(s, 0)) for s in statuses) + f' | {sum(counts.values())} |')
    totals = {s: sum(g.get(s, 0) for g in groups.values()) for s in statuses}
    print('| **Total** | ' + ' | '.join(f'**{totals[s]}**' for s in statuses) + f' | **{sum(totals.values())}** |')


print('### Status por assunto (bula brasileira)\n')
table_status({k: v for k, v in a['status_by_subject'].items() if k in ('indicacao', 'posologia', 'efeitos', 'contraindicacao', 'armazenamento', 'gravidez', 'interacao', 'superdose')}, 'Assunto')
print('\n### Status por bloco\n')
table_status(a['status_by_block'], 'Bloco')
print('\n### Indicadores\n')
print('| Indicador | Resultado |')
print('| --- | --- |')
print(f"| Remédio certo identificado (bula brasileira, marcas, conversa) | {pct(a['medicine_identified'])} |")
print(f"| Respostas em que **todas** as fontes são bulas do remédio perguntado | {pct(a['answers_with_every_source_from_the_medicine'])} |")
print(f"| Respostas com ao menos uma fonte da seção do assunto | {pct(a['a_source_from_the_subject_section'])} |")
print(f"| Respondidas com ao menos uma afirmação aceita | {pct(a['answered_with_accepted_claim'])} |")
print(f"| Afirmações aceitas pelo validador | {pct(a['claims']['acceptance'])} |")
print(f"| Trechos crus da bula exibidos ao paciente | {a['raw_excerpts_shown']} |")
print(f"| Leitura da bula inteira (quando os trechos não bastaram) | {a['leaflet_fallback_used']} perguntas |")
print(f"| Cobertura das respostas sobre bula brasileira | " + ', '.join(f'{k}: {v}' for k, v in a['coverage_brazil'].items()) + ' |')
print(f"| Fontes citadas, por tipo | " + ', '.join(f'{k}: {v}' for k, v in a['source_kinds'].items()) + ' |')
print(f"| Motivos de rejeição de afirmações | " + ', '.join(f'{k}: {v}' for k, v in sorted(a['claims']['rejection_reasons'].items(), key=lambda x: -x[1])) + ' |')
l = a['latency_seconds']
print(f"| Tempo de resposta no servidor (s) | mediana {l['server_p50']}, p95 {l['server_p95']}, máx. {l['server_max']}, média {l['mean']} |")
t = a['tokens']
print(f"| Tokens (entrada/saída) | geração {t.get('generation_in', 0)}/{t.get('generation_out', 0)}; interpretação {t.get('interpretation_in', 0)}/{t.get('interpretation_out', 0)}; leitura da bula {t.get('leaflet_in', 0)}/{t.get('leaflet_out', 0)} |")
print(f"| Modelos de geração | " + ', '.join(f'{k}: {v}' for k, v in a['generation_models'].items()) + ' |')
print('\n### Rubrica automática das respostas (respondidas)\n')
print('| Dimensão | Resultado |')
print('| --- | --- |')
for dim, w in a['rubric_answered'].items():
    print(f'| {dim} | {pct(w)} |')
