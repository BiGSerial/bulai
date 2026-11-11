# Atividades implementadas

## Objetivo

Este documento apresenta o que já está pronto no BULA-IA e como cada fluxo funciona. Ele descreve o estado observado no código e nos testes em 1º de outubro de 2026.

## Resumo funcional

| Atividade | Estado | Como acessar ou executar |
| --- | --- | --- |
| Autenticação administrativa | Pronta | `/login` |
| Controle de acesso por perfil | Pronto | Middleware `auth` e `admin` |
| Gestão de usuários com auditoria | Pronta | `/admin/users` |
| Minha conta e troca de senha | Pronta | `/account` |
| Primeiro acesso (troca da senha provisória) | Pronto | `/first-access` |
| Painel da base de conhecimento | Pronto | `/admin/knowledge` |
| Cadastro e gestão de origens | Prontos | `/admin/knowledge/sources` |
| Cadastro manual de documento lógico | Pronto | `/admin/knowledge/documents/create` |
| Catálogo e inspeção de documentos | Prontos | `/admin/knowledge/documents` |
| Upload administrativo de PDFs em lote | Pronto | `/admin/knowledge/upload` |
| Acompanhamento de lotes | Pronto | `/admin/knowledge/batches` |
| Revisão e associação de pendências | Prontas | `/admin/knowledge/pending` |
| Importação estruturada por API | Pronta | `POST /api/internal/knowledge/import` |
| Anexação de PDF por API | Pronta | `POST /api/internal/knowledge/{document}/file` |
| Ingestão de lote por API | Pronta | `POST /api/internal/knowledge/batches` |
| Associação manual por API | Pronta | `POST /api/internal/knowledge/batches/{batch}/items/{item}/associate` |
| Extração e fragmentação de PDFs | Prontas | Job, Admin ou comando Artisan |
| Armazenamento privado e portável | Pronto | Disco definido por `KNOWLEDGE_FILESYSTEM_DISK` |
| PWA básica e identidade visual | Prontas | Manifesto, service worker e SVGs em `public/brand` |

## 1. Autenticação e autorização administrativa

O sistema usa autenticação de sessão do Laravel. O usuário entra por `/login` e encerra a sessão por `POST /logout`. Todas as páginas administrativas exigem autenticação, acesso ativo e o perfil `ADMIN`; usuários com perfil `USER` recebem `403`.

O login aceita o e-mail sem diferenciar maiúsculas, bloqueia a 6ª tentativa no mesmo minuto para o mesmo e-mail e IP (mensagem no próprio formulário), recusa acesso desativado (a mensagem só aparece quando a senha está correta) e grava o último acesso. Depois do login, quem usa senha provisória vai para "Primeiro acesso"; depois disso, o `ADMIN` segue para o admin e o perfil `USER` vai para "Minha conta" (ver seção 11).

O primeiro administrador pode ser criado pelo seeder:

```bash
BULAI_ADMIN_NAME="BULA-IA Admin" \
BULAI_ADMIN_EMAIL="admin@example.com" \
BULAI_ADMIN_PASSWORD="change-me-locally" \
php artisan db:seed
```

O seeder não cria uma conta quando e-mail ou senha não estão definidos explicitamente. O e-mail é gravado em minúsculas e o administrador do seed fica sempre ativo e sem troca de senha obrigatória.

## 2. Painel da base de conhecimento

`/admin/knowledge` apresenta contagens atualizadas de documentos, arquivos, lotes, itens pendentes e erros. A página funciona como entrada para os fluxos de origens, documentos, upload, lotes e pendências.

## 3. Origens dos documentos

Em `/admin/knowledge/sources`, o administrador pode pesquisar, paginar e filtrar origens ativas ou inativas. Também pode:

- criar uma origem;
- editar seus dados de procedência;
- consultar seus documentos recentes;
- ativar ou desativar a origem sem apagar o histórico.

As origens distinguem nome, coleção, tipo, organização, URL, país e descrição. A combinação normalizada de nome e coleção evita duplicidades que diferem apenas por maiúsculas e minúsculas. Somente origens ativas podem receber novos uploads.

A tela de upload oferece criação rápida de origem e mantém os PDFs já selecionados no formulário.

## 4. Documentos lógicos

O documento lógico representa a identidade e os metadados de um conteúdo, independentemente do PDF físico.

Em `/admin/knowledge/documents/create`, o administrador pode cadastrar um documento sem PDF informando origem, título, tipo e identificadores. Em `/admin/knowledge/documents`, pode pesquisar por título ou chave externa, filtrar por estado do arquivo e navegar com paginação.

A página de detalhes mostra procedência, lote, metadados, estados independentes de arquivo, conteúdo e embedding, SHA-256, informações de processamento e chunks ordenados. O conteúdo exibido é escapado, e caminhos físicos privados não são expostos.

Os aliases `/admin/documents` e `/admin/documents/{document}` continuam disponíveis para compatibilidade.

## 5. Upload e ingestão em lote

O upload administrativo em `/admin/knowledge/upload` aceita múltiplos PDFs e exige a seleção de uma origem ativa. Não há limite de quantidade na aplicação; o lote pode somar no máximo 100 MB, configurável por `KNOWLEDGE_INGESTION_MAX_BATCH_KILOBYTES`. O teto temporário por arquivo usa `KNOWLEDGE_INGESTION_MAX_FILE_KILOBYTES`, também em 100 MB por padrão.

Antes de iniciar a importação, cada PDF da lista tem a ação "Remover", e, a partir de dois arquivos, aparece "Limpar seleção", com confirmação numa janela do BULAI. O contador e o total em MB se atualizam, os erros de validação da lista são limpos e o arquivo temporário do Livewire é apagado. Uma nova seleção se soma à atual, não a substitui. A remoção identifica o arquivo pelo nome temporário, e não pela posição, para que um clique repetido não remova outro arquivo. Componente: `app/Livewire/Admin/Knowledge/Upload.php` (`removeFile`, `clearFiles`); tela: `resources/views/livewire/admin/knowledge/upload.blade.php`; testes: `tests/Feature/Admin/KnowledgeUploadAdminTest.php`.

