"""Bateria ampla do Perguntar em produção (/api/v1/evaluation/ask). Retoma de onde parou; grava cada resposta bruta com data e hora.
Uso: python3 bateria.py TOKEN SAIDA.json"""
import json, os, sys, time, urllib.request, urllib.error
from datetime import datetime, timezone, timedelta

BASE = 'https://bulai.vixforge.com.br/api/v1'
TOKEN, OUT = sys.argv[1], sys.argv[2]
BRT = timezone(timedelta(hours=-3))

# nome na pergunta, palavras que identificam o remédio certo (sem acento, minúsculas), parceiro para a pergunta de interação
MEDS = [
    ('paracetamol', ['paracetamol', 'tylenol', 'acetaminof'], 'ibuprofeno'),
    ('acetilcisteína', ['acetilciste', 'fluimucil'], 'amoxicilina'),
    ('AAS', ['acetilsalic', 'aspirina', 'aas'], 'ibuprofeno'),
    ('alendronato de sódio', ['alendron'], 'omeprazol'),
    ('amiodarona', ['amiodaron', 'ancoron'], 'digoxina'),
    ('amoxicilina', ['amoxicil', 'amoxil'], 'paracetamol'),
    ('atenolol', ['atenolol'], 'anlodipino'),
    ('atorvastatina', ['atorvastat', 'lipitor', 'citalor'], 'claritromicina'),
    ('anlodipino', ['anlodipin'], 'sinvastatina'),
    ('colírio de brimonidina', ['brimonid', 'alphagan'], 'timolol'),
    ('Berotec', ['fenoterol', 'berotec'], 'ipratrópio'),
    ('carbamazepina', ['carbamazep', 'tegretol'], 'fluoxetina'),
    ('Torsilax', ['carisoprodol', 'torsilax'], 'dipirona'),
    ('vitamina D colecalciferol', ['colecalcif', 'vitamina d', 'cholecalc'], 'alendronato'),
    ('ciprofibrato', ['ciprofibr', 'oroxadin', 'lipless'], 'sinvastatina'),
    ('claritromicina', ['claritrom', 'klaricid'], 'sinvastatina'),
    ('clonazepam', ['clonazep', 'rivotril'], 'sertralina'),
    ('Prolopa', ['levodopa', 'benserazid', 'prolopa'], 'sertralina'),
    ('dapagliflozina', ['dapaglif', 'forxiga'], 'metformina'),
    ('denosumabe', ['denosum', 'prolia', 'xgeva'], 'vitamina D'),
    ('dexametasona', ['dexamet', 'decadron'], 'ibuprofeno'),
    ('diclofenaco', ['diclofen', 'voltaren', 'cataflam'], 'losartana'),
    ('digoxina', ['digoxin'], 'amiodarona'),
    ('dipirona', ['dipiron', 'metamizol', 'novalgina'], 'paracetamol'),
    ('empagliflozina', ['empaglif', 'jardiance'], 'metformina'),
    ('enalapril', ['enalapril', 'renitec'], 'hidroclorotiazida'),
    ('dabigatrana', ['dabigatr', 'pradaxa'], 'AAS'),
    ('fluoxetina', ['fluoxet', 'daforin', 'prozac'], 'clonazepam'),
    ('hidroclorotiazida', ['hidroclorot', 'hydrochloro'], 'losartana'),
    ('ibuprofeno', ['ibuprof', 'advil', 'alivium'], 'losartana'),
    ('indapamida', ['indapam', 'natrilix'], 'enalapril'),
    ('insulina glargina', ['glargin', 'lantus', 'basaglar'], 'metformina'),
    ('isotretinoína', ['isotretin', 'roacutan'], 'vitamina D'),
    ('levotiroxina', ['levotirox', 'puran', 'synthroid', 'euthyrox'], 'omeprazol'),
    ('losartana', ['losartan', 'cozaar'], 'ibuprofeno'),
    ('metformina', ['metformin', 'glifage'], 'dapagliflozina'),
    ('omeprazol', ['omeprazol', 'losec'], 'clonazepam'),
    ('Dorflex', ['orfenadrin', 'dorflex'], 'clonazepam'),
    ('pantoprazol', ['pantopraz', 'pantozol'], 'levotiroxina'),
    ('fenitoína', ['fenitoin', 'hidantal'], 'carbamazepina'),
    ('pseudoefedrina', ['pseudoefedr', 'claritin', 'allegra'], 'atenolol'),
    ('ramipril', ['ramipril', 'triatec'], 'hidroclorotiazida'),
    ('riluzol', ['riluzol', 'rilutek'], 'fluoxetina'),
    ('rosuvastatina', ['rosuvast', 'crestor'], 'ciprofibrato'),
    ('Buscopan', ['escopolamin', 'scopolamin', 'buscopan'], 'dipirona'),
    ('sertralina', ['sertralin', 'zoloft'], 'clonazepam'),
    ('sinvastatina', ['sinvastat', 'zocor'], 'anlodipino'),
    ('Bactrim', ['sulfametox', 'trimetoprim', 'bactrim'], 'losartana'),
    ('colírio de timolol', ['timolol', 'timoptol'], 'brimonidina'),
]

