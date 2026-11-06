# Design system da área administrativa

Regras para que **toda** tela admin tenha a mesma aparência, o mesmo idioma e a mesma estrutura.
Quem cria ou altera uma tela (pessoa ou agente) deve seguir este documento. O teste
`tests/Feature/Admin/AdminDesignSystemTest.php` reprova o que fugir dele.

## Princípio: simples = direto e claro

- Mostre o que o administrador precisa para **decidir ou agir**; detalhe técnico (SHA-256, códigos, metadados) fica em seções de "Detalhes", nunca no topo nem nas listas.
- Cada tela tem **uma ação principal** (botão `primary`). Ações secundárias usam `secondary`; destrutivas, `danger`.
- Listas mostram título, 1 linha de contexto, situação e ação. Nada além disso.
- Estados vazios sempre explicam em uma frase (`empty-state`).

## Estrutura de página

Toda view em `resources/views/livewire/admin/**` começa com `<x-knowledge-admin.shell>`, que fornece a barra lateral, o cabeçalho, o menu da conta, o título (`h1`), o subtítulo, o link de voltar, o slot `actions` e o alerta `accountFeedback`.

**Shell (reformulação de 06/11/2026, "admin profissional").** Barra lateral fixa à esquerda em telas grandes (`lg`), na cor da marca (`bg-brand-800`), com o menu em **grupos**; cada item tem ícone, rótulo e uma **descrição curta** na segunda linha (`AdminNavigation::groups()[...]['description']`), para que se saiba o que cada tela faz antes de abrir. Recolhe para só ícones (preferência em `localStorage`, chave `bulai-admin-collapsed`); em telas menores vira uma gaveta com foco preso. O cabeçalho fino traz, da esquerda para a direita: a **trilha** "Grupo › Tela" (automática, a partir de `AdminNavigation::find(rota)`; em telas de detalhe aparece "Grupo › Lista › Detalhe"), a **busca global** (`<livewire:admin.global-search />`, atalho Ctrl/⌘+K), a saúde dos serviços (`xl+`), o botão **"Ajuda desta tela"** (ícone ?) e o avatar com o menu da conta.

**Ajuda contextual.** O botão "?" abre um painel à direita com o conteúdo de `App\AdminHelp::for(rota)`: *Para que serve*, *Quando usar*, *Passo a passo* e *Telas relacionadas* (links), mais o fluxo das bulas (Adicionar → Importações → Pendências → Documentos, `AdminHelp::workflow()`). Toda rota do admin com tela própria tem uma entrada; o teste `AdminHelpAndSearchTest` reprova rotas sem ajuda ou fora do menu. Para uma tela nova: acrescente o item em `AdminNavigation` (rótulo, descrição, dica) e a entrada em `AdminHelp` (texto operacional, em português simples).

**Busca global** (`App\Livewire\Admin\GlobalSearch`, view em `resources/views/livewire/shared/global-search.blade.php`): a partir de 2 letras, até cinco resultados por grupo em *Telas* (rótulo, descrição e propósito), *Documentos* (título e chave externa), *Catálogo ANVISA* (`MedicationSearchService`, com tolerância a erros de digitação) e *Usuários* (nome e e-mail). Setas percorrem, Enter abre, Esc fecha; limite de 120 buscas por minuto por administrador; só administradores (403 para os demais).

**Sem áreas vazias.** O `main` é uma grade `content-start`: sem isso a altura sobrando da tela era distribuída entre as linhas e abria espaços em branco entre o título e os filtros (defeito visto na tela Documentos). O cabeçalho de página é compacto (título 2xl, subtítulo em uma linha, ações à direita) e o espaçamento entre blocos é `gap-4`. O teste de design reprova `grid` com `flex-1` sem `content-start`, blocos `py-10`+ e itens de lista em cartão alto.