Cada arquivo é tratado isoladamente. Uma falha não invalida os demais itens do lote. A resolução procura, nesta ordem:

1. `external_key` explícita;
2. SHA-256 já conhecido;
3. identificador da origem, limitado à origem do lote;
4. nome de arquivo normalizado;
5. criação automática, somente quando existem identidade e metadados estruturados suficientes.

O resultado de cada item é:

- `ASSOCIATED`: PDF associado ao documento;
- `DUPLICATE`: os mesmos bytes já existem e a nova cópia é descartada;
- `PENDING`: associação não segura; o PDF fica em quarentena privada;
- `ERROR`: falha controlada do item, sem manter o arquivo.

O sistema nunca sobrescreve silenciosamente um documento que já possui outro PDF. Os lotes e seus contadores podem ser consultados em `/admin/knowledge/batches` e `/admin/knowledge/batches/{batch}`.

## 6. Revisão de pendências

`/admin/knowledge/pending` lista apenas itens que ainda exigem ação. Na revisão, o administrador pode:

- pesquisar e selecionar um documento existente da mesma origem; ou
- criar um novo documento lógico e associá-lo ao PDF pendente.

A confirmação revalida o item, o arquivo e o documento dentro de transação e com bloqueio das linhas. O serviço detecta repetição, arquivo ausente ou alterado, documento ocupado e conflitos de SHA-256. Em caso de sucesso, promove o PDF da quarentena para o armazenamento definitivo, recalcula os contadores do lote e agenda o processamento do documento.

## 7. API interna de ingestão

As rotas sob `/api/internal` exigem Bearer Token com escopo `knowledge:ingest` e possuem limitação de requisições. Um token temporário é emitido por:

```bash
php artisan knowledge:issue-token "curadoria-dia"
```

O token em texto puro é mostrado uma única vez; somente seu hash SHA-256 é persistido. Tokens expirados, revogados ou com escopo incorreto são rejeitados.

Fluxos disponíveis:

- `POST /api/internal/knowledge/import`: cria ou atualiza um documento por `external_key` e substitui seus chunks por conteúdo estruturado;
- `POST /api/internal/knowledge/{document}/file`: anexa um PDF ao documento indicado, sem inferir identidade pelo nome;
- `POST /api/internal/knowledge/batches`: recebe lote multipart e aplica a mesma lógica usada pelo upload administrativo;
- `POST /api/internal/knowledge/batches/{batch}/items/{item}/associate`: resolve um item pendente sem exigir novo upload.

Erros inesperados retornam um código controlado e um `error_id`. Mensagens SQL, stack traces, segredos e caminhos privados ficam apenas nos logs.

## 8. Processamento de PDFs

O pipeline implementado é:

```text
PDF privado -> extração por página -> normalização conservadora
-> detecção de seções -> chunks rastreáveis -> knowledge_chunks
```

O processador extrai texto localmente, preserva ordem e página, reconhece títulos de seções e divide o conteúdo sem cruzar seções. Cada chunk registra índice, seção e intervalo de páginas.

O processamento pode ser iniciado pela página do documento ou pelos comandos:

```bash
php artisan knowledge:process-document 123
php artisan knowledge:process-document 123 --force
php artisan knowledge:process-document 123 --force --sync
```

Sem `--sync`, o comando agenda o Job na fila. O worker de produção deve estar ativo:

```bash
php artisan queue:work database --queue=default --timeout=60
```

Documentos já prontos são ignorados, salvo com `--force`. O reprocessamento substitui os chunks atomicamente; uma falha mantém os chunks válidos anteriores e grava somente código e referência controlados do erro. PDFs compostos apenas por imagem usam o OCR como fallback; sem o serviço, falham com `TEXT_EXTRACTION_FAILED` (OCR desabilitado) ou `OCR_UNAVAILABLE` (serviço indisponível).

## 9. Armazenamento e integridade

PDFs ficam fora de `public/`, no disco Laravel definido por `KNOWLEDGE_FILESYSTEM_DISK` (padrão `knowledge`). As chaves são geradas pela aplicação:

```text
knowledge/originals/{document_id}/{operation_uuid}.pdf
knowledge/pending/{batch_id}/{uuid}.pdf
knowledge/staging/{operation_uuid}/original.pdf
```

SHA-256 protege a identidade dos bytes e detecta duplicidades ou alterações. A promoção entre quarentena, staging e destino usa streams e verificação de checksum, permitindo trocar o driver do filesystem futuramente. Falhas comuns têm compensação de arquivos; uma queda abrupta exatamente entre filesystem e commit de banco ainda exige futura rotina de reconciliação.

## 10. PWA e interface

A aplicação possui manifesto instalável, service worker e identidade visual em `public/brand`. O service worker pode armazenar assets estáticos, mas não guarda navegações autenticadas de `/admin` ou `/login`. Não há uso offline dos documentos administrativos.

## 11. Gestão de usuários, primeiro acesso e Minha conta

Em `/admin/users` (item "Usuários" do menu), o administrador lista os acessos em cartões (nome, e-mail, perfil, situação, último acesso), busca por nome ou e-mail e filtra por situação e perfil. Também pode:

- cadastrar um usuário informando nome, e-mail e perfil (`ADMIN` = "Administrador", `USER` = "Usuário"); o sistema gera uma senha provisória de 16 caracteres (letras e números), mostrada **uma única vez** numa janela com botão "Copiar", e exige a troca no primeiro acesso;
- editar nome, e-mail e perfil;
- desativar ou reativar o acesso (com confirmação);
- gerar uma nova senha provisória (com confirmação, na página do usuário);
- consultar o histórico de auditoria na página do usuário.

