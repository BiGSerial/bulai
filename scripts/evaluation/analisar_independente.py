"""Análise da rodada independente (freeform.independent.v1). Separa identificação, consulta ao DDInter, recuperação e geração.
Uso: python3 analisar_independente.py RESULTADOS.json SAIDA.json"""
import json
import os
import re
import sys
import unicodedata
from collections import Counter

DATASET = os.path.join(os.path.dirname(__file__), '..', '..', 'database', 'evaluation', 'freeform.independent.v1.json')
dataset = json.load(open(DATASET, encoding='utf8'))
results = {r['id']: r for r in json.load(open(sys.argv[1], encoding='utf8'))['results']}
KEYS = dataset['keys']
SEVERITY_WORDS = r'\b(grave|gravidade maior|moderad|leve|gravidade menor|nao classificad)'
SAFETY_CLAIM = r'(e|sao|seria) segur[ao]s?\b(?! (porque|so))|pode tomar (os dois|juntos) sem (problema|risco)|nao ha (nenhuma )?interacao'


def plain(text):
    return unicodedata.normalize('NFKD', str(text or '')).encode('ascii', 'ignore').decode().lower()


def has(medicine_key, text):
    return any(k in plain(text) for k in KEYS.get(medicine_key, [medicine_key]))


rows = []
for item in dataset['items']:
    r = results.get(item['id'])
    if r is None or r['http'] != 200:
        rows.append({'id': item['id'], 'error': r and r['http']})
        continue
    resp, rec = r['response'], r.get('record') or {}
    meds = rec.get('medicines') or []
    resolved_text = ' | '.join(f"{m.get('name')} {m.get('ingredients')}" for m in meds)
    unresolved = (rec.get('retrieval') or {}).get('unresolved_names') or (resp.get('retrieval') or {}).get('unresolved_names') or []
    ident = {}
    for m in item['expected_medicines']:
        if has(m, resolved_text):
            ident[m] = 'identificado'
        elif any(has(m, u) for u in unresolved):
            ident[m] = 'não reconhecido, declarado'
        else:
            ident[m] = 'não reconhecido, sem aviso'
    pairs = resp.get('pair_interactions') or []
    ddinter = []
    for p in item['pairs']:
        match = next((x for x in pairs if (has(p['a'], x['a']) and has(p['b'], x['b'])) or (has(p['a'], x['b']) and has(p['b'], x['a']))), None)
        predicted = None if match is None else match['severity']
        if match is None:
            outcome = 'par não consultado'
        elif predicted == 'unverified':
            outcome = 'não verificado (declarado)'
        elif (predicted or None) == p['gold_severity']:
            outcome = 'correto'
        elif p['gold_severity'] is None:
            outcome = 'falso positivo'
        elif predicted is None:
            outcome = 'falso negativo'
        else:
            outcome = 'gravidade divergente'
        ddinter.append({'pair': f"{p['a']} + {p['b']}", 'gold': p['gold_severity'], 'predicted': predicted, 'outcome': outcome})
    evidences = [e for e in rec.get('evidences') or [] if e.get('method') != 'structured']
    gen = rec.get('generation') or {}
    answer = resp.get('answer') or resp.get('message') or ''
    limitations = ' '.join(resp.get('limitations') or [])
    gold_positive = any(p['gold_interaction'] for p in item['pairs'])
    rows.append({
        'id': item['id'], 'category': item['category'], 'question': item['question'], 'status': resp.get('status'),
        'identification': ident, 'unresolved_names': unresolved, 'resolved': [m.get('name') for m in meds],
        'ddinter': ddinter,
        'retrieval': {'excerpts': len(evidences), 'interaction_section': sum(1 for e in evidences if re.search(r'INTERA|O QUE DEVO SABER ANTES|ADVERT', plain(e.get('section')).upper())),
                      'methods': dict(Counter(e.get('method') for e in evidences)), 'foreign': sum(1 for e in evidences if 'FDA' in str(e.get('source', '')).upper())},
        'generation': {'accepted': gen.get('claims_accepted'), 'rejected': gen.get('claims_rejected'), 'reasons': gen.get('rejection_reasons'), 'leaflet_fallback': rec.get('leaflet_fallback_used')},
        'answer_mentions_severity': bool(re.search(SEVERITY_WORDS, plain(answer))),
        'limitations_state_severity': 'registra interacao' in plain(limitations),
        'safety_claim': bool(re.search(SAFETY_CLAIM, plain(answer))) and (gold_positive or item['id'] == 'R5'),
        'answer': answer, 'limitations': resp.get('limitations'), 'uuid': resp.get('uuid'), 'seconds': (rec.get('duration_ms') or 0) / 1000,
    })

ok = [r for r in rows if 'error' not in r]
ident = Counter(v for r in ok for v in r['identification'].values())
dd = Counter(d['outcome'] for r in ok for d in r['ddinter'])
by_gold = {}
for r in ok:
    for d in r['ddinter']:
        by_gold.setdefault(str(d['gold']), Counter())[d['outcome']] += 1
summary = {
    'items': len(rows), 'answered_http': len(ok), 'status': dict(Counter(r['status'] for r in ok)),
    'identification': dict(ident),
    'ddinter': dict(dd), 'ddinter_by_gold': {k: dict(v) for k, v in by_gold.items()},
    'answers_mentioning_severity_when_registered': sum(1 for r in ok if any(d['predicted'] not in (None, 'unverified', 'duplicate') for d in r['ddinter']) and r['answer_mentions_severity']),
    'answers_with_registered_pair': sum(1 for r in ok if any(d['predicted'] not in (None, 'unverified', 'duplicate') for d in r['ddinter'])),
    'limitations_with_severity': sum(1 for r in ok if r['limitations_state_severity']),
    'safety_claims': [r['id'] for r in ok if r['safety_claim']],
    'claims': {'accepted': sum(r['generation']['accepted'] or 0 for r in ok), 'rejected': sum(r['generation']['rejected'] or 0 for r in ok),
               'reasons': dict(sum((Counter(r['generation']['reasons'] or {}) for r in ok), Counter()))},
    'leaflet_fallback': sum(1 for r in ok if r['generation']['leaflet_fallback']),
    'seconds_median': sorted(r['seconds'] for r in ok)[len(ok) // 2] if ok else None,
}
json.dump({'summary': summary, 'rows': rows}, open(sys.argv[2], 'w', encoding='utf8'), ensure_ascii=False, indent=1)
print(json.dumps(summary, ensure_ascii=False, indent=1))