Menu em grupos (ordem do fluxo de trabalho): **Base de conhecimento** (Visão geral → Adicionar documentos → Importações → Pendências → Remédios não encontrados → Documentos → Origens → Catálogo ANVISA → Interações), **Operação** (Fila e serviços), **Análises** (Analytics, Relatórios), **Pessoas** (Usuários). Rota, rótulo, padrão de rota ativa, ícone, dica e descrição ficam em **`app/AdminNavigation.php`**; `AdminNavigation::items()` (lista plana) alimenta o resumo do primeiro acesso e `AdminNavigation::icon(nome)` expõe os ícones a outros componentes.

Páginas fora do admin (`auth/login.blade.php`, `auth/account.blade.php` e `auth/first-access.blade.php`) não usam o `shell`: são páginas autônomas com os mesmos componentes e tokens, e também são fiscalizadas pelo teste.

```blade
<x-knowledge-admin.shell title="Importações" subtitle="Histórico dos envios." :back="route('...')" back-label="Início">
    <x-slot:actions><x-knowledge-admin.button :href="route('...')">Adicionar documentos</x-knowledge-admin.button></x-slot:actions>
    <x-knowledge-admin.toolbar count="17 importações">
        <x-knowledge-admin.search compact wire:model.live.debounce.300ms="search" placeholder="Nome ou origem" />
        <x-knowledge-admin.select compact label="Situação" wire:model.live="status">…</x-knowledge-admin.select>
    </x-knowledge-admin.toolbar>
    <x-knowledge-admin.data-table :columns="[['label' => 'Nome', 'sort' => 'name'], ['label' => 'Arquivos', 'align' => 'right'], ['label' => 'Situação'], ['label' => '']]" :sort="$sort" :direction="$direction">
        @foreach ($batches as $batch)
            <tr wire:key="b-{{ $batch->id }}"><td>…</td><td class="tabular">{{ $batch->total_items }}</td><td><x-knowledge-admin.status-badge :status="$batch->status" /></td><td><x-knowledge-admin.row-actions><x-knowledge-admin.button variant="quiet" size="sm" :href="route('...')">Abrir</x-knowledge-admin.button></x-knowledge-admin.row-actions></td></tr>
        @endforeach
        <x-slot:empty>Nenhuma importação ainda.</x-slot:empty>
    </x-knowledge-admin.data-table>
    {{ $batches->links() }}
</x-knowledge-admin.shell>
```

Ordem do conteúdo: alertas → `toolbar` (filtros + contagem) → conteúdo principal (`data-table` em listas, `section`/`card` em detalhes) → paginação (`{{ $items->links() }}`).
Título da aba (`->title()` no componente Livewire): `Nome da tela · BULAI`.

## Componentes (`resources/views/components/knowledge-admin`)