Travas no serviço (`app/Services/Users/UserAccountService.php`): ninguém desativa nem rebaixa o próprio acesso, o sistema nunca fica sem administrador ativo (transação com bloqueio das linhas de administradores ativos) e e-mail repetido, mesmo com outras maiúsculas, é recusado. Desativar, trocar o perfil ou gerar nova senha provisória derruba as sessões da pessoa (linhas da tabela `sessions`) e invalida o "Manter conectado".

A senha provisória nunca vai para a sessão, para o log nem para a auditoria. A tabela `user_audit_events` registra, só por inclusão, `created`, `updated`, `role_changed`, `deactivated`, `reactivated`, `temporary_password_issued` e `password_changed`, com autor e IP.

Em `/first-access` ("Primeiro acesso"), quem ainda usa senha provisória define a própria senha antes de usar o sistema: só "Nova senha" e confirmação (pelo menos 12 caracteres, letras e números, diferente da provisória), sem pedir a provisória de novo. Enquanto a troca estiver pendente, o login, `/`, o admin e a Minha conta levam para essa tela. Na primeira vez, a tela dá as boas-vindas com o nome da pessoa, apresenta o BULAI e explica o sistema: para o `ADMIN`, as áreas do menu, com as mesmas explicações das dicas do menu; para o `USER`, o que ele faz em Minha conta e o aviso de que a consulta de bulas ainda vai chegar. Para quem já usava o sistema e recebeu nova senha provisória, a tela só pede a nova senha ("Defina sua nova senha"). Ao salvar, a troca encerra as outras sessões, fica registrada no histórico ("Ao substituir a senha provisória.") e leva o `ADMIN` ao admin e o `USER` à Minha conta.

Em `/account` ("Minha conta", também no cabeçalho do admin), qualquer pessoa autenticada vê nome, e-mail e perfil, troca a senha quando quiser (senha atual, nova com pelo menos 12 caracteres, letras e números, diferente da atual) e sai. A troca encerra as outras sessões abertas e mantém a atual.

Antes de usar em um banco existente, rode `php artisan migrate`: a migration normaliza os e-mails para minúsculas e para, sem alterar nada, se houver dois cadastros com o mesmo e-mail ignorando maiúsculas.

Testes: `tests/Feature/Admin/UserManagementAdminTest.php`, `tests/Feature/Auth/AuthenticationTest.php`, `tests/Feature/Auth/AccountTest.php`, `tests/Feature/Auth/FirstAccessTest.php`, `tests/Feature/Auth/AccountStateMiddlewareTest.php`, `tests/Feature/Auth/LivewirePersistentMiddlewareTest.php` e `tests/Feature/Infrastructure/UserAccountStateMigrationTest.php`.

## Verificação automatizada

A suíte cobre os principais fluxos e falhas de autenticação, autorização, gestão de usuários, importação, anexação, lotes, duplicidades, quarentena, associação manual, origens, Admin, processamento, portabilidade de armazenamento e PWA.

Para executar toda a suíte:

```bash
php artisan test
```

Para mudanças futuras, deve-se executar primeiro o arquivo de teste mais diretamente relacionado e, quando apropriado, a suíte completa.

## Limitações conhecidas

- embeddings, busca vetorial, RAG e respostas por LLM ainda não foram implementados;
- OCR é apenas fallback do job de processamento (não atua na identificação durante o upload) e sua acurácia não foi medida;
- a gestão de usuários não envia convite por e-mail (a senha provisória é entregue pelo administrador), não exclui usuários e não tem perfil de curadoria;
- documentos podem ser criados e inspecionados no Admin, mas ainda não há edição geral de seus metadados;
- emissão e revogação administrativa de tokens não possuem interface web;
- ingestão em lote ocorre sincronamente durante a requisição;
- não existe importação por ZIP nem manifesto separado;
- não há expiração ou comando de reconciliação para arquivos abandonados em quarentena/staging;
- limites reais de upload do PHP e do proxy precisam ser validados no ambiente de produção;
- acesso autenticado offline não está disponível;
- a ativação de S3 ainda depende de instalação/configuração do adapter, credenciais e infraestrutura.

## Arquivos e áreas relacionadas

- Rotas web: `routes/web.php`
- Rotas internas: `routes/api.php`
- Componentes administrativos: `app/Livewire/Admin`
- Serviços de domínio: `app/Services/Knowledge`
- Job de processamento: `app/Jobs/ProcessKnowledgeDocument.php`
- Modelos: `app/Models/Knowledge`
- Migrações: `database/migrations`
- Testes funcionais: `tests/Feature`
- Testes unitários do pipeline: `tests/Unit/Services/Knowledge`
- Detalhes de arquitetura: `docs/technical-foundation.md`

## Padronização de UI/UX administrativa

- Design system com componentes Blade, tokens em `resources/css/app.css` e regras em `docs/admin-design-system.md`.
- Todas as telas admin migradas para `<x-knowledge-admin.shell>`; documentos agora têm o mesmo menu completo.
- Interface 100% em português (locale `pt_BR`, status traduzidos, paginação e validação em português).
- Fiscal automático: `tests/Feature/Admin/AdminDesignSystemTest.php`.
- Confirmações em janela própria (`<x-knowledge-admin.confirm-modal>`), no lugar da caixa nativa do navegador (`wire:confirm`): gerar nova senha provisória e desativar/reativar acesso (página do usuário e cartões da lista), processar/reprocessar documento e limpar a seleção de PDFs. Cada janela tem título em forma de pergunta, a consequência explicada e o botão com o verbo da ação (vermelho nas ações de risco); fecha com Cancelar, Esc ou clique fora. Regras de uso em `docs/admin-design-system.md`; testes: `tests/Feature/Admin/ConfirmModalAdminTest.php` e `AdminDesignSystemTest` (reprova `wire:confirm`).

