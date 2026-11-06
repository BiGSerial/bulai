# 26. Experiência nativa do PWA, conversa com contexto, tolerância a erros de digitação e landing page

Nota redigida em 06/11/2026 a partir do código, dos testes e das execuções locais. Complementa os capítulos 21 (PWA), 24 (contexto de tratamento) e 25 (programação do tratamento). Seções do docx relacionadas: 3.3.2 (etapas 4 e 5), 3.4 (Fase 4), 3.5.3.

## 1. Problema

Após a quinta onda o aplicativo cobria as tarefas do paciente, mas a experiência de uso ficou aquém do que o público-alvo espera de um aplicativo de celular:

1. telas funcionais porém **pobres**, com pouca hierarquia visual e **quebras de layout** em telas estreitas (rolagem horizontal, elementos cortados);
2. o **ditado por voz** falhava de forma intermitente (reconhecimento encerrado pelo navegador após pausas, sem mensagem de erro, texto substituído em vez de acrescentado);
3. a **opção de instalar** o PWA não aparecia (o evento de instalação não era capturado e não havia orientação para iOS);
4. a pergunta livre **não guardava contexto**: "e com café?" depois de "posso tomar losartana à noite?" não sabia de que remédio se tratava;
5. um **erro de digitação** no nome do remédio ("lozartana", "omeprasol") derrubava a detecção e a resposta;
6. a página pública era uma lista de limites, não uma apresentação do produto.

## 2. Decisões de projeto

| Decisão | Justificativa |
| --- | --- |
| **Tolerância a erros por trigramas no banco** (`pg_trgm`), não por IA. `FuzzyMedicineMatcher` compara o termo com a primeira palavra do nome comercial e do princípio ativo, com os nomes inteiros e com os aliases, por `similarity()` simétrica; índices GIN de trigramas em `anvisa_medicines`, `active_ingredients` e `active_ingredient_aliases`. | Determinístico, auditável, sem custo por chamada e rápido (uma pergunta com três erros de digitação resolve em ~60 ms sobre 39 mil registros). `word_similarity` foi descartada porque infla nomes com palavras repetidas no catálogo. |
| **Regras de ambiguidade** antes de aceitar uma correção: só palavras com 5+ letras fora da lista de palavras comuns; grupos que compartilham o princípio ativo não são ambíguos ("dipironna" → Dipirona); vários grupos sem ingrediente em comum só valem se um se destacar ou se todos apontarem para a mesma palavra que é nome de produto ("omeprasol" → Omeprazol, ainda que existam combinações); "acidu" (ácido fólico, valproico, ascórbico…) é ambíguo e não é corrigido. | Evita trocar uma palavra comum por um remédio ("muscular" × Musculare) sem bloquear os casos frequentes. A lista de palavras comuns foi ampliada com corpo, sintomas, hábitos e formas farmacêuticas. |
| **Correção visível e reversível**: a resposta mostra "Entendemos 'lozartana' como Losartana" com troca ou remoção. | Transparência exigida pelas diretrizes do produto: o sistema nunca decide em silêncio por um remédio diferente do escrito. |
| **Conversa com contexto no bloco de fatos**, nunca como instrução: as últimas trocas (pergunta e resposta validada) entram no prompt rotuladas como "Conversa anterior", com tamanho limitado; a pergunta seguinte sem nome de remédio herda os remédios da anterior. | Mantém o modelo preso às evidências da bula e à validação existente (`ExplanationValidator`); o contexto é memória de curto prazo da sessão, não histórico persistente adicional. |
| **Ditado contínuo com retomada**: Web Speech API com `continuous` e `interimResults`, texto interino visível, reinício automático quando o navegador encerra sozinho, mensagens por tipo de erro, parada ao sair da tela; sem a API, dica para usar o microfone do teclado. | Elimina a intermitência percebida; sem dependência nova. Limitação: a API só existe em navegadores baseados em Chromium e no Safari, e exige internet. |
| **Instalação explícita**: captura de `beforeinstallprompt`, cartão "Tenha o BULA-IA na tela do seu celular" com botão "Instalar" (Android/Chrome/Edge) ou passo a passo do iOS (Compartilhar → Adicionar à Tela de Início), item no menu da conta, manifesto e service worker revisados para os critérios de instalabilidade. | O prompt do Chrome só aparece após engajamento e em HTTPS; o iOS nunca o dispara. Mostrar a orientação é o único caminho confiável. |
| **Sensação nativa só com CSS e Alpine**: safe areas em modo standalone, transições de página curtas (`@view-transition`), sem realce de toque, `overscroll-behavior`, alvos ≥ 48 px, estados de pressionar; guardas globais de layout (`min-width: 0`, `overflow-wrap: anywhere`, mídia com `max-width: 100%`). | Sem bibliotecas novas (restrição do projeto); o que mais pesa na percepção de "nativo" é ausência de quebra e resposta imediata ao toque. |
| **Auditoria estática de layout em teste** (`ConsultaDesignSystemTest`): proíbe nas telas do paciente `whitespace-nowrap` sem corte, grades de 3+ colunas sem prefixo responsivo, larguras fixas maiores que a tela, tabelas fora de `overflow-x-auto`, `style=` inline e jargão técnico. | Mesmo mecanismo do admin (`AdminDesignSystemTest`): a regra vive no teste e vale para as próximas iterações, sem depender de inspeção manual. |
| **Landing page** como apresentação de produto, com os limites do produto em seção própria e visível. | Credibilidade comercial sem abrir mão da transparência das diretrizes (não substitui o médico; "nenhuma interação encontrada" não é garantia). |