| Componente | Uso |
|---|---|
| `shell` | Estrutura da página (obrigatório): barra lateral, trilha, busca global, ajuda, cabeçalho compacto |
| `button` | Ação ou link (`variant`: primary, secondary, accent, danger, quiet, link; `size`: md ou `sm` para ações de linha; `href`; `loading-target` + `loading`) |
| `toolbar` | Barra de filtros em uma linha (`count`, slot `actions`); dentro, `search`/`select`/`input` com `compact` (rótulo oculto, 36px) |
| `data-table` | Tabela densa para listas: `columns` (`label`, `class`, `sort`, `align`), `sort`, `direction`, `sort-event` (padrão `sortBy`), `dense`, slot `empty`; cabeçalho fixo em `lg+`, zebra, destaque ao passar o mouse; `td.tabular` para números |
| `row-actions` | Ações de linha alinhadas à direita (`button variant="quiet" size="sm"`) |
| `tabs` | Abas sublinhadas ligadas a uma propriedade Livewire (`tabs` com `key`/`label`/`count`, `active`, `model`) |
| `callout` | Aviso compacto de uma linha (`tone`, `title`) |
| `workflow` | Fluxo em etapas numeradas (`steps` com `label`, `href`, `hint`, `count`, `state` done/current/todo) |
| `kpis` / `kpi` | Grade de indicadores (2 colunas → 4 em `md`) e indicador compacto (rótulo, valor, complemento, `tone`, `href`) |
| `card`, `section` | Blocos de conteúdo (`section` = card com título/descrição/`actions`) |
| `input`, `select`, `textarea`, `field` | Campos com rótulo, obrigatório/opcional, dica e erro automático (`wire:model`); `compact` em barras de filtro |
| `search` | Campo de busca de listas (`compact` na `toolbar`) |
| `table` | Tabela simples (`:columns`) para dados comparáveis em detalhes; para listas use `data-table` |
| `stat` | Indicador numérico clicável (grande) |
| `sidebar-nav`, `sidebar-link` | Menu lateral em grupos e seu item (ícone, rótulo, descrição, marcador ativo, selo de pendências) |
| `facts` / `fact` | Pares rótulo → valor |
| `badge`, `status-badge` | Rótulos pequenos (`text-xs px-2 py-0.5`; tons neutral, success, warning, danger, info, brand); `status-badge` traduz os status do sistema |
| `document-processing-states` | Situação de PDF, texto e índice de busca (`compact`/`inline`) |
| `alert` | Mensagens maiores (`success`, `warning`, `danger`, `info`) |
| `empty-state` | Lista vazia compacta (ícone, uma frase, uma ação); sem blocos altos |
| `ops-row`, `ops-disclosure`, `ops-filters` | Linha de lista enxuta, seção recolhível e barra de filtros antiga (prefira `toolbar`) |
| `modal`, `confirm-modal`, `temporary-password-modal` | Diálogos e confirmações (ver "Confirmações") |
| `page-header`, `menu-item` | Faixa de decisão das telas de detalhe (selo, metadados, UMA ação primária, "Mais") |
| `toast` | Aviso breve (4 s) incluído pelo shell; `$this->dispatch('notify', message: '…', tone: '…')` |

Antes de criar HTML novo, procure o componente. Se faltar um, crie-o **na pasta de componentes** e documente aqui; não estilize na view.

## Padrão de tela (contrato de UX)

Regras completas em `docs/design/principios-de-ux.md`. Em resumo, toda tela de detalhe segue três níveis:

1. **Cabeçalho**: `h1` do shell com o nome do que se decide (ex.: o medicamento identificado, não o nome do PDF) e `page-header` com um selo de situação, até dois metadados, **uma** ação primária contextual e as demais em "Mais".
2. **Resumo decisório**: um `card` com até quatro fatos (`dl` com rótulo pequeno, valor e, quando houver, a origem do dado em uma linha pequena) e uma frase que diz a consequência da ação primária.
3. **Detalhe sob demanda**: no máximo três abas (`role="tablist"`, Alpine, marcador `border-accent-500`), nenhuma repetindo o resumo. O que diverge aparece; o que concorda vira uma frase.

Listas: uma linha por item (título em negrito, uma linha de apoio, **um** selo, uma ação "Abrir"/"Revisar"); filtros em linha com contagem; estado vazio com ação. Partes que mudam sozinhas ficam em componentes Livewire filhos com `wire:poll` próprio (`resources/views/livewire/shared/*`, ex.: `batch-progress`), nunca na página inteira. Toda ação tem `loading-target` e, ao concluir, dispara o `toast`.

Telas que seguem o padrão (05/11/2026): revisão de pendência, pendências, importações (lista e detalhe), adicionar documentos, documentos (lista, cadastro e detalhe) e origens.

## Confirmações (`confirm-modal`)

Toda ação que muda dado ou acesso de forma difícil de desfazer pede confirmação com `<x-knowledge-admin.confirm-modal>`. **Não use `wire:confirm`**: ele abre a caixa cinza do navegador ("127.0.0.1 diz… OK/Cancelar"), fora do padrão visual; o teste de design reprova.

O botão que abre a janela fica no slot, **sem** `wire:click` (pode manter `variant` e `loading-target` próprios). A ação vai em `action`, com a mesma expressão que iria no `wire:click`:

