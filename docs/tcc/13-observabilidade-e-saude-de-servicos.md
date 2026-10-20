# Observabilidade operacional e saúde de serviços

Mapeamento no docx: Cap. 3, seções 3.3.3 (Tecnologias) e 3.4 (Etapas de desenvolvimento). Complementa `05-qualidade-operacao-e-evolucao.md` e `04-seguranca-e-privacidade.md`.

## Objetivo do tópico

Registrar como o operador sabe se os serviços auxiliares (IA local e OCR) estão disponíveis e como a telemetria evita expor conteúdo sensível.

## 1. Fundamentação

Observabilidade combina logs, métricas e verificações de saúde para inferir o estado interno de um sistema. [referência a incluir: literatura de observabilidade/SRE.] Em serviços de IA com carregamento lento de modelo, "processo vivo" não equivale a "pronto"; por isso há estados explícitos.

## 2. Implementação

### 2.1 Estados do classificador local

`MODEL_NOT_INSTALLED`, `MODEL_LOADING`, `READY`, `ERROR` e `SERVICE_AVAILABLE` (ver `09-llms-locais-quantizacao-llamafile.md`). `/status` responde sempre 200; `/health` responde 200 somente em `READY`, que exige sonda de inferência real.

### 2.2 Verificador de saúde na aplicação

`ServiceHealthChecker` consulta `LOCAL_AI_URL/status` e `DOCUMENT_OCR_URL/health` com cache de 5 s; falhas viram `UNREACHABLE`, nunca exceção. O componente Livewire `ServiceHealth` aparece no cabeçalho do Admin em todas as páginas, com ponto colorido e texto por serviço, atualizado por polling de 10 s. Os mesmos dados estão em `GET /admin/knowledge/services/health` (JSON, somente administrador).

### 2.3 Logs e correlação

Logs registram códigos, correlation id, contagens e durações, sem texto de PDF, prompt, URL ou segredo. Chamadas de IA registram provider, modelo, latência e tokens. Erros inesperados recebem identificador para correlação.

### 2.4 Contêineres

Healthchecks Docker, limites de CPU/memória, `restart: unless-stopped` e portas publicadas apenas em 127.0.0.1.

## Evidências no repositório

- `app/Services/Health/ServiceHealthChecker.php`; `app/Data/Health/`
- `app/Livewire/Admin/Knowledge/ServiceHealth.php`; `app/Http/Controllers/Admin/ServiceHealthController.php`
- `resources/views/components/knowledge-admin/shell.blade.php`; `routes/web.php`
- `compose.yaml`; `services/local-ai/app/supervisor.py`
- Testes: `tests/Feature/Admin/ServiceHealthTest.php`, `services/local-ai/tests/test_supervisor.py`

## Resultados observados

Testes automatizados do verificador, do componente e do endpoint. Verificação manual do healthcheck Docker do local-ai com modelo de teste (registrada em `technical-foundation.md`).

## Lacunas / a verificar

- Sem métricas temporais consolidadas, alertas nem SLOs formais.
- Sem monitoramento da fila (jobs acumulados ou falhos) na tela.
- Sem teste de carga nem medição de uso de memória/CPU dos serviços.
