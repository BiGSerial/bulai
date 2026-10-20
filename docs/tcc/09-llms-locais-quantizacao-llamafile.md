# LLMs locais, quantização e llamafile

Mapeamento no docx: Cap. 2, seção 2.6.3 (Modelos de Linguagem de Grande Escala) e Cap. 3, seção 3.3.3 (Tecnologias Planejadas). Observação: o docx planeja "API de LLM (OpenAI GPT-4o ou equivalente)" para a IA principal; o modelo local descrito aqui é um componente distinto, de apoio à ingestão.

## Objetivo do tópico

Justificar e documentar o uso de um modelo de linguagem pequeno, executado localmente em CPU, como classificador auxiliar da ingestão.

## 1. Fundamentação teórica

### 1.1 Modelos pequenos e execução local

Modelos com poucos bilhões de parâmetros executam em hardware comum, mantendo os dados no ambiente do projeto, ao custo de menor capacidade de raciocínio e maior taxa de erro. [referência a incluir: relatório técnico do Qwen2.5; survey de LLMs (Zhao et al., 2023, já nas referências).]

### 1.2 Quantização

Quantização reduz a precisão numérica dos pesos (por exemplo, 4 bits) para diminuir memória e tempo de inferência, com possível perda de qualidade. A variante Q4_K_M do formato GGUF é um compromisso comum. [referência a incluir: artigo/documentação do llama.cpp e do formato GGUF.]

### 1.3 Saída estruturada por gramática

Gramáticas restringem a geração aos tokens que compõem um JSON válido conforme um schema, reduzindo respostas fora do contrato. [referência a incluir: documentação de grammars do llama.cpp.]

## 2. Escolhas implementadas

| Item | Escolha | Observação |
| --- | --- | --- |
| Runtime | llamafile 0.9.3 em container, CPU-only | sem GPU |
| Modelo padrão | Qwen2.5-1.5B-Instruct, Q4_K_M, cerca de 1,1 GB | escolhido por CPU/RAM e idioma, sem benchmark comparativo |
| Instalação | `scripts/install-local-model.sh` | https, SHA-256 verificado, arquivo parcial removido em falha, promoção atômica |
| Armazenamento | `services/local-ai/models/*` fora do Git, montado somente leitura | modelo não é baixado ao iniciar o container |
| Recursos | 4 CPUs, 4 GB, contexto 2048, porta em 127.0.0.1 | configurável por variáveis `LOCAL_AI_*` |

### 2.1 Supervisor e proxy

`services/local-ai/app/supervisor.py` (biblioteca padrão Python) inicia o llamafile, expõe `GET /status` e `GET /health` e repassa apenas `POST /v1/chat/completions` e `GET /v1/models`. Estados: `SERVICE_AVAILABLE`, `MODEL_NOT_INSTALLED`, `MODEL_LOADING`, `READY`, `ERROR`. `READY` exige saúde do upstream e uma sonda de inferência de 1 token. Modelo ausente não gera loop de falhas; reinícios usam backoff exponencial.

### 2.2 Achados de integração

1. O llamafile ignora `response_format: json_schema` da OpenAI. Foi necessário enviar `json_object` com o campo `schema` (gramática) e nomear as chaves exatas no prompt. Configuração: `ai.classifier.drivers.llamafile.structured_output = grammar`.
2. O limite de 300 caracteres na validação de evidência era incompatível com trechos de até 400 caracteres enviados ao modelo, causando `AI_INVALID_RESPONSE`.

## Evidências no repositório

- `services/local-ai/` (Dockerfile, `app/supervisor.py`, `entrypoint.sh`, `tests/test_supervisor.py`)
- `scripts/install-local-model.sh`, `scripts/setup-local-ai.sh`
- `compose.yaml` (serviço `local-ai`), `config/ai.php` (`classifier.drivers.llamafile`)
- `docs/technical-foundation.md` (seção "Local Classification Layer (llamafile) and Service Health")
- Testes: `tests/Unit/Services/Ai/LlamafileClassificationProviderTest.php`

## Resultados observados

- Verificação manual com um GGUF de teste de 1 MB: transições `MODEL_NOT_INSTALLED` para `READY`, chat completion real e healthcheck do Docker (registrado em `technical-foundation.md`).
- Execução com o Qwen2.5-1.5B em 6 PDFs reais: latência de cerca de 8 a 14 s por resposta em CPU; JSON válido nas 6 (observação de teste, n=6, hardware não registrado).

## Lacunas / a verificar

- `technical-foundation.md` ainda afirma que o download e a inferência do Qwen padrão não foram executados, o que diverge do relato de execução com 6 PDFs; atualizar a fonte e registrar hardware, threads e contexto usados.
- Sem comparação entre modelos (tamanhos, quantizações) nem medição de acurácia.
- Licença do modelo (Apache-2.0 declarada na documentação do repositório) deve ser confirmada na fonte do modelo.