```blade
<x-knowledge-admin.confirm-modal action="toggleActive({{ $user->id }})" tone="danger" title="Desativar acesso?"
    :message="'O acesso de '.$user->name.' será bloqueado e a pessoa será desconectada imediatamente.'"
    confirm-label="Desativar acesso" loading="Desativando…">
    <x-knowledge-admin.button variant="quiet">Desativar</x-knowledge-admin.button>
</x-knowledge-admin.confirm-modal>
```

| Parâmetro | Uso |
|---|---|
| `title` | Pergunta curta: verbo + objeto + "?" ("Desativar acesso?", "Limpar seleção?"). |
| `message` | A consequência em 1–2 frases: o que muda, para quem e se dá para desfazer. Com dado dinâmico, passe `:message` concatenando; não use `{{ }}` dentro do atributo (o texto seria escapado duas vezes). |
| `confirm-label` | Verbo da ação ("Desativar acesso", "Gerar nova senha", "Limpar seleção"). Nunca "OK" ou "Sim". |
| `action` | Método Livewire, com argumentos quando houver: `clearFiles`, `toggleActive(5)`, `processDocument(true)`. Argumento de texto vindo de dado passa por `Js::from`, como no `wire:click`. |
| `tone` | `danger` (botão vermelho) para ação destrutiva ou de risco: desativar acesso, gerar nova senha, reprocessar (substitui os trechos), limpar seleção (descarta os PDFs enviados). `primary` (padrão) para as demais: reativar acesso, processar pela primeira vez. |
| `loading` | Texto do botão de confirmar enquanto a ação roda ("Desativando…"). |
| `loading-target` | Opcional; o padrão é a própria `action`. |

Comportamento: a janela abre e fecha no navegador (Alpine, sem requisição). Fecha com "Cancelar", "✕", Esc ou clique fora. O botão de confirmar chama `$wire.<ação>`, mostra o texto de `loading` e desabilita os botões da janela até a resposta (sem clique duplo); depois fecha. Acessibilidade: `role="dialog"`, `aria-modal`, `aria-labelledby`/`aria-describedby` com ids derivados da ação (únicos mesmo com uma janela por cartão), foco inicial em "Cancelar" (ação segura), foco preso na janela (`x-trap`, do plugin Focus que já vem no Livewire), rolagem da página travada e foco devolvido ao botão de origem ao fechar. O componente **não** usa `wire:key`: no Livewire 4 ele entraria na chave dos componentes Livewire renderizados depois na página.

## Tokens visuais

- **Fonte:** pilha do sistema, definida em `resources/css/app.css` (`--font-sans`). Não importar outras.
- **Cores (identidade do logotipo, `docs/design/identidade-visual.md`):** marca `brand` (azul-marinho `#12344D` = `brand-700`; barra lateral `brand-800`), destaque `accent` (laranja `#D9661F` = `accent-500`; só para marcador ativo, foco na barra lateral e ênfase pontual, nunca para texto corrido), superfícies `surface` (creme `#F7F4EE`; fundo de página `surface-50` e dos `kpi`), neutros `slate`; estados `emerald` (sucesso), `amber` (atenção), `rose` (erro/destrutivo), `sky` (informação). Tudo definido em `@theme` (`resources/css/app.css`); nunca usar `teal-*` nem outras cores.
- **Ícones SVG:** sem os atributos `stroke-width`, `stroke-linecap` e `stroke-linejoin` nas views (o fiscal os lê como cores); use `class="... stroke-2"`.
- **Texto:** `text-xs` (rótulos de badge/tabela), `text-sm` (corpo), `text-base` (título de item), `text-xl`/`text-2xl` (título de página/login), `text-3xl` (indicadores). Peso: normal, `font-medium`, `font-semibold`.
- **Formas:** `rounded-lg` (controles e botões), `rounded-xl` (cards), `rounded-full` (badges); sombra `shadow-sm`.
- **Espaçamento:** `gap-*` entre blocos (`gap-3` lista, `gap-5` página); cards com `p-4 sm:p-5`.
- Sem `style=""`, sem valores arbitrários (`text-[13px]`), sem CSS dentro de views.

