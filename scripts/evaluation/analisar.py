"""Resume a bateria de produção: status, cobertura, remédio certo, fonte certa, seção certa, validação, notas, tempos, custo.
Uso: python3 analisar.py BATERIA.json SAIDA.json"""
import json, math, re, statistics, sys, unicodedata
from collections import Counter, defaultdict

data = json.load(open(sys.argv[1]))
rows = [r for r in data['results'] if r['http'] == 200]


def plain(text):
    return unicodedata.normalize('NFKD', str(text)).encode('ascii', 'ignore').decode().lower()


def wilson(k, n, z=1.96):
    if n == 0:
        return {'rate': None, 'low': None, 'high': None, 'n': 0, 'k': 0}
    p = k / n
    d = 1 + z * z / n
    c = (p + z * z / (2 * n)) / d
    h = z * math.sqrt(p * (1 - p) / n + z * z / (4 * n * n)) / d
    return {'rate': round(p, 4), 'low': round(max(0, c - h), 4), 'high': round(min(1, c + h), 4), 'n': n, 'k': k}


def pct(values, q):
    values = sorted(values)
    if not values:
        return None
    i = (len(values) - 1) * q
    lo, hi = math.floor(i), math.ceil(i)
    return round(values[lo] + (values[hi] - values[lo]) * (i - lo), 1)


SECTION = {
    'indicacao': r'INDICAC|PARA QUE|COMO ESTE MEDICAMENTO FUNCIONA',
    'posologia': r'POSOLOGIA|COMO DEVO USAR|MODO DE USAR|MODO DE USO',
    'efeitos': r'REACOES ADVERSAS|QUAIS OS MALES',
    'contraindicacao': r'CONTRAINDICAC|QUANDO NAO DEVO USAR',
    'armazenamento': r'ARMAZENAMENTO|ONDE, COMO E POR QUANTO TEMPO',
    'gravidez': r'ADVERTENCIAS|PRECAUC|O QUE DEVO SABER ANTES|CONTRAINDICAC|QUANDO NAO DEVO USAR|GRAVIDEZ|GESTA',
    'interacao': r'INTERAC|O QUE DEVO SABER ANTES|ADVERTENCIAS|PRECAUC',
    'superdose': r'SUPERDOSE|QUANTIDADE MAIOR',
}

out = {'run_started': data.get('run_started'), 'run_finished': data.get('run_finished'), 'asked': len(data['results']), 'http_200': len(rows),
       'http_other': Counter(r['http'] for r in data['results'] if r['http'] != 200)}
out['status'] = Counter(r['response'].get('status') for r in rows)
by_block = defaultdict(Counter)
by_subject = defaultdict(Counter)
for r in rows:
    by_block[r['block']][r['response'].get('status')] += 1
    by_subject[r['subject']][r['response'].get('status')] += 1
out['status_by_block'] = by_block
out['status_by_subject'] = by_subject

