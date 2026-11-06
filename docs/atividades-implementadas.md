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