## Idioma e vocabulário (pt-BR)

Locale da aplicação: `pt_BR` (`lang/pt_BR`). Todo texto visível é em português; datas `d/m/Y H:i`; pluralização correta (1 arquivo / 2 arquivos).

| Use | Em vez de |
|---|---|
| Importação | Lote, Batch |
| Origem | Fonte |
| Trechos | Chunks |
| Índice de busca | Embeddings |
| Adicionar documentos / Enviar | Upload |
| Visão geral | Dashboard |
| Tarefas (da fila) | Jobs |
| Assinatura do arquivo (SHA-256) | Hash |
| Status em português (`status-badge`) | `PENDING`, `ASSOCIATED`... |

Marca: **BULAI** (nunca "BULA-IA" na interface).

## Fiscalização automática

`AdminDesignSystemTest` verifica: uso do `shell`; ausência de `<button>/<select>/<textarea>/<input>` crus nas telas; paleta, escala de texto, cantos e sombras; ausência de CSS inline; vocabulário proibido (visível em views e strings de `app/Livewire`, `app/AdminNavigation.php` e `app/AdminHelp.php`); sufixo `· BULAI` nos títulos; ausência de `wire:confirm` em `resources/views`; **densidade**: itens de lista em cartão alto (`rounded-xl border … py-3` com `wire:key`) só com `data-list-ok`, nenhum `py-10`+ e toda grade com `flex-1` tem `content-start`. `AdminHelpAndSearchTest` verifica que toda rota do admin tem ajuda contextual e lugar no menu, e que a busca global agrupa telas, documentos, catálogo e usuários só para administradores. Rode: `php artisan test --compact tests/Feature/Admin/AdminDesignSystemTest.php`.

Exceções permitidas e conscientes: `<input type="file">` e `type="checkbox"`, e o cartão selecionável de documento da revisão (`selectDocument`).

## Arquivos tocados nesta padronização

- `resources/css/app.css` (tokens e classes `ui-control`, `ui-table`)
- `resources/views/components/knowledge-admin/*`, `resources/views/livewire/admin/**`, `resources/views/auth/login.blade.php`, `resources/views/auth/account.blade.php`, `resources/views/auth/first-access.blade.php`
- `app/AdminNavigation.php` (itens e ícones do menu)
- `resources/views/vendor/livewire/tailwind.blade.php` (paginação em pt-BR)
- `lang/pt_BR/*`, `config/app.php`, `.env.example` (locale `pt_BR`)
- `app/Models/Knowledge/KnowledgeDocument.php` (`documentTypeOptions()`, `documentTypeLabel()`)

## Visão geral e Relatórios

- **Visão geral** (`Livewire\Admin\Knowledge\Dashboard` + `App\Services\Admin\OverviewService`), de cima para baixo: (1) quatro indicadores do dia (bulas prontas, pendências, remédios não encontrados, perguntas em 7 dias com o custo de IA no mês), (2) o fluxo das bulas com as contagens de cada etapa (`workflow`, `OverviewService::summary()['workflow']`), (3) "Precisa de atenção" (uma linha por item > 0, cada uma com link) ao lado de (4) "Atividade recente" (últimas cinco importações e perguntas, `summary()['recent']`), (5) "Operação" (componente `admin.knowledge.operation-panel`, `wire:poll.30s` + evento `bulai-realtime`), "Uso do aplicativo" (7 dias) e, recolhidos, "Base de conhecimento", "Inteligência artificial" (mês corrente) e "Pessoas". Os contadores pesados ficam em cache por 60 s (`admin-overview:*`); fluxo, atenção e atividade são lidos ao vivo.
- **Relatórios** (`/admin/relatorios`): ver `docs/relatorios.md`.
- **Remédios não encontrados** (`/admin/knowledge/unmatched-medicines`): uma linha por nome cadastrado por pacientes sem correspondência no catálogo, um selo, ação primária "Ligar ao catálogo" (painel inline com busca e candidatos) e "Descartar" com `confirm-modal`; ver `docs/admin-remedios-nao-encontrados.md`.