SUBJECTS = [
    ('indicacao', lambda m, p: f'Para que serve o {m}?'),
    ('posologia', lambda m, p: f'Como devo tomar o {m}?'),
    ('efeitos', lambda m, p: f'Quais os efeitos colaterais do {m}?'),
    ('contraindicacao', lambda m, p: f'Quem não pode usar {m}?'),
    ('armazenamento', lambda m, p: f'Como devo guardar o {m}?'),
    ('gravidez', lambda m, p: f'Grávida pode usar {m}?'),
    ('interacao', lambda m, p: f'Posso usar {m} junto com {p}?'),
    ('superdose', lambda m, p: f'Tomei {m} a mais do que devia, o que pode acontecer?'),
]

SCENARIOS = []
for i, (name, keys, partner) in enumerate(MEDS):
    for k in range(4):
        subject, template = SUBJECTS[(i + 2 * k) % len(SUBJECTS)]
        SCENARIOS.append({'id': f'M{i + 1:02d}-{subject}', 'block': 'bula brasileira', 'subject': subject, 'medicine': name, 'keys': keys,
                          'question': template(name, partner)})

EXTRA = [
    # marcas e apresentações
    ('marca', 'Para que serve o Dorflex UNO?', ['orfenadrin', 'dorflex']),
    ('marca', 'Qual a dose do Dorflex Max?', ['orfenadrin', 'dorflex']),
    ('marca', 'Novalgina serve para febre?', ['dipiron', 'novalgina']),
    ('marca', 'Para que serve o Tylenol?', ['paracetamol', 'tylenol']),
    ('marca', 'Como tomar Losec MUPS?', ['omeprazol', 'losec']),
    ('marca', 'Quais os efeitos colaterais do Tegretol?', ['carbamazep', 'tegretol']),
    ('marca', 'Para que serve o Cozaar?', ['losartan', 'cozaar']),
    ('marca', 'Renitec pode dar tosse?', ['enalapril', 'renitec']),
    ('marca', 'Para que serve o Amoxil?', ['amoxicil', 'amoxil']),
    ('marca', 'Rivotril vicia?', ['clonazep', 'rivotril']),
    ('marca', 'Como tomar Puran T4?', ['levotirox', 'puran']),
    ('marca', 'Para que serve a Aspirina?', ['acetilsalic', 'aspirina']),
    ('marca', 'Daforin dá sono?', ['fluoxet', 'daforin']),
    ('marca', 'Como usar o Berotec gotas na nebulização?', ['fenoterol', 'berotec']),
    # sem bula brasileira: a bula americana (FDA) entra só para efeitos e interações
    ('sem bula BR', 'Quais os efeitos colaterais da levofloxacina?', ['levofloxac']),
    ('sem bula BR', 'Quais os efeitos colaterais da prednisona?', ['predniso']),
    ('sem bula BR', 'Quais remédios interagem com a enoxaparina?', ['enoxapar']),
    ('sem bula BR', 'Quais os efeitos colaterais do Dramin?', ['dimenidrin', 'dramin']),
    ('sem bula BR', 'Para que serve a prednisona?', ['predniso']),
    ('sem bula BR', 'Como tomar varfarina?', ['varfarin', 'warfarin', 'marevan']),
    # inexistentes ou fora do catálogo
    ('inexistente', 'Para que serve o Zentrovax?', []),
    ('inexistente', 'Posso tomar Fluxomarin 20mg?', []),
    ('inexistente', 'Quais os efeitos do Brontaxil?', []),
    # urgência
    ('urgencia', 'Meu filho de 3 anos tomou um frasco inteiro de dipirona, o que eu faço?', ['dipiron']),
    ('urgencia', 'Tomei 20 comprimidos de clonazepam agora', ['clonazep']),
    # fora do escopo (não é sobre remédio)
    ('fora do escopo', 'Qual a capital da França?', []),
]
for j, (block, question, keys) in enumerate(EXTRA):
    SCENARIOS.append({'id': f'X{j + 1:02d}', 'block': block, 'subject': block, 'medicine': None, 'keys': keys, 'question': question})