## MVP de consulta, RAG e avaliação (04/11/2026)

- **Catálogo de princípios ativos** (`active_ingredients`, `active_ingredient_aliases`, `anvisa_medicine_ingredients`, `knowledge_document_ingredients`): ponte PT↔EN entre ANVISA e DDInter por nome completo, remoção de sais/hidratos, aliases por regra ortográfica (só aceitos se coincidirem com um nome DDInter) e CSV curado (`database/data/ingredient_aliases.csv`, pendente de revisão farmacêutica). Comando `php artisan knowledge:build-ingredients` (idempotente).
- **Recuperação** (`app/Services/Retrieval`): FTS `portuguese`, vetorial (pgvector) e híbrida (RRF k=60); `knowledge:index-embeddings` grava modelo/dimensão por chunk. Só documentos READY de fontes ativas.
- **Análise de interações** (`app/Services/Interactions`): existência/gravidade vêm do DDInter; o texto vem de trechos de bula, validado (`ExplanationValidator`); estados explícitos de inconclusão.
- **PWA do usuário** e histórico: `docs/consulta-pwa.md`. **Provedor Anthropic**: `docs/ai/anthropic-provider.md` (somente mocks). **Avaliação**: `docs/evaluation.md`. **Arquitetura e quadro**: `docs/architecture/`, `docs/engineering/implementation-board.md`.
- Comandos novos: `knowledge:build-ingredients`, `knowledge:index-embeddings`, `consultations:purge-expired`, `bulai:pipeline-check`, `evaluation:build-datasets`, `evaluation:run`.
- Limitações: sem serviço de embeddings (vetorial/híbrido não medidos), geração testada só com modelo local pequeno e mocks, dipirona e vários nomes comerciais sem princípio ativo no catálogo, nenhuma validação por profissional de saúde.

## Iteração 2 do MVP: bula humanizada, tratamento do paciente e analytics (04/11/2026)