## Limitações conhecidas

- O manifesto PWA e o README ainda usam "BULA-IA".
- Não há modo escuro nem biblioteca de ícones.
- A verificação visual é manual (nenhum teste de navegador).

## Padrões das telas de operação e análises (redesign de 05/11/2026, princípios de `docs/design/principios-de-ux.md`)

Componentes novos (prefixo `ops-`): **`ops-row`** (linha de lista: título com link opcional, uma linha de apoio, um selo e uma ação, dentro de `<ul class="grid gap-2">`), **`ops-disclosure`** (`<details>` com título, resumo de uma linha e "Ver detalhes/Recolher"; o detalhe fica recolhido por padrão) e **`ops-filters`** (busca + até dois seletores em uma linha, com a contagem de resultados). Regras aplicadas:

- **Visão geral**: sem rolar, só "Precisa de atenção" (itens > 0), quatro indicadores de uso em 7 dias e o bloco de operação (`wire:poll.30s` só nele). Base de conhecimento, IA e Pessoas ficam em `ops-disclosure` com um resumo numérico na linha do título.
- **Fila e serviços**: uma linha de selos (processador da fila, IA local, leitura de PDF) com a hora da verificação, três indicadores da fila, lista de análises com um selo e ações `Reenfileirar`/`Cancelar` em `confirm-modal`; reinício de serviços em seção recolhida. Polling só no bloco que muda; o aviso de ação fica fora dele.
- **Catálogo ANVISA e Interações**: três indicadores, importação em "Importar arquivo(s)" recolhido (abre sozinho quando a base está vazia ou a última importação falhou), progresso em fragmento próprio (`wire:poll.3s`), histórico em `ops-row`. Os totais de interações (`count`, por gravidade e `distinct`) ficam em cache por 60 s com chave ligada à última importação concluída (`Interactions\Index::totals()`), o que atende ao achado da revisão sobre agregados pesados em cada atualização.
- **Analytics**: período no cabeçalho (slot `actions`), quatro indicadores, depois seções recolhíveis (Pares, Perguntas, Bulas, Desempenho, Consumo de IA) com barras de uma série (`bars`, rótulo sempre); listas em `ops-row` (uma linha, um selo, "Abrir"); detalhes com cabeçalho → fatos → pares/seções em `ops-disclosure` (só o primeiro par aberto; evidências e métricas recolhidas).
- **Relatórios**: lista de tipos com descrição de uma linha e "Gerar" (o formulário de formato/período aparece só no tipo escolhido); gerados em `ops-row` com selo, tamanho, Baixar/Apagar; `wire:poll.3s` só enquanto há geração em curso.
- **Usuários**: filtros em uma linha (busca, situação, perfil, ordenação) com contagem; uma linha por pessoa (apoio: e-mail · perfil · último acesso), **um** selo (Inativo / Troca de senha pendente / Ativo), "Abrir" e, discreto, Desativar/Ativar com confirmação; página da pessoa com cabeçalho (selo + Editar como ação primária), quatro fatos, "Mais ações" recolhido (senha provisória, exigir troca, encerrar sessões, desativar com motivo) e abas `Atividade | Uso do aplicativo` (`role="tablist"`, Alpine). Formulário em uma coluna.
- **Minha conta, Histórico e Primeiro acesso** já estavam em uma coluna com seções curtas e ação primária clara; não mudaram.
- Ícones SVG nas views do admin não levam `stroke-width`/`stroke-linecap` (o fiscal os lê como cores); as telas redesenhadas usam apenas texto e selos.

## Telas da base de conhecimento (reformulação de 06/11/2026)