DIP = {'Dipirona', 'Dipirona Monoidratada', 'Dipirona Sódica'}
LOS = {'Losartan', 'Losartana Potássica'}
EXPECTED = {  # primeira palavra-chave -> princípios ativos (nomes de produção) do remédio perguntado
    'paracetamol': {'Acetaminophen'}, 'acetilciste': {'Acetylcysteine'}, 'acetilsalic': {'Acetylsalicylic acid'}, 'alendron': {'Alendronic acid'},
    'amiodaron': {'Amiodarone'}, 'amoxicil': {'Amoxicillin'}, 'atenolol': {'Atenolol'}, 'atorvastat': {'Atorvastatin'}, 'anlodipin': {'Besilato De Anlodipino'},
    'brimonid': {'Brimonidine'}, 'fenoterol': {'Bromidrato De Fenoterol'}, 'carbamazep': {'Carbamazepine'}, 'carisoprodol': {'Carisoprodol', 'Acetaminophen', 'Caffeine', 'Diclofenac'},
    'colecalcif': {'Cholecalciferol'}, 'ciprofibr': {'Ciprofibrato'}, 'claritrom': {'Clarithromycin'}, 'clonazep': {'Clonazepam'}, 'levodopa': {'Cloridrato De Benserazida', 'Evodopa'},
    'dapaglif': {'Dapagliflozin'}, 'denosum': {'Denosumab'}, 'dexamet': {'Dexamethasone'}, 'diclofen': {'Diclofenac'}, 'digoxin': {'Digoxin'}, 'dipiron': DIP,
    'empaglif': {'Empagliflozin'}, 'enalapril': {'Enalapril'}, 'dabigatr': {'Etexilato De Dabigatrana'}, 'fluoxet': {'Fluoxetine'}, 'hidroclorot': {'Hydrochlorothiazide'},
    'ibuprof': {'Ibuprofeno'}, 'indapam': {'Indapamide'}, 'glargin': {'Insulin glargine'}, 'isotretin': {'Isotretinoin'}, 'levotirox': {'Levothyroxine'}, 'losartan': LOS,
    'metformin': {'Metformin'}, 'omeprazol': {'Omeprazole'}, 'orfenadrin': {'Orphenadrine', 'Caffeine'} | DIP, 'pantopraz': {'Pantoprazole'}, 'fenitoin': {'Phenytoin'},
    'pseudoefedr': {'Pseudoephedrine'}, 'ramipril': {'Ramipril'}, 'riluzol': {'Riluzole'}, 'rosuvast': {'Rosuvastatin'}, 'escopolamin': {'Scopolamine'} | DIP,
    'sertralin': {'Sertraline'}, 'sinvastat': {'Simvastatin'}, 'sulfametox': {'Sulfamethoxazole', 'Trimethoprim'}, 'timolol': {'Timolol'},
}
LEAFLETS = {plain(l['title']): set(l['ingredients']) for l in json.load(open(sys.argv[3]))['leaflets']} if len(sys.argv) > 3 else {}


def source_kind(source, expected):
    ingredients = LEAFLETS.get(plain(source.split('|')[0].strip()))
    if ingredients is None:
        return 'desconhecida'
    if not ingredients:
        return 'sem principio ativo'
    if ingredients <= expected:
        return 'do remedio'
    return 'combinacao que contem' if ingredients & expected else 'de outro remedio'


# Bula brasileira: o remédio certo foi identificado? as fontes são dele? da seção do assunto?
core = [r for r in rows if r['block'] in ('bula brasileira', 'marca', 'conversa')]
medicine_ok = source_ok = section_ok = 0
medicine_n = source_n = section_n = 0
wrong_medicine, wrong_source, wrong_section = [], [], []
source_kinds = Counter()
for r in core:
    ev = r['response'].get('evaluation') or {}
    keys = r['keys']
    names = plain(' '.join(ev.get('medicines') or []))
    sources = ev.get('sources') or []
    if keys:
        medicine_n += 1
        if any(k in names for k in keys) or any(any(k in plain(s) for k in keys) for s in sources):
            medicine_ok += 1
        else:
            wrong_medicine.append({'id': r['id'], 'question': r['question'], 'medicines': ev.get('medicines'), 'status': r['response'].get('status')})
    if sources and keys and r['subject'] != 'interacao' and keys[0] in EXPECTED:
        kinds = [source_kind(s, EXPECTED[keys[0]]) for s in sources]
        source_kinds.update(kinds)
        source_n += 1
        if all(k == 'do remedio' for k in kinds):
            source_ok += 1
        else:
            wrong_source.append({'id': r['id'], 'question': r['question'], 'sources': [f'{k}: {s}' for k, s in zip(kinds, sources)]})
    if sources and r['subject'] in SECTION:
        section_n += 1
        sections = [unicodedata.normalize('NFKD', s.split('|')[1] if '|' in s else '').encode('ascii', 'ignore').decode().upper() for s in sources]
        if any(re.search(SECTION[r['subject']], s) for s in sections):
            section_ok += 1
        else:
            wrong_section.append({'id': r['id'], 'question': r['question'], 'sources': sources})
out['medicine_identified'] = wilson(medicine_ok, medicine_n)
out['answers_with_every_source_from_the_medicine'] = wilson(source_ok, source_n)
out['source_kinds'] = source_kinds
out['a_source_from_the_subject_section'] = wilson(section_ok, section_n)
out['examples'] = {'wrong_medicine': wrong_medicine, 'wrong_source': wrong_source, 'wrong_section': wrong_section[:15]}

