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

Toda view em `resources/views/livewire/admin/**` começa com `<x-knowledge-admin.shell>`, que fornece cabeçalho, menu, usuário/Sair, título (`h1`), subtítulo, link de voltar e slot `actions`.

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

Antes de criar HTML novo, procure o componente. Se faltar um, crie-o **na pasta de componentes** e documente aqui; não estilize na view.

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

`AdminDesignSystemTest` verifica: uso do `shell`; ausência de `<button>/<select>/<textarea>/<input>` crus nas telas; paleta, escala de texto, cantos e sombras; ausência de CSS inline; vocabulário proibido (visível em views e strings de `app/Livewire`); sufixo `· BULAI` nos títulos. Rode: `php artisan test --compact tests/Feature/Admin/AdminDesignSystemTest.php`.

Exceções permitidas e conscientes: `<input type="file">` e `type="checkbox"`, e o cartão selecionável de documento da revisão (`selectDocument`).

## Arquivos tocados nesta padronização

- `resources/css/app.css` (tokens e classes `ui-control`, `ui-table`)
- `resources/views/components/knowledge-admin/*`, `resources/views/livewire/admin/**`, `resources/views/auth/login.blade.php`
- `resources/views/vendor/livewire/tailwind.blade.php` (paginação em pt-BR)
- `lang/pt_BR/*`, `config/app.php`, `.env.example` (locale `pt_BR`)
- `app/Models/Knowledge/KnowledgeDocument.php` (`documentTypeOptions()`, `documentTypeLabel()`)

## Limitações conhecidas

- O manifesto PWA e o README ainda usam "BULA-IA".
- Não há modo escuro nem biblioteca de ícones.
- A verificação visual é manual (nenhum teste de navegador).
