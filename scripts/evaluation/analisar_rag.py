"""Origem dos erros da recuperação por assunto, a partir do registro completo de um evaluation:run retrieval
(GET /api/v1/audit/evaluations/{run_id}, com results.details). Classifica os 3 primeiros trechos das consultas que não
acertaram em @3 pelo título da seção. É uma classificação por rótulo de seção, não pelo conteúdo do trecho.
Uso: python3 analisar_rag.py REGISTRO.json database/evaluation/retrieval.subjects.v1.json SAIDA.json"""
import json
import re
import statistics
import sys
import unicodedata
from collections import Counter

run = json.load(open(sys.argv[1], encoding='utf8'))
details = run['results']['details']
subjects = json.load(open(sys.argv[2], encoding='utf8'))['subjects']


def plain(text):
    return unicodedata.normalize('NFKD', text or '').encode('ascii', 'ignore').decode().upper()


# Seções que também tratam do assunto, mas que o critério (título da seção) não conta como relevantes.
NEIGHBOR = {
    'indicacao': r'CARACTERISTICAS FARMACOLOGICAS|RESULTADOS DE EFICACIA|FARMACODINAM',
    'posologia': r'ESQUECER|DOSE',
    'efeitos': r'ADVERTENC|PRECAUC|O QUE DEVO SABER ANTES',
    'contraindicacao': r'ADVERTENC|PRECAUC|O QUE DEVO SABER ANTES',
    'armazenamento': r'VALIDADE|CUIDADOS DE CONSERV',
    'advertencias': r'CONTRAINDIC|QUANDO NAO DEVO',
    'superdose': r'ESQUECER|ADVERTENC',
}
ADMIN = r'COMPOSIC|IDENTIFICAC|APRESENTAC|DIZERES LEGAIS'
KNOWN = (r'INDICAC|PARA QUE|COMO ESTE MEDICAMENTO FUNCIONA|CARACTERISTICAS|RESULTADOS|CONTRAINDIC|QUANDO NAO|ADVERTENC|PRECAUC|'
         r'O QUE DEVO SABER|INTERAC|POSOLOGIA|COMO DEVO USAR|MODO DE US|ESQUECER|REACOES|QUAIS OS MALES|SUPERDOSE|QUANTIDADE MAIOR|'
         r'ARMAZENAMENTO|ONDE, COMO|CUIDADOS|INFORMACOES')


def category(top, subject):
    section = plain(top['section']).strip()
    if not section:
        return 'sem seção'
    if re.search(subjects[subject]['sections'], section):
        return 'seção do assunto não marcada como relevante'
    if re.search(ADMIN, section):
        return 'seção administrativa (composição, identificação, apresentação)'
    if not re.search(KNOWN, section):
        return 'rótulo que não é seção (nome de produto, arquivo)'
    if 'INTERAC' in section:
        return 'seção de interações'
    if re.search(NEIGHBOR[subject], section):
        return 'seção vizinha que também trata do assunto'
    return 'outra seção da bula'


out = {'run_id': run.get('run_id'), 'modes': {}}
for mode, items in details.items():
    misses = [d for d in items if not any(t['relevant'] for t in d['top'][:3])]
    slots = Counter(category(t, d['subject']) for d in misses for t in d['top'][:3] if not t['relevant'])
    by_subject = {}
    for subject in subjects:
        rows = [d for d in items if d['subject'] == subject]
        hits = sum(1 for d in rows if any(t['relevant'] for t in d['top'][:3]))
        by_subject[subject] = {'hit@3': f'{hits}/{len(rows)}', 'relevant_median': statistics.median(d['relevant'] for d in rows) if rows else None}
    out['modes'][mode] = {
        'queries': len(items), 'misses@3': len(misses),
        'relevant_only_at_4_or_5': sum(1 for d in misses if any(t['relevant'] for t in d['top'][3:5])),
        'miss_slots_by_origin': dict(slots.most_common()),
        'miss_slots_by_document_type': dict(Counter(t['document_type'] for d in misses for t in d['top'][:3]).most_common()),
        'by_subject': by_subject,
    }
json.dump(out, open(sys.argv[3], 'w', encoding='utf8'), ensure_ascii=False, indent=1)
print(json.dumps(out, ensure_ascii=False, indent=1))
