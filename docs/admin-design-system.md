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

Toda view em `resources/views/livewire/admin/**` começa com `<x-knowledge-admin.shell>`, que fornece a barra lateral, o cabeçalho, o menu da conta, o título (`h1`), o subtítulo, o link de voltar, o slot `actions` e o alerta `accountFeedback` (mensagem de sucesso da conta, por exemplo ao concluir o primeiro acesso).

**Shell (redesenho de 05/11/2026).** Barra lateral fixa à esquerda em telas grandes (`lg`), na cor da marca (`bg-brand-800`), com o menu em **grupos** e o item ativo marcado em laranja (`accent-400`). Ela recolhe para só ícones pelo botão "Recolher menu" (preferência guardada em `localStorage`, chave `bulai-admin-collapsed`). Em telas menores vira uma gaveta aberta pelo botão "Abrir menu" do cabeçalho, com foco preso (`x-trap.noscroll`) e fechamento por Esc, toque fora ou navegação. O cabeçalho fino mostra o título da tela, a saúde dos serviços (`livewire:admin.knowledge.service-health`, só em `md+`) e o avatar com o menu da conta (App do paciente, Minha conta, Sair). Partes de tela com polling próprio (ex.: o bloco "Operação" da Visão geral) são componentes Livewire com view em `resources/views/livewire/shared/`, fora da fiscalização do `shell`.

Menu em grupos (ordem do fluxo de trabalho): **Base de conhecimento** (Visão geral → Adicionar documentos → Importações → Pendências → Documentos → Origens → Catálogo ANVISA → Interações), **Operação** (Fila e serviços), **Análises** (Analytics, Relatórios), **Pessoas** (Usuários). Cada item tem ícone e dica. Rota, rótulo, padrão de rota ativa, ícone e dica ficam em **`app/AdminNavigation.php`**: `AdminNavigation::groups()` alimenta a barra lateral e `AdminNavigation::items()` (lista plana) o resumo de áreas do primeiro acesso. Para mudar o menu, altere só esse arquivo (a contagem de pendências continua no `shell`).

Páginas fora do admin (`auth/login.blade.php`, `auth/account.blade.php` e `auth/first-access.blade.php`) não usam o `shell`: são páginas autônomas com os mesmos componentes e tokens, e também são fiscalizadas pelo teste. O primeiro acesso mostra ao ADMIN o resumo das áreas a partir de `AdminNavigation::items()` (ícone, rótulo e dica), sem links, porque a navegação só libera depois da troca de senha.

```blade
<x-knowledge-admin.shell title="Importações" subtitle="Histórico dos envios." :back="route('...')" back-label="Início">
    <x-slot:actions><x-knowledge-admin.button :href="route('...')">Adicionar documentos</x-knowledge-admin.button></x-slot:actions>
    ...
</x-knowledge-admin.shell>
```

Ordem do conteúdo: alertas → filtros/busca → conteúdo principal → paginação (`{{ $items->links() }}`).
Título da aba (`->title()` no componente Livewire): `Nome da tela · BULAI`.

## Componentes (`resources/views/components/knowledge-admin`)

| Componente | Uso |
|---|---|
| `shell` | Estrutura da página (obrigatório) |
| `button` | Ação ou link (`variant`: primary, secondary, danger, quiet; `href`; `loading-target` + `loading` para estado de carregamento) |
| `card`, `section` | Blocos de conteúdo (`section` = card com título/descrição/`actions`) |
| `input`, `select`, `textarea` | Campos com rótulo, obrigatório/opcional, dica e erro automático (`wire:model`) |
| `search` | Campo de busca de listas |
| `table` | Tabela (`:columns`) para dados comparáveis; cartões para itens com ações |
| `stat` | Indicador numérico clicável (grande) |
| `kpi` | Indicador compacto da Visão geral: rótulo, valor, complemento, `tone` (default, success, warning, danger, brand) e `href` opcional |
| `sidebar-nav`, `sidebar-link` | Menu lateral em grupos e seu item (ícone, rótulo, marcador ativo, selo de pendências); recebem a expressão Alpine `collapsed` |
| `facts` / `fact` | Pares rótulo → valor |
| `badge`, `status-badge` | Rótulos; `status-badge` traduz os status do sistema |
| `document-processing-states` | Situação de PDF, texto e índice de busca (`compact` em coluna; `inline` = três selos curtos em uma linha, para listas densas) |
| `alert` | Mensagens (`success`, `warning`, `danger`, `info`) |
| `empty-state` | Lista vazia |
| `modal` | Diálogos |
| `temporary-password-modal` | Exibição única de senha provisória (botão Copiar, aviso de que não será mostrada novamente); usado na criação de usuário e em "Gerar nova senha provisória" |
| `confirm-modal` | Confirmação antes de uma ação de risco, no visual do `modal` (ver "Confirmações" abaixo) |
| `page-header` | Faixa de decisão abaixo do título: `badge`/`badge-tone`, `meta` (até dois metadados), slot `primary` (UMA ação) e slot `more` (secundárias no menu "Mais", com `menu-item`) |
| `menu-item` | Item do menu "Mais": link (`href`, `target`, `download`) ou ação (`wire:click`/`x-on:click`); `tone="danger"` para risco |
| `toast` | Aviso breve (4 s) incluído pelo shell; as telas disparam `$this->dispatch('notify', message: '…', tone: 'success|info|warning|danger')` |

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

`AdminDesignSystemTest` verifica: uso do `shell`; ausência de `<button>/<select>/<textarea>/<input>` crus nas telas; paleta, escala de texto, cantos e sombras; ausência de CSS inline; vocabulário proibido (visível em views e strings de `app/Livewire` e `app/AdminNavigation.php`); sufixo `· BULAI` nos títulos; ausência de `wire:confirm` em `resources/views` (confirmação usa `confirm-modal`). Rode: `php artisan test --compact tests/Feature/Admin/AdminDesignSystemTest.php`.

Exceções permitidas e conscientes: `<input type="file">` e `type="checkbox"`, e o cartão selecionável de documento da revisão (`selectDocument`).

## Arquivos tocados nesta padronização

- `resources/css/app.css` (tokens e classes `ui-control`, `ui-table`)
- `resources/views/components/knowledge-admin/*`, `resources/views/livewire/admin/**`, `resources/views/auth/login.blade.php`, `resources/views/auth/account.blade.php`, `resources/views/auth/first-access.blade.php`
- `app/AdminNavigation.php` (itens e ícones do menu)
- `resources/views/vendor/livewire/tailwind.blade.php` (paginação em pt-BR)
- `lang/pt_BR/*`, `config/app.php`, `.env.example` (locale `pt_BR`)
- `app/Models/Knowledge/KnowledgeDocument.php` (`documentTypeOptions()`, `documentTypeLabel()`)

## Visão geral e Relatórios

- **Visão geral** (`Livewire\Admin\Knowledge\Dashboard` + `App\Services\Admin\OverviewService`): "Precisa de atenção" (só itens com contagem > 0, cada um com link), "Operação" (componente `admin.knowledge.operation-panel`, `wire:poll.30s` + evento `bulai-realtime`), "Base de conhecimento", "Uso do aplicativo" (7 dias e total), "Inteligência artificial" (mês corrente) e "Pessoas". Os contadores pesados ficam em cache por 60 s (`admin-overview:*`).
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