Objetivo: densidade e clareza. Listas viraram tabelas (`data-table`) com ordenação por coluna, paginação de 25 e ações de linha discretas (`row-actions` com botões `quiet`/`sm`); os filtros ficam numa única barra (`toolbar`) logo abaixo do cabeçalho, com a contagem e a ação secundária à direita; detalhes usam `page-header` + `facts` + `tabs`. Nada é repetido: situação em um selo pequeno com o detalhe no `title`, "Abrir" só como ação de linha.

| Tela | Colunas / estrutura | Ordenação | Checklist (princípios §8) |
| --- | --- | --- | --- |
| Documentos | Título (+ identificador) · Tipo · Origem · Trechos · Índice de busca · Situação · Atualizado · Abrir | título, tipo, trechos, atualizado (`sortBy`, lista branca em `Documents\Index::SORTABLE`) | ação primária "Adicionar documentos"; "Cadastrar sem PDF" na barra; vazio com ação |
| Importações | Importação · Origem · Arquivos · Incorporados · Pendentes · Erros · Situação · Enviada em · Abrir; filtro Situação (em processamento / com pendências / com erros) | nome, arquivos, pendentes, erros, data | pendentes e erros em cor só quando > 0 |
| Pendências | Arquivo (+ princípio ativo, andamento da reanálise) · Importação · Origem · Enviado em · Situação · Reanalisar/Revisar | — (mais recentes primeiro; fragmento com `wire:poll` e `bulai-realtime` mantido) | faixa de andamento só quando há reanálise ativa ou parada |
| Remédios não encontrados | Nome · Pacientes · Posologias de exemplo · Desde · Situação/Resultado · Ligar ao catálogo/Descartar; painel "Ligar" abre como linha expandida com a busca do catálogo em tabela (Medicamento · Princípio ativo · Detentor · Catálogo · Ligar) e "Você quis dizer…?" quando a busca por prefixo não acha nada | — | fluxo `abrir`/`ligar`/`descartar` inalterado |
| Origens | Origem · Tipo · País · Documentos · Importações · Situação · Ativar/Desativar · Abrir | nome, país, documentos, importações | detalhe: `facts` (documentos, importações, tipo, endereço) + documentos recentes em tabela com "Ver todos" |
| Catálogo ANVISA / Interações | resumo em `kpi` → importação em andamento (fragmento com `wire:poll`) → "Importar" recolhido (`ops-disclosure`, área de arquivo compacta) → histórico em tabela (Arquivo · Data · Lidas · Novas · Atualizadas · Rejeitadas · Por · Situação) | — | rejeitadas em âmbar só quando > 0 |
| Importação (detalhe) | `page-header` → andamento (fragmento próprio) → tabela Arquivo · Documento identificado · Resultado · Situação · Revisar/Abrir/Detalhes | — | uma ação primária contextual (Revisar pendências / Ver documentos) |
| Documento (detalhe) | `page-header` → `facts` em uma linha (trechos, índice, PDF, processado em, idioma, princípios ativos) → `tabs` Trechos (N) · Arquivo · Processamento; trechos em cartões compactos com `max-h-96 overflow-auto` | — | blocos técnicos só nas abas |
| Revisão de pendência | estrutura anterior mantida; abas pelo componente `tabs` (contagem de divergências como selo) | — | — |
| Adicionar documentos / Cadastrar sem PDF / Origem (formulário) | formulário em uma coluna `max-w-2xl`; área de arrastar com ~128 px e instruções em duas linhas curtas; arquivos selecionados em tabela (Arquivo · Tamanho · Remover) | — | botão com verbo e `loading-target`; erros ligados ao campo |

Regras que estas telas seguem (e que valem para as próximas): abaixo de `md` a tabela rola horizontalmente (o admin é desktop-first); células de texto longo usam `max-w-*` + `truncate` + `title`; números alinhados à direita com `tabular-nums`; a coluna de ações tem `class="w-px"` para não roubar largura; `wire:loading.class="opacity-60"` no bloco da tabela com `wire:target` nos filtros e na ordenação.

## Telas de operação, análises e pessoas (reformulação de 06/11/2026)

