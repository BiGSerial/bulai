"""Quadro A.1 do TCC a partir da produção: GET /stats/knowledge (bula -> princípios ativos) e GET /audit/documents (trechos, hash).
Uso: python3 apendice_a.py STATS.json DOCUMENTS.json > quadro-a1.md"""
import json, sys

PT = json.load(open('database/evaluation/retrieval.subjects.v1.json'))
NAMES = {i['ingredient']: i['name'] for i in PT['items']}
NAMES.update({'Evodopa': 'levodopa', 'Cloridrato De Benserazida': 'benserazida', 'Alendronic acid': 'alendronato de sódio', 'Cholecalciferol': 'colecalciferol'})
TYPE = {'bula_profissional': 'Profissional', 'bula_paciente': 'Paciente', 'bula': 'Não classificada'}

stats = json.load(open(sys.argv[1]))
documents = {d['id']: d for d in json.load(open(sys.argv[2]))['documents']}
leaflets = stats['leaflets']

rows, chunks, by_type, linked = [], 0, {}, 0
for n, leaflet in enumerate(sorted(leaflets, key=lambda l: l['id']), start=1):
    doc = documents.get(leaflet['id'], {})
    ingredients = sorted({NAMES.get(i, i.lower()) for i in leaflet['ingredients']})
    title = leaflet['title'].split(' — ')[0].replace('|', '/').strip()
    if len(title) > 60:
        title = title[:57] + '…'
    kind = TYPE.get(leaflet['type'], leaflet['type'])
    by_type[kind] = by_type.get(kind, 0) + 1
    chunks += doc.get('chunks') or 0
    linked += 1 if ingredients else 0
    rows.append(f"| {n} | {leaflet['id']} | {title} | {' + '.join(ingredients) if ingredients else '—'} | {kind} | {doc.get('chunks', '—')} | {(doc.get('sha256') or '')[:12]} |")

print('| Nº | Doc. | Título no sistema | Princípio(s) ativo(s) vinculado(s) | Tipo de bula | Trechos | SHA-256 do PDF (início) |')
print('| --- | --- | --- | --- | --- | --- | --- |')
print('\n'.join(rows))
print(f"| | | **Total: {len(rows)} bulas** | {linked} vinculadas | {', '.join(f'{v} {k.lower()}' for k, v in sorted(by_type.items()))} | **{chunks}** | |")