- **Diretriz do produto** ("nunca substitui médico ou farmacêutico") centralizada em `config/bulai.php` e aplicada na interface, nos prompts e na validação: `docs/diretrizes-do-produto.md`.
- **Bula humanizada** (`/bula/{medicine}`): para que serve, como usar, benefícios, riscos e cuidados, efeitos colaterais, não use se, interações e quando procurar ajuda — gerada por RAG a partir das bulas cadastradas, validada afirmação a afirmação, cacheada por conjunto de princípios ativos e personalizada com alergias e interações do tratamento do usuário.
- **Perfil do paciente**: receitas médicas (prescritor, registro, data, observações) e posologia por medicamento (dose, frequência, início, término, receita vinculada).
- **Pergunta com contexto de tratamento**: ao escolher um medicamento o app informa se ele está em uso, com que posologia, se há alergia cruzada e com quais medicamentos em uso a base registra interação (gravidade maior/moderada/menor); a resposta da IA recebe esses fatos como evidência citável. Cada pergunta e resposta ficam registradas com evidências e métricas, visíveis no histórico e apagáveis.
- **Analytics e auditoria** (`/admin/analytics`): KPIs, distribuições (veredito, gravidade, status de geração, motivos de rejeição, modo de recuperação), taxa de ancoragem, latências por etapa, consumo e custo do provedor de IA, detalhe de cada consulta/pergunta/bula com os trechos completos; exportação JSON/CSV (`analytics:export`).
- **Embeddings e recuperação híbrida**: serviço local de embeddings e modo `hybrid` medidos em `docs/evaluation.md`; provedor Anthropic validado com chamada real.
- **PWA**: ícones PNG 192/512 (instalação em iOS/Android), aviso de nova versão também no admin, sem recarga indevida na primeira visita.
- Documentação acadêmica: `docs/tcc/24-bula-humanizada-contexto-de-tratamento-e-analytics.md`.
- **Identidade visual** (05/11/2026): tokens da marca (azul-marinho, laranja, creme) para admin e app do paciente; guia com contrastes verificados em `docs/design/identidade-visual.md`.
- **Administração redesenhada (05/11/2026)**: barra lateral em grupos (Base de conhecimento, Operação, Análises, Pessoas) recolhível e gaveta no celular, identidade visual do logotipo (azul-marinho, laranja, creme), Visão geral com tudo o que importa (atenção, operação ao vivo, base, uso, IA, pessoas) e página de **Relatórios** com geração sob demanda e download (`docs/relatorios.md`).
- **Autenticação e usuários (iteração 3, 05/11/2026)**: hash Argon2id com migração transparente do bcrypt, "Esqueci minha senha" por e-mail, "Manter conectado", bloqueio progressivo de tentativas, "Sair de outros dispositivos", histórico da própria conta, e administração de usuários completa (ordenação, filtros, envio da senha provisória por e-mail, exigir troca de senha, encerrar sessões, desativar com motivo, histórico paginado). Telas de acesso com a identidade visual do BULA-IA. Documentação: `docs/usuarios-e-autenticacao.md`.
- **Redesign do fluxo de ingestão da administração (05/11/2026)**: revisão de pendência refeita no padrão "um fato, uma vez" (cabeçalho com o medicamento, resumo decisório de quatro fatos com origem, uma ação primária contextual e menu "Mais", três abas, avanço automático para a próxima pendência com opção de ficar), listas de pendências/importações/documentos/origens com uma linha por item e um selo, andamento da importação em fragmento próprio com polling, envio de PDFs com área de arrastar e uma ação, componentes `page-header`, `menu-item` e `toast`. Contrato em `docs/design/principios-de-ux.md`.
- **Redesign das telas de operação e análises da administração (05/11/2026)**: Visão geral enxuta (atenção → uso em 7 dias → operação; o resto recolhido), Fila e serviços em selos e lista com confirmação, Catálogo e Interações com importação recolhida e totais em cache, Analytics com seções recolhíveis e listas de uma linha, Relatórios por tipo, Usuários com filtros em linha, um selo por pessoa e página com abas. Componentes `ops-row`, `ops-disclosure`, `ops-filters`; regras em `docs/admin-design-system.md` e `docs/design/principios-de-ux.md`.
- **Redesign sênior-primeiro do app do paciente (05/11/2026)**: texto 18 px+, botões ≥ 56 px, uma ação por linha, barra com 3 destinos, Início com 4 botões grandes, Falar/Ouvir progressivos, frase única de risco, página `/sobre` e aviso na primeira abertura. Detalhes em `docs/consulta-pwa.md`.
- **Programação do tratamento, avisos e remédios fora do catálogo (05/11/2026)**: a receita (foto ou à mão) nunca mais é barrada por remédio ausente no catálogo — ele entra pelo nome escrito, sinalizado ao paciente e enfileirado para a administração em **Remédios não encontrados** (ligar ao catálogo ou descartar; o paciente é avisado). A frequência vira horários por regra (`DoseScheduleParser`), ajustáveis; a tela **Remédios de hoje** lista cada horário com Tomei/Pular/Desfazer no fuso do paciente; a Início mostra a próxima dose. **Avisos**: lembrete de dose e fim de tratamento a cada 5 minutos pelo agendador (`treatment:send-reminders`, serviço `scheduler`), sino com não lidos, tela `/avisos` com preferências (lembretes, e-mail, fuso) e notificação do navegador com o app aberto. Docs: `docs/tratamento.md`, `docs/notificacoes.md`, `docs/admin-remedios-nao-encontrados.md`, `docs/tcc/25`.
- **Experiência nativa, conversa com contexto, tolerância a erros e landing page (06/11/2026)**: busca e detecção de remédios tolerantes a erros de digitação por trigramas no PostgreSQL (`pg_trgm`, `FuzzyMedicineMatcher`; "lozartana" → Losartana, com "Você quis dizer…?" e "Entendemos X como Y" reversível); a tela **Perguntar** virou conversa (as últimas trocas entram como contexto rotulado, nunca como instrução; "e com café?" herda os remédios) com ditado por voz contínuo e com mensagens de erro; **PWA instalável** (cartão "Instalar", passo a passo iOS, item no menu, manifesto e service worker revisados) e sensação nativa (safe areas, transições de página, toque, guardas globais de layout, `ConsultaDesignSystemTest`); **todas as telas do paciente redesenhadas** (cartão do dia com anel de progresso, linha do tempo das doses, segmentos, folha de ações, estados vazios ilustrados) sem quebra a 360–400 px; **landing page** pública com SEO, mockup, recursos, segurança e limites, FAQ. Docs: `docs/consulta-pwa.md` (iteração 5), `docs/design/principios-de-ux.md` (seção 9), `docs/design/landing-page.md`, `docs/tcc/26`.
- **Programação dinâmica por intervalo (06/11/2026)**: remédios "de X em X horas" recontam a próxima dose a partir do horário real em que o paciente marcou "Tomei" (tomou a das 06:00 às 09:30 → próxima 17:30); pular não desloca, desfazer restaura, lembretes seguem a agenda recalculada; o paciente escolhe entre "Contar a partir de quando eu tomar" e "Horários fixos". Docs: `docs/tratamento.md`.
- **Área administrativa profissional (06/11/2026)**: correção das áreas em branco (grade esticada), shell com trilha de navegação, painel "Ajuda desta tela" com "para que serve, quando usar, passo a passo" para todas as telas, busca global (Ctrl+K), menu com descrições, Visão geral com KPIs, fluxo das bulas e "Precisa de atenção"; todas as listas em tabelas densas ordenáveis com barra de filtros única, detalhes com fatos e abas, formulários compactos. Regras fiscalizadas por `AdminDesignSystemTest`; contrato em `docs/admin-design-system.md` e `docs/design/principios-de-ux.md` (seção 10).
- **Perguntar como chat amigável (06/11/2026)**: balões de conversa com avatar do BULA-IA, indicador "digitando…", boas-vindas com sugestões; quando a bula não responde, o assistente fala em português simples ("Procurei na bula de X e não achei nada sobre isso…", "Ainda não tenho a bula de X comigo…") com sugestões clicáveis de outras perguntas, sem listas de limitações, seção/página ou jargão; detalhes técnicos ficam recolhidos em "Ver na bula". Textos por situação em `docs/consulta-pwa.md`.

## Do PDF aprovado ao Perguntar (índice automático)

**Problema:** um documento criado pela revisão de pendência ficava `READY` mas o Perguntar não o usava: o vínculo documento → princípio ativo e os embeddings só eram gerados por comandos manuais (`knowledge:build-ingredients`, `knowledge:index-embeddings`), e o catálogo de princípios nasce do DDInter, que não cobre todo medicamento brasileiro (ex.: nimesulida; 17.493 de 39.416 medicamentos ANVISA sem princípio ligado).

**Agora, ao fim de `ProcessKnowledgeDocument` (documento `READY`):**
1. `KnowledgeDocumentIngredientLinker::link()` liga a bula aos princípios ativos; se o princípio declarado na bula não existe no catálogo, cria-o (alias `origin=document`, `review_status=pending_review`, sem interações) e liga os medicamentos ANVISA com o mesmo ativo.
2. `KnowledgeIndexer::index(documentId:)` gera os embeddings só dos trechos desse documento.
Falha nessa etapa é registrada (`knowledge_document_post_processing_failed`) e não derruba o documento; a busca textual continua funcionando. Reprocessar à mão: `php artisan knowledge:index-embeddings`.