## 3. Modelo de dados e serviços

- Migração `2026_11_06_011348_add_trigram_search_to_catalog`: `CREATE EXTENSION pg_trgm` e cinco índices GIN de trigramas (nome comercial, primeira palavra do nome comercial, princípio ativo, primeira palavra do princípio ativo, nomes e aliases de princípios ativos). Nenhuma coluna nova.
- `App\Services\Interactions\FuzzyMedicineMatcher`: `match(termo, limite, semelhança mínima)` (um produto representativo por conjunto de princípios ativos, mais parecidos primeiro; empate decidido por genérico → identificado → menos ingredientes) e `best(termo)` com as regras de ambiguidade.
- `MedicationSearchService::search()`: busca por prefixo e, sem resultado, fallback tolerante; cada item traz `fuzzy` e `matched` para a tela perguntar "Você quis dizer…?".
- `MedicationMentionDetector::detect()`: após o caminho exato, tenta a correção nas palavras não cobertas; cada menção traz `fuzzy` e `corrected`.
- `MedicationQuestionService::askFreeform()`: aceita a conversa recente (seção 4.1).

## 4. Fluxos

### 4.1 Pergunta com contexto e com erro de digitação

1. O paciente escreve ou dita "posso tomar lozartana com omeprasol?". A triagem de segurança roda como antes.
2. O detector não encontra "lozartana" nem "omeprasol" pelo caminho exato; a tolerância a erros devolve Losartana e Omeprazol, marcados como corrigidos.
3. A resposta vem das bulas dos dois e a tela mostra "Entendemos 'lozartana' como Losartana. Não é isso?" com troca e remoção.
4. "E com café?" chega sem nome de remédio: a tela envia as últimas trocas; o serviço herda os dois remédios e acrescenta "Conversa anterior" ao bloco de fatos; o modelo responde sobre café com as mesmas bulas.

### 4.2 Instalação

1. Chrome/Edge no Android disparam `beforeinstallprompt` após o engajamento; o aplicativo guarda o evento e mostra o cartão na Início e o item no menu.
2. "Instalar" chama o prompt nativo; `appinstalled` esconde o cartão. "Agora não" silencia por 14 dias.
3. No iOS o cartão mostra o passo a passo; em modo standalone nada é exibido.

## 5. Evidências

- `tests/Feature/Consulta/BuscaToleranteAErrosTest.php`: fallback da busca, correção no detector, ambiguidade, palavras comuns, limitação com letras trocadas de lugar.
- `tests/Feature/Consulta/PerguntaLivreTest.php` (ampliado): pergunta seguinte herda os remédios e o prompt recebe "Conversa anterior"; correção visível.
- `tests/Feature/PwaAssetTest.php` (ampliado) e `tests/Feature/Consulta/ConsultaDesignSystemTest.php` (novo): instalabilidade e regras de layout.
- `tests/Feature/LandingPageTest.php` (novo).
- Documentação técnica: `docs/consulta-pwa.md` (seções da iteração 5), `docs/design/principios-de-ux.md` (regras de layout mobile), `docs/design/landing-page.md`, `docs/tratamento.md`.

## 6. Limitações e ameaças à validade

- Trigramas não resolvem letras trocadas de lugar em palavras curtas ("dipirnoa" fica abaixo da semelhança mínima de 0,5); a tela continua oferecendo a busca manual.
- Palavras comuns que coincidem com marcas registradas podem ser corrigidas indevidamente; a lista de palavras comuns reduz, mas não elimina, o risco. A correção é sempre visível e reversível.
- O contexto da conversa vive na sessão da tela (não é persistido além do histórico já existente de perguntas) e cobre as últimas três trocas.
- Ditado e instalação dependem do navegador: sem Web Speech API (Firefox, alguns WebViews) o botão de voz não aparece; o iOS não oferece prompt de instalação.
- Nenhum teste com pacientes idosos ou em dispositivos reais foi executado; a avaliação de usabilidade continua como protocolo (capítulo 22). A verificação de layout é estática (classes), não visual.

## 7. Números da iteração

| Medida | Valor |
| --- | --- |
| Tempo da detecção com três erros de digitação (catálogo real, 39.416 registros, local) | ~60 ms |
| Semelhança mínima para corrigir uma palavra numa pergunta / na busca | 0,5 / 0,45 |
| Palavras comuns que nunca viram remédio (`MedicationMentionDetector::STOPWORDS`) | 275 |
| Testes novos: `BuscaToleranteAErrosTest` 2, `ConsultaDesignSystemTest` 3, `LandingPageTest` 3 | 8 |
| Testes ampliados: `PerguntaLivreTest` 9 (+3), `PwaAssetTest` 6 (reescritos), `RemediosDeHojeTest` 3, `ConsultaPwaTest` 11, `PerfilEPerguntasTest` | 29 cobrindo as telas |
| Suíte completa em 06/11/2026 (sequencial, após a sexta onda) | 562 testes, 561 aprovados, 1 ignorado (chamada real à Anthropic, executada à parte), 11.064 asserções, ~61 s |
| Toques para marcar a próxima dose a partir da Início (cartão do dia) | 1 |
| Trocas de conversa lembradas na pergunta livre | 3 (até 600 caracteres de contexto) |
