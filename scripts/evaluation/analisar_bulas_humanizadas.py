"""Legibilidade das bulas humanizadas (GET /api/v1/audit/leaflet-summaries): índice de Flesch adaptado (Martins et al., 1996)
por bula, por seção e contra os trechos de origem, termos técnicos e palavras longas. O índice mede facilidade de leitura,
não compreensão; nomes de fármacos são palavras longas e baixam o índice mesmo quando a frase é clara.
Uso: python3 analisar_bulas_humanizadas.py BULAS.json SAIDA.json"""
import json
import re
import statistics
import sys
import unicodedata
from collections import Counter, defaultdict

data = json.load(open(sys.argv[1], encoding='utf8'))
summaries = data['summaries']
TECHNICAL = ['hipersensibilidade', 'concomitante', 'insuficiencia', 'hepatic', 'posologia', 'contraindica', 'administra', 'hipotens',
             'angioedema', 'arritmia', 'hipercalemia', 'plasmatic', 'metaboli', 'farmacolog', 'intravenos', 'sistemic', 'gastrointestina',
             'anafila', 'broncoespasmo', 'teratogen', 'profilax', 'hemorrag', 'agranulocit', 'nefrotox', 'hepatotox', 'excipiente',
             'anticolinergic', 'adrenergic', 'agonista', 'atelectasia', 'mucoviscidose', 'microrganism']


def plain(text):
    return unicodedata.normalize('NFKD', text or '').encode('ascii', 'ignore').decode().lower()


def band(index):
    return None if index is None else 'muito fácil' if index >= 75 else 'fácil' if index >= 50 else 'difícil' if index >= 25 else 'muito difícil'


rows, by_section, sentences = [], defaultdict(list), []
for s in summaries:
    text, source = (s['readability'] or {}).get('summary') or {}, (s['readability'] or {}).get('sources') or {}
    claims = [c for x in s['sections'] for c in x['claims']]
    sentences += [(s['prompt_version'], c) for c in claims]
    for x in s['sections']:
        if x['claims'] and x['readability'] and s['prompt_version'] == summaries[0]['prompt_version']:
            by_section[x['label']].append(x['readability']['index'])
    rows.append({'id': s['id'], 'prompt_version': s['prompt_version'], 'status': s['status'], 'claims': len(claims),
                 'flesch': text.get('index'), 'band': text.get('band'), 'source_flesch': source.get('index'), 'source_band': source.get('band'),
                 'easier_than_source': None if text.get('index') is None or source.get('index') is None else text['index'] > source['index'],
                 'words_per_sentence': round(text['words'] / text['sentences'], 1) if text.get('sentences') else None})

current = [r for r in rows if r['prompt_version'] == summaries[0]['prompt_version']]
technical = [c for _, c in sentences if any(t in plain(c) for t in TECHNICAL)]
out = {
    'summaries': len(rows),
    'current_prompt': summaries[0]['prompt_version'],
    'current': {
        'n': len(current),
        'bands': dict(Counter(r['band'] for r in current)),
        'flesch_median': statistics.median(r['flesch'] for r in current),
        'source_flesch_median': statistics.median(r['source_flesch'] for r in current),
        'easier_than_source': f"{sum(1 for r in current if r['easier_than_source'])}/{len(current)}",
        'words_per_sentence_median': statistics.median(r['words_per_sentence'] for r in current),
    },
    'by_section_median': {k: {'median': statistics.median(v), 'n': len(v), 'band': band(statistics.median(v))} for k, v in sorted(by_section.items(), key=lambda kv: statistics.median(kv[1]))},
    'sentences': len(sentences),
    'sentences_with_listed_technical_terms': len(technical),
    'technical_terms': dict(Counter(t for _, c in sentences for t in TECHNICAL if t in plain(c)).most_common()),
    'long_words_13_plus': dict(Counter(w.lower() for _, c in sentences for w in re.findall(r'\w+', c) if len(w) >= 13).most_common(20)),
    'rows': rows,
}
json.dump(out, open(sys.argv[2], 'w', encoding='utf8'), ensure_ascii=False, indent=1)
print(json.dumps({k: v for k, v in out.items() if k != 'rows'}, ensure_ascii=False, indent=1))
