"""Rodada independente do Perguntar em produção (database/evaluation/freeform.independent.v1.json).
Faz cada pergunta em POST /api/v1/evaluation/ask (com o turno anterior, quando o item tem conversation_setup), baixa o
registro completo em GET /api/v1/questions/{uuid} e grava tudo, com data e hora. Retoma de onde parou.
Uso: python3 independente.py TOKEN SAIDA.json"""
import json
import os
import sys
import time
import urllib.error
import urllib.request
from datetime import datetime, timedelta, timezone

BASE = 'https://bulai.vixforge.com.br/api/v1'
DATASET = os.path.join(os.path.dirname(__file__), '..', '..', 'database', 'evaluation', 'freeform.independent.v1.json')
TOKEN, OUT = sys.argv[1], sys.argv[2]
BRT = timezone(timedelta(hours=-3))
PAUSE = 10.5


def call(method, path, body=None):
    request = urllib.request.Request(BASE + path, data=json.dumps(body).encode() if body is not None else None, method=method, headers={
        'Authorization': 'Bearer ' + TOKEN, 'Accept': 'application/json', 'Content-Type': 'application/json', 'User-Agent': 'BULAI-Avaliacao/1.0'})
    try:
        with urllib.request.urlopen(request, timeout=180) as response:
            return response.status, json.load(response)
    except urllib.error.HTTPError as error:
        return error.code, {'error': error.read().decode()[:500]}


def ask(question, conversation):
    status, data = call('POST', '/evaluation/ask', {'question': question, 'scenario': 'independente', 'conversation': conversation, 'expects': {'facts': True}})
    time.sleep(PAUSE)
    return status, data


dataset = json.load(open(DATASET, encoding='utf8'))
done = json.load(open(OUT, encoding='utf8')) if os.path.exists(OUT) else {'dataset': dataset['version'], 'results': []}
seen = {r['id'] for r in done['results'] if r.get('http') == 200}

for item in dataset['items']:
    if item['id'] in seen:
        continue
    conversation = []
    for previous in item.get('conversation_setup', []):
        status, data = ask(previous, conversation)
        if status == 200:
            conversation.append(data['next_conversation_turn'])
    asked_at = datetime.now(BRT).isoformat(timespec='seconds')
    status, data = ask(item['question'], conversation)
    record = {}
    if status == 200 and data.get('uuid'):
        _, record = call('GET', '/questions/' + data['uuid'])
    done['results'] = [r for r in done['results'] if r['id'] != item['id']] + [{'id': item['id'], 'asked_at': asked_at, 'http': status, 'response': data, 'record': record}]
    json.dump(done, open(OUT, 'w', encoding='utf8'), ensure_ascii=False, indent=1)
    print(item['id'], status, data.get('status'), flush=True)