# conversas encadeadas: a segunda pergunta só faz sentido com a primeira
CHAINS = [
    ('Para que serve a losartana?', 'E qual a dose máxima por dia?', ['losartan']),
    ('Quais os efeitos colaterais da sertralina?', 'E ela pode ser tomada à noite?', ['sertralin']),
    ('Para que serve o omeprazol?', 'Posso tomar em jejum?', ['omeprazol']),
    ('Como tomar metformina?', 'E se eu esquecer uma dose?', ['metformin']),
    ('Para que serve o ibuprofeno?', 'Criança pode tomar?', ['ibuprof']),
    ('Para que serve a dipirona?', 'E ela serve para dor de cabeça?', ['dipiron']),
]
for j, (first, second, keys) in enumerate(CHAINS):
    SCENARIOS.append({'id': f'C{j + 1:02d}a', 'block': 'conversa', 'subject': 'conversa-1', 'medicine': None, 'keys': keys, 'question': first})
    SCENARIOS.append({'id': f'C{j + 1:02d}b', 'block': 'conversa', 'subject': 'conversa-2', 'medicine': None, 'keys': keys, 'question': second, 'chain': f'C{j + 1:02d}a'})


def post(payload):
    request = urllib.request.Request(BASE + '/evaluation/ask', data=json.dumps(payload).encode(), method='POST',
                                     headers={'Authorization': 'Bearer ' + TOKEN, 'Accept': 'application/json', 'Content-Type': 'application/json', 'User-Agent': 'BULAI-Avaliacao/1.0'})
    try:
        with urllib.request.urlopen(request, timeout=300) as response:
            return response.status, json.loads(response.read()), {}
    except urllib.error.HTTPError as error:
        raw = error.read() or b'{}'
        try:
            body = json.loads(raw)
        except ValueError:
            body = {'raw': raw[:500].decode('utf-8', 'replace')}
        return error.code, body, dict(error.headers)
    except Exception as error:  # noqa: BLE001 - timeout/rede: registra e segue
        return 0, {'error': repr(error)}, {}


state = json.load(open(OUT)) if os.path.exists(OUT) else {'run_started': datetime.now(BRT).isoformat(timespec='seconds'), 'base': BASE, 'results': []}
done = {r['id']: r for r in state['results'] if r['http'] == 200}
state['results'] = [r for r in state['results'] if r['http'] == 200]
last = 0.0
for scenario in SCENARIOS:
    if scenario['id'] in done:
        continue
    payload = {'question': scenario['question'], 'scenario': scenario['id'], 'expects': {}}
    if scenario.get('chain'):
        previous = done.get(scenario['chain'])
        if previous is None:
            continue
        payload['conversation'] = [previous['response']['next_conversation_turn']]
    for attempt in range(6):
        wait = 10.5 - (time.time() - last)
        if wait > 0:
            time.sleep(wait)
        started = datetime.now(BRT)
        last = time.time()
        status, body, headers = post(payload)
        if status == 429:
            retry = int(headers.get('Retry-After', headers.get('retry-after', 60)) or 60)
            print('429, aguardando', retry, flush=True)
            if retry > 3600:
                json.dump(state, open(OUT, 'w'), ensure_ascii=False, indent=1)
                sys.exit('limite diário atingido')
            time.sleep(retry + 2)
            continue
        if status in (0, 500, 502, 503, 504) and attempt < 2:
            time.sleep(20)
            continue
        break
    record = {k: v for k, v in scenario.items() if k != 'chain'} | {'chain': scenario.get('chain'), 'asked_at': started.isoformat(timespec='seconds'), 'http': status, 'wall_seconds': round(time.time() - last, 1), 'response': body}
    state['results'].append(record)
    if status == 200:
        done[scenario['id']] = record
    ev = body.get('evaluation', {}) if isinstance(body, dict) else {}
    print(len(state['results']), '/', len(SCENARIOS), scenario['id'], status, record['wall_seconds'], body.get('status') if isinstance(body, dict) else '', ev.get('coverage'), (ev.get('medicines') or [])[:2], flush=True)
    json.dump(state, open(OUT, 'w'), ensure_ascii=False, indent=1)
state['run_finished'] = datetime.now(BRT).isoformat(timespec='seconds')
json.dump(state, open(OUT, 'w'), ensure_ascii=False, indent=1)
print('fim', len(state['results']))