# Cobertura e validação (perguntas sobre bula brasileira)
brazil = [r for r in rows if r['block'] == 'bula brasileira']
out['coverage_brazil'] = Counter((r['response'].get('evaluation') or {}).get('coverage') for r in brazil)
answered = [r for r in rows if r['response'].get('status') == 'answered']
accepted = sum((r['response'].get('generation') or {}).get('claims_accepted') or 0 for r in answered)
rejected = sum((r['response'].get('generation') or {}).get('claims_rejected') or 0 for r in answered)
reasons = Counter()
for r in answered:
    reasons.update((r['response'].get('generation') or {}).get('rejection_reasons') or {})
out['claims'] = {'accepted': accepted, 'rejected': rejected, 'acceptance': wilson(accepted, accepted + rejected), 'rejection_reasons': reasons}
out['answered_with_accepted_claim'] = wilson(sum(1 for r in answered if ((r['response'].get('generation') or {}).get('claims_accepted') or 0) > 0), len(answered))
out['raw_excerpts_shown'] = sum((r['response'].get('evaluation') or {}).get('raw_excerpts_shown') or 0 for r in rows)
out['leaflet_fallback_used'] = sum(1 for r in rows if (r['response'].get('retrieval') or {}).get('leaflet_fallback'))
out['retrieval_mode'] = Counter((r['response'].get('retrieval') or {}).get('mode') for r in rows)

# Notas automáticas do servidor (rubrica do evaluation:conversation)
dims = defaultdict(lambda: [0, 0])
for r in answered:
    for dim, ok in (((r['response'].get('evaluation') or {}).get('score') or {}).get('scores') or {}).items():
        dims[dim][1] += 1
        dims[dim][0] += 1 if ok else 0
out['rubric_answered'] = {d: wilson(k, n) for d, (k, n) in dims.items()}

# Sem bula brasileira, inexistentes, urgência, fora do escopo
special = {}
for block in ('sem bula BR', 'inexistente', 'urgencia', 'fora do escopo'):
    special[block] = [{'id': r['id'], 'question': r['question'], 'status': r['response'].get('status'), 'urgency': r['response'].get('urgency'),
                       'external': (r['response'].get('evaluation') or {}).get('external') or (r['response'].get('retrieval') or {}).get('external'),
                       'sources': (r['response'].get('evaluation') or {}).get('sources'), 'answer': (r['response'].get('answer') or r['response'].get('message') or '')[:400]} for r in rows if r['block'] == block]
out['special'] = special
out['follow_up'] = [{'id': r['id'], 'question': r['question'], 'status': r['response'].get('status'), 'medicines': (r['response'].get('evaluation') or {}).get('medicines'), 'conversation_used': ((r['response'].get('retrieval') or {}).get('classification') or {}).get('conversation_used')} for r in rows if r['subject'] == 'conversa-2']

# Tempos e custo
seconds = [(r['response'].get('evaluation') or {}).get('seconds') for r in rows]
seconds = [s for s in seconds if isinstance(s, (int, float))]
wall = [r['wall_seconds'] for r in rows]
out['latency_seconds'] = {'server_p50': pct(seconds, .5), 'server_p95': pct(seconds, .95), 'server_max': max(seconds) if seconds else None, 'wall_p50': pct(wall, .5), 'wall_p95': pct(wall, .95), 'mean': round(statistics.mean(seconds), 1) if seconds else None}
tokens = Counter()
models = Counter()
for r in rows:
    g = r['response'].get('generation') or {}
    if g.get('model'):
        models[g['model']] += 1
    tokens['generation_in'] += g.get('input_tokens') or 0
    tokens['generation_out'] += g.get('output_tokens') or 0
    ret = r['response'].get('retrieval') or {}
    it = ret.get('interpretation') or {}
    tokens['interpretation_in'] += it.get('input_tokens') or 0
    tokens['interpretation_out'] += it.get('output_tokens') or 0
    for m in ((ret.get('leaflet_fallback') or {}).get('per_medicine') or []):
        for doc in m.get('documents') or []:
            tokens['leaflet_in'] += doc.get('input_tokens') or 0
            tokens['leaflet_out'] += doc.get('output_tokens') or 0
out['tokens'] = tokens
out['generation_models'] = models

json.dump(out, open(sys.argv[2], 'w'), ensure_ascii=False, indent=1, default=dict)
summary = {k: v for k, v in out.items() if k not in ('examples', 'special', 'follow_up')}
print(json.dumps(summary, ensure_ascii=False, indent=1, default=dict))
