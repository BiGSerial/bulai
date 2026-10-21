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

Toda view em `resources/views/livewire/admin/**` começa com `<x-knowledge-admin.shell>`, que fornece cabeçalho, menu, usuário/"Minha conta"/Sair, título (`h1`), subtítulo, link de voltar, slot `actions` e o alerta `accountFeedback` (mensagem de sucesso da conta, por exemplo ao concluir o primeiro acesso).

Menu (ordem do fluxo de trabalho): Visão geral → Adicionar documentos → Importações → Pendências → Documentos → Origens → Catálogo ANVISA → Usuários. Cada item tem ícone e dica ao passar o mouse. Rota, rótulo, padrão de rota ativa, ícone e dica ficam em **`app/AdminNavigation.php`** (`AdminNavigation::items()`), fonte única usada pelo `shell` e pelo resumo de áreas da tela de primeiro acesso: para mudar o menu, altere só esse arquivo (a contagem de pendências continua no `shell`). O link "Minha conta" (`/account`) fica no cabeçalho, ao lado do e-mail.

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
| `stat` | Indicador numérico clicável |
| `facts` / `fact` | Pares rótulo → valor |
| `badge`, `status-badge` | Rótulos; `status-badge` traduz os status do sistema |
| `document-processing-states` | Situação de PDF, texto e índice de busca |
| `alert` | Mensagens (`success`, `warning`, `danger`, `info`) |
| `empty-state` | Lista vazia |
| `modal` | Diálogos |
| `temporary-password-modal` | Exibição única de senha provisória (botão Copiar, aviso de que não será mostrada novamente); usado na criação de usuário e em "Gerar nova senha provisória" |
| `confirm-modal` | Confirmação antes de uma ação de risco, no visual do `modal` (ver "Confirmações" abaixo) |

Antes de criar HTML novo, procure o componente. Se faltar um, crie-o **na pasta de componentes** e documente aqui; não estilize na view.

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
- **Cores:** neutros `slate`; marca `brand` (alias de teal, definido em `@theme`); estados `emerald` (sucesso), `amber` (atenção), `rose` (erro/destrutivo), `sky` (informação). Nunca usar `teal-*` direto nem outras cores.
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

## Limitações conhecidas

- O manifesto PWA e o README ainda usam "BULA-IA".
- Não há modo escuro nem biblioteca de ícones.
- A verificação visual é manual (nenhum teste de navegador).