**Bula do paciente × profissional:** o mesmo registro pode ter os dois documentos (`KnowledgeDocumentLifecycleService`). O Perguntar usa todos os documentos ligados ao princípio ativo; cada evidência carrega `document_type`. O resumo de bula (`LeafletSummaryService`) prefere a do paciente e só usa a do profissional na ausência dela.

**Operação:** `queue:work` não recarrega código; reinicie o worker (`php artisan queue:restart`) após o deploy.

Testes: `ActiveIngredientCatalogTest` (vínculo/criação do princípio), `KnowledgeDocumentProcessingTest::test_job_runs_the_processor_and_command_dispatches_the_job` (embeddings ao fim do job).
- **Observabilidade do pipeline de ingestão e retaguarda do classificador (06/11/2026)**: o OCR passou a rodar em processo filho persistente e informa a página atual em `/status` (`current`), o que tirou o container de 1,5–4,4 s para ≤ 27 ms de resposta durante o OCR; a saúde dos serviços é sondada em segundo plano a cada 5 s (`services:probe-health`) e distingue "ocupado, sem resposta" de "inacessível"; cada documento ganhou uma **linha do tempo do processamento** (`knowledge_processing_events`: texto nativo, OCR, normalização, trechos, embeddings por lote, vínculo), visível na página do documento e, para os documentos em andamento, na tela Fila e serviços e no evento SSE (com "página X de Y" casada pelo `correlation_id`); o **Claude** pode ser ligado como retaguarda do classificador local (`DOCUMENT_CLASSIFIER_FALLBACK_DRIVER=anthropic`), chamado só quando a IA local falha, responde com confiança baixa ou não acha o princípio ativo, com as mesmas checagens de respaldo e custo registrado. Docs: `docs/realtime-options.md`, `docs/technical-foundation.md`, `docs/tcc/08` (5.1), `services/document-ocr/README.md`, `docs/tcc/27`.
- **Índice de busca como etapa própria, lotes de embeddings e fuso horário (07/11/2026)**: a linha do tempo revelou `AI_TIMEOUT` na etapa de embeddings (16 trechos por requisição contra um serviço que calcula um texto por vez, com 60 s de limite na VPS). Agora cada requisição é limitada por quantidade (`AI_EMBEDDING_BATCH_SIZE`, 4) e por caracteres (`AI_EMBEDDING_BATCH_CHARACTERS`, 6.000), um lote que estoura o tempo é refeito trecho a trecho, e os limites subiram para 180 s (`AI_EMBEDDING_TIMEOUT`, `LOCAL_AI_REQUEST_TIMEOUT` do serviço `embeddings`). O índice roda no job `IndexKnowledgeDocumentEmbeddings` (enfileirado ao fim de `ProcessKnowledgeDocument`; `KNOWLEDGE_INDEX_JOB_TIMEOUT_SECONDS`, 600 s; `failed()` marca FAILED), reiniciando só a linha `embed` da linha do tempo. Administração: alerta e botão "Gerar o índice de busca"/"Refazer só o índice de busca" na página do documento (com o código do erro explicado em português), "Refazer o índice de busca" no menu Mais e "Gerar N índices de busca pendentes" na lista de documentos. Fuso: `APP_TIMEZONE=America/Sao_Paulo` e `TZ`/`PGTZ` em todos os containers (dev e produção; `compose.prod.yaml` muda → reinstalar o conjunto confiável). Docs: `docs/tcc/28-…`, `docs/deployment/release-2026-11-07.md`. Testes: `tests/Feature/Knowledge/KnowledgeSearchIndexTest.php` (6).
- **Falha no e-mail da senha provisória sem 500 (07/11/2026)**: ao marcar "Enviar por e-mail" em Gerar nova senha ou Novo usuário, uma recusa do transporte (API da KingHost, SMTP) derrubava a tela com 500 depois de a senha já ter sido trocada. Agora `UserAccountService::emailTemporaryPassword` registra `temporary_password_email_failed` (sem a senha) e lança `TemporaryPasswordEmailException`; as telas mostram a senha no modal com um aviso explicando o motivo, e o envio não é auditado. Teste: `UserManagementAdminTest::test_a_mail_transport_failure_still_shows_the_temporary_password_with_a_warning_instead_of_a_500`.
- **Vínculo bula ↔ princípio ativo como ação própria (07/11/2026)**: o Perguntar chega à bula pelo vínculo em `knowledge_document_ingredients`; bulas processadas antes do vinculador (ou sem metadados de identificação) ficavam "sem princípio ativo" e a resposta era "ainda não consigo ligar a uma bula". `KnowledgeDocumentIngredientLinker` ganhou a estratégia `catalog` (nome do produto normalizado → `anvisa_medicines.normalized_product_name` → ingredientes já ligados ao catálogo, confiança 0,6), usada quando registro, metadado e título não resolvem. Admin: alerta "Nenhum princípio ativo ligado" com botão "Vincular princípios ativos" (síncrono, grava a etapa `link` na linha do tempo) e "Revincular princípios ativos" no menu Mais; na lista, "Vincular N documentos sem princípio ativo" (job `LinkKnowledgeDocumentIngredients`). Pré-requisito em produção: `knowledge:build-ingredients` executado após o catálogo ANVISA e o DDInter (sem ele o próprio catálogo não tem ingredientes e o Perguntar responde "não consegui ligar X a uma bula"). Testes: `tests/Feature/Knowledge/KnowledgeIngredientLinkActionsTest.php` (3).
- **Perguntar confiável: detecção robusta, bula inteira como segunda fonte, evidência auditável e revisão humana (07/11/2026)**: em produção o Perguntar quase nunca achava o remédio porque o catálogo ANVISA estava sem princípios ativos ligados (cada grafia do ativo virava um grupo e o termo era descartado como ambíguo; a consulta com `limit(600)` ordenada por nome expulsava "losartana" atrás de centenas de "dipirona"). O detector passou a consultar o catálogo de forma agregada (um grupo por nome × ativo), a agrupar sem vínculo pelos componentes do ativo sem sal/dose, a aplicar a regra de foco (nome exato > ativo partilhado > até 3 grupos > ambíguo), a aceitar sufixos de linha ("naprix d") e ditado partido ("ome prazol") e a sugerir "Você quis dizer…?" quando nada é reconhecido. `MedicineLeafletFinder` liga o catálogo na hora (aliases, sais e **tradução por IA** dos nomes em português para o DDInter, alias `pending_review`) ou acha a bula pelo nome do produto. A pergunta ganha intenção (`QuestionIntentDetector`: efeitos colaterais, como tomar, interações…) que dirige a busca para a seção certa (bula do paciente primeiro); se o modelo não responde, a **bula inteira** é lida (`LeafletEvidenceService`) e o trecho literal + seção + página + confiança, conferido contra o documento, vira `knowledge_leaflet_facts` (método `pdf_fallback_ai`, hash e versão do documento, revisável na aba "Evidências extraídas") e é reaproveitado nas próximas perguntas. A resposta fala com a pessoa (prompt `interaction-explanation-v2`), cita "Fonte: bula do paciente de X, parte “…”, página N", nunca mostra trechos de outra seção e, sem evidência, diz isso com honestidade e põe a pergunta na fila **Perguntas sem resposta** (admin, `review_status`) para adquirir fontes. `BuildActiveIngredientCatalog` roda sozinho após cada importação do catálogo e do DDInter. Docs: `docs/tcc/29-…`, `docs/deployment/release-2026-11-07.md` §7. Testes: `tests/Feature/Consulta/PerguntarConfiavelTest.php` (7).
- **Web Push, balão no PWA e microfone (07/11/2026)**: o aviso de dose só aparecia com o aplicativo aberto; agora há Web Push real sem dependência (VAPID com `openssl`, push vazio + `/push/ultimo`, tabela `push_subscriptions`, canal `WebPushChannel`, comando `webpush:generate-keys`, botão na tela Avisos, evento `push` no service worker v6). O layout do balão deixou de quebrar com palavras longas (`min-width: 0` e `overflow-wrap: anywhere` em `.app-screen`). O microfone não pedia permissão porque o nginx enviava `Permissions-Policy: microphone=()`; agora `microphone=(self)`. Docs: `docs/notificacoes.md`. Testes: `tests/Feature/Consulta/WebPushTest.php` (5).
- **Motor conversacional do Perguntar (07/11/2026)**: o Perguntar devolvia trechos brutos ("Não consegui montar uma resposta curta…") quando o modelo não podia afirmar algo citado, porque o contrato só aceitava afirmações citadas, a busca ignorava a seção e o fallback mostrava os primeiros trechos. Agora o modelo devolve segmentos tipados (`fact` citado, `limit`, `ask`) com cobertura; a intenção é composta ("posso tomar X?" = para que serve + quando não usar + cuidados) e cada seção tem a sua busca; cobertura incompleta dispara a leitura da bula inteira; há uma reformulação controlada; sem evidência a resposta é específica e vai para revisão humana; trecho bruto nunca substitui a resposta; urgência é orientada antes de qualquer consulta (Disque-Intoxicação e SAMU); fontes ficam recolhidas ("Fontes utilizadas"), sem repetição. Avaliação com o modelo real (`evaluation:conversation`, 8 cenários, 5 dimensões): de 6/10 relevância e 4/7 naturalidade para 9/9 e 8/8 (ibuprofeno e omeprazol). Docs: `docs/tcc/30-…`. Testes: `tests/Feature/Consulta/ConversaHumanizadaTest.php` (13).
- **Administração: monitor largo, contraste, tabelas que não vazam e celular (07/11/2026)**: a captura em monitor de 2576 px mostrou conteúdo preso a 1280 px, cabeçalho de coluna quebrando por letra, texto de célula sobre a coluna vizinha (`nowrap` + `max-w-*`), barra lateral com rolagem horizontal e fundo todo branco. Corrigido nos componentes: conteúdo em largura total, células que quebram, `th` com `nowrap` e cabeçalho escuro, fundo `slate-200` com cartões brancos, selos/alertas/indicadores mais contrastados, menu compacto de uma linha por item sem rolagem horizontal, título e subtítulo que quebram, tabelas com primeira coluna fixa no celular, e a tela Perguntas sem resposta com abas de situação e atualização a cada 20 s. Docs: `docs/admin-design-system.md`. Testes: `AdminLayoutContractTest` (5). Sem conferência visual em navegador.
- **E-mails com a identidade do BULA-IA (07/11/2026)**: os e-mails saíam no modelo padrão do Laravel (preto e branco, "Laravel", "All rights reserved", "Regards", link alternativo em inglês). Agora usam o tema `bulai` (azul-marinho, laranja e creme), cabeçalho com o símbolo em PNG e o nome com o lema, botão laranja de contraste AA, painel de destaque (senha provisória e horário do remédio), rodapé em português com o aviso do produto e o caminho de volta ao aplicativo, e versão de texto simples com a mesma identidade. Layouts dedicados para senha provisória e lembrete de dose. Docs: `docs/design/identidade-visual.md` §12 e exemplos em `docs/design/emails/`. Testes: `EmailIdentidadeTest` (6). Sem conferência em clientes de e-mail reais.
- **Modo cuidador com handshake (09/11/2026)**: o paciente gera um código de convite de 8 números (uso único, 48 h); quem cuida, com conta própria, digita o código e o vínculo fica pendente até o paciente aprovar escolhendo as permissões (avisos de dose atrasada, ver remédios e horários, marcar doses e editar dose/horários; editáveis e revogáveis a qualquer momento). Permissões aplicadas em policies no servidor, acesso só com o consentimento de saúde do paciente, 5 tentativas erradas de código por 15 min, auditoria LGPD de cada passo e de cada ação de quem cuida (histórico visível ao paciente), troca de perfil "Eu / Cuidando de Fulano" no menu do avatar com faixa fixa de quem são os dados, e aviso de dose atrasada para quem cuida no sino e por Web Push pelo mesmo agendador dos lembretes. Docs: `docs/cuidador.md`.
- **Marca, vínculo corrigível no Admin, bula simples e "Posso tomar junto?" (10/11/2026)**: em produção "Para que serve o Dorflex?" usava a bula do Torsilax, porque 27 das 61 bulas não tinham princípio ativo ligado (entre elas Dorflex Pac, DORFLEX MAX®, DORFLEX® UNO, dipirona, Novalgina, omeprazol, Berotec) e a busca por princípio ativo (cafeína) vencia a busca pelo nome.
  - `MedicineLeafletFinder`: a bula da própria marca vem primeiro; bula de outra associação que só divide um ativo não entra (`docs/ai/perguntar-inteligencia-conversacional.md`).
  - Admin › Documento › aba **Princípios ativos**: tirar o vínculo errado, ligar o certo pela busca (nome em português ou inglês) e criar o princípio ativo que o catálogo não tem (dipirona, que o DDInter não traz). O vínculo manual (`method = manual`) e o tirado à mão (`metadata.ingredient_links.excluded`) sobrevivem ao "Revincular" e ao reprocessamento (`KnowledgeDocumentIngredientLinker::attach/detach/createIngredient`). Depois de criar um princípio ativo, `php artisan knowledge:build-ingredients` liga o catálogo ANVISA a ele.
  - Admin › Documentos: a lista mostra o **medicamento** (metadado `product_name` ou título antes de " — "; título de arquivo como "Bula 1793933368611" aparece como "Medicamento não identificado") e os princípios ativos ligados, com aviso "Sem princípio ativo ligado"; a busca também acha pelo medicamento, pelo princípio ativo declarado e pelo ligado. No documento, o cartão **Identificação** mostra medicamento, princípio ativo declarado e ligado, tipo, registro, detentor, título, arquivo e o começo do texto, **Abrir a bula (PDF)** mostra o PDF guardado (`GET /admin/knowledge/documents/{id}/pdf`, `KnowledgeDocumentFileController`, só Admin; também embutido na aba Arquivo), bula sem nome mostra a sugestão tirada do texto (primeira linha curta do começo que é produto do catálogo ANVISA, já preenchida ao editar), e **Editar identificação** grava a correção (`metadata.identity_edited_at`) e refaz o vínculo, mantendo as correções manuais (`KnowledgeDocument::medicineName/displayName`, `Admin\Documents\Show::saveIdentity`).
  - Admin › Analytics › Bulas humanizadas: a lista mostra os princípios ativos (ou o produto) em vez da chave; o detalhe dava 500 porque `supports_claims` da bula humanizada é "seção#índice" (agora "Para que serve 1").
  - Bula simples: voz da própria bula (`humanized-leaflet-v2`), página `/bulas` e botão no Perguntar (`docs/consulta-pwa.md`).
  - "Posso tomar junto?" (`/consulta`): os remédios em uso (tratamento não encerrado) já entram na lista; `?com={id}` acrescenta o remédio da bula simples; "Verificar interações entre eles" (`?usar=1`) verifica e abre o resultado num toque.
  - Folha de ações (`x-consulta.folha-acoes`) vai para o fim do `body` (`@teleport`): no cartão de tratamento encerrado (`opacity-75`) ela herdava a transparência e ficava atrás dos cartões seguintes.
  - Testes: `PerguntarConfiavelTest` (marca e associação), `KnowledgeIngredientLinkActionsTest` (correção do vínculo e da identificação no Admin), `BulaHumanizadaTest` (Admin da bula humanizada, voz, página), `PerfilEPerguntasTest` ("Posso tomar junto?").