Objetivo: densidade profissional e clareza de "o que é, onde está e quando usar". Cada tela segue o mesmo esqueleto: título (shell) → `toolbar` (filtros em uma linha + contagem + ações discretas) → `data-table` (uma linha por item, cabeçalho ordenável, ações à direita com `row-actions`) → paginação. Detalhes ficam em `tabs` dentro de um `card`, com os painéis renderizados e só o ativo visível (`hidden`), para que buscas na página e testes vejam tudo.

| Tela | O que mudou | Checklist (princípios de UX §8) |
| --- | --- | --- |
| **Fila e serviços** (`/admin/knowledge/queue`) | 4 `kpi` (Na fila · Processando · Com falha · Resolvidos hoje) + serviços em selos na mesma faixa; itens da fila em `data-table` (Arquivo · Etapa · Andamento · Situação · Atualizado · Reenfileirar/Cancelar); "Reenfileirar parados" na `toolbar`; reinício de serviços em tabela dentro de `ops-disclosure`; `callout` vermelho quando não há processador. Polling de 5 s só no fragmento. | Ação primária contextual (reenfileirar parados); nenhum fato duplicado; sem blocos vazios (estado vazio em uma linha da tabela); confirmações pelo `confirm-modal`. |
| **Analytics** (`/admin/analytics`) | Navegação por abas (Visão geral · Consultas · Perguntas · Bulas humanizadas) comum às quatro telas; período na `toolbar`; 4 `kpi`; "Qualidade das respostas" com três `meter` lado a lado; distribuições em duas colunas; Desempenho e consumo de IA em `data-table` densas; série diária recolhida. | Cabeçalho → resumo decisório (KPIs + ancoragem) → detalhe; polling nenhum; sem jargão exposto nas legendas (p50/p95 explicados). |
| **Consultas / Perguntas / Bulas** (listas) | `toolbar` com um seletor e contagem; `data-table` com colunas fixas (quando, usuário anonimizado, pares/evidências, duração, situação) e ação "Abrir". | Uma linha por item, um selo, uma ação. |
| **Pergunta / Bula humanizada** (detalhe) | `page-header` com selo e metadados; pergunta e resposta lado a lado; abas Contexto · Geração · Evidências (propriedade `tab`, na URL como `aba`). | Três níveis; detalhe sob demanda. |
| **Relatórios** | Tipos em `data-table` (Relatório · O que contém · Formatos · Período · Gerar); a linha selecionada expande o formulário (formato, período); relatórios gerados em `data-table` (Gerado em · Período · Por · Linhas · Tamanho · Situação · Baixar/Apagar). | Uma ação por linha; polling de 3 s só enquanto há geração. |
| **Usuários** (lista) | `toolbar` (busca, situação, perfil, contagem, Limpar); `data-table` ordenável pelos cabeçalhos (`sortBy`: nome, e-mail, perfil, último acesso, cadastro; segundo clique inverte, `sentido` na URL); `callout` quando só há um administrador. | Filtros em uma linha; uma linha por pessoa, um selo (Ativo / Inativo / Troca pendente), ações Abrir e Desativar. |
| **Usuário** (detalhe) | `page-header` (selo + e-mail · perfil · último acesso); abas Dados (fatos + uso do aplicativo) · Acesso (senha provisória, exigir troca, encerrar sessões, desativar/reativar em tabela) · Histórico (tabela Evento · Detalhe · Quando · Por quem). | Uma ação primária (Editar); ações de risco com confirmação e motivo; histórico paginado. |
| **Usuário** (formulário) | Uma coluna, `max-w-2xl`. | Rótulos visíveis; erro ligado ao campo. |

Convenções adotadas nestas telas: números em células com a classe `tabular`; texto longo em célula com `!whitespace-normal`; estado vazio sempre pelo slot `empty` da `data-table`; botões de linha `variant="quiet" size="sm"`; a aba ativa vem da propriedade `tab` validada em `updatedTab()` (valores fora da lista voltam para a primeira aba).