### Princípio ativo duplicado em português escondia interações (11/11/2026)

**Problema (medido em produção):** as bulas foram vinculadas antes de o catálogo conhecer o nome em português. O vinculador só consultava aliases já gravados e, sem achar "ibuprofeno", criava um princípio ativo novo ("Ibuprofeno", "Besilato De Anlodipino", "Losartana Potássica"), sem interações. O `knowledge:build-ingredients` seguinte achava esse alias exato e ligava o catálogo ANVISA ao duplicado, não ao princípio do DDInter. Resultado na avaliação de 11/11 (`prod-interacoes-1483`): 81 falsos negativos, 7 interações graves perdidas (Marevan + ibuprofeno, Norvasc + Tegretol…).

**Correção:**
- `ActiveIngredientResolver::resolveAndLearn()` (antes privado no construtor do catálogo) resolve pela regra cognata e grava o alias aprendido; o vinculador (metadado da bula) e o Admin ("Criar o princípio ativo") passam por ele antes de criar — "ibuprofeno" leva a *ibuprofen*.
- `DuplicateIngredientMerger` (chamado por `knowledge:build-ingredients`, antes de ligar o catálogo): cada princípio ativo sem alias do DDInter cujo nome resolve para outro (ignorando a si mesmo) é fundido nele — vínculos de bula e de medicamento, bula dos EUA, aliases e `metadata.ingredient_links.excluded` migram; o duplicado é apagado. O comando lista "Duplicado fundido → No princípio ativo".
- Operação: após o deploy, `sudo bulai-deploy artisan knowledge:build-ingredients` e reexecutar `evaluation:run identification` e `interactions`.
- Testes: `ActiveIngredientCatalogTest` (bula vinculada antes do catálogo usa o princípio do DDInter; duplicado existente é fundido e o medicamento volta a ter as interações).
