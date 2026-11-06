# Princípios de UX do BULA-IA (contrato aplicado a todas as telas)

Complementa `docs/design/identidade-visual.md` (cores, tipografia, componentes) e `docs/admin-design-system.md` (componentes do admin). Toda tela, administrativa ou do paciente, deve passar no checklist do fim deste documento antes de ser considerada pronta.

## 1. Uma tela, uma tarefa

- Cada tela responde a uma pergunta do usuário ("o que preciso decidir aqui?") e tem **uma ação primária**, visível sem rolar: no desktop à direita do título, no celular fixa acima da barra inferior.
- Ações secundárias ficam em um menu "Mais" ou abaixo da primária, nunca lado a lado com o mesmo peso.
- Nada aparece duas vezes. Um fato (nome do medicamento, registro, status) é mostrado uma única vez, no lugar onde a decisão é tomada. Detalhes vão para seções recolhidas (`details`/abas), não para blocos repetidos.

## 2. Hierarquia em três níveis

1. **Cabeçalho**: título curto, um selo de situação e no máximo dois metadados (origem · data).
2. **Resumo decisório**: até quatro fatos essenciais em uma linha de "fatos" (rótulo pequeno + valor), seguidos da ação primária e de uma frase que explica a consequência.
3. **Detalhe sob demanda**: abas ou acordeões com o restante (análise da IA, texto extraído, histórico, arquivo). A primeira aba é a mais usada; nunca mais de quatro abas.

## 3. Listas

- Uma linha por item; título em negrito, uma linha de apoio em cinza, **um** selo de situação, uma ação de linha ("Abrir"). Sem três selos por linha.
- Filtros em uma linha acima da lista (busca + no máximo dois seletores), contagem de resultados e "Limpar".
- Abaixo de `md` a tabela vira cartões com os mesmos dados.
- Estado vazio com uma frase e uma ação. Paginação sempre que houver mais de 20 itens.

## 4. Formulários

- Uma coluna; campos agrupados por tema com título curto; rótulo sempre visível; ajuda em uma linha abaixo do campo; erro ligado ao campo (`aria-describedby`).
- Botão de envio com o verbo da ação e estado de carregamento (`wire:loading`); confirmação só para ações destrutivas (janela `confirm-modal`).

## 5. Reatividade perceptível e leve

- Toda ação mostra feedback em até 100 ms (`wire:loading`, texto do botão muda) e, ao terminar, um aviso breve (`x-knowledge-admin.toast`, 4 s, `role="status"`).
- Polling (`wire:poll`) só no fragmento que muda (fila, progresso), nunca na página inteira; use os eventos `bulai-realtime` já existentes.
- Sem bibliotecas novas; Alpine só para abrir/fechar, abas e menus. Evite consultas pesadas em `render()`: calcule contadores com cache e evite N+1.

## 6. Linguagem

- Português simples; verbos nos botões ("Criar documento", "Associar", "Descartar"); sem jargão exposto (em vez de "chunks", "trechos"; em vez de "OCR", "leitura do PDF").
- Mensagens de erro dizem o que aconteceu e o que fazer. Sem textos de rodapé repetindo o que o botão já diz.

## 7. Acessibilidade e toque

- Alvos ≥ 44 px, foco visível (`ring-brand-600`), cor sempre acompanhada de texto, `aria-current` na navegação, `aria-live` nos estados que mudam sozinhos.

## 8. Checklist por tela (preencher no relatório de cada frente)

| Critério | Pergunta de verificação |
| --- | --- |
| Ação primária única | Dá para dizer qual é o botão principal em 2 s? Está visível sem rolar? |
| Sem duplicação | Algum fato aparece duas vezes na tela? |
| Três níveis | Cabeçalho → resumo decisório → detalhe recolhido? |
| Lista enxuta | Uma linha por item, um selo, uma ação? |
| Feedback | Toda ação tem `wire:loading` e aviso ao concluir? |
| Polling restrito | `wire:poll` só no fragmento que muda? |
| Mobile | Funciona a 400 px sem rolagem horizontal, com CTA fixo? |
| Linguagem | Botões com verbo, sem jargão, erros com próximo passo? |
| Acessibilidade | Rótulos, foco, `aria-*`, contraste AA? |

## 9. Regras de layout mobile (fiscalizadas por `tests/Feature/Consulta/ConsultaDesignSystemTest.php`)

A tela do paciente é usada a 360–400 px de largura, muitas vezes instalada em tela cheia. Nada pode causar rolagem horizontal.

| Regra | Por quê | Como fazer |
| --- | --- | --- |
| Sem `style=` inline nas telas do paciente | o fiscal não enxerga larguras em CSS inline | classes do Tailwind; exceções em `resources/css/app.css` |
| `whitespace-nowrap` só com `truncate` ou `overflow-hidden` | um nome de remédio longo empurra a linha para fora | `truncate` + `min-w-0` no contêiner |
| `grid-cols-3` ou mais só com prefixo (`sm:`, `md:`) | 3+ colunas não cabem a 360 px | `grid-cols-1 sm:grid-cols-3`; seletor de 3 opções curtas: `data-mobile-ok="motivo"` |
| Largura fixa (`w-64+`, `w-[…]`, `min-w-[…]`) só com `max-w-…` | passa da tela | `w-64 max-w-[calc(100vw-1rem)]` |
| `text-[…]` acima de 2rem só em `h1`/`h2` | palavras grandes não quebram | escala do Tailwind (`text-2xl`, `text-3xl`) |
| `fixed inset-x-0` precisa de um filho `mx-auto max-w-…` | estica no tablet/desktop | ver barra inferior e CTA fixo do shell |
| `<table>` dentro de `overflow-x-auto` | tabelas não encolhem | ou cartões abaixo de `md` (seção 3) |
| Sem jargão (chunk, OCR, embedding, LLM, token, prompt) | linguagem do paciente | "trechos da bula", "leitura da foto", "resposta" |

Guardas globais (já aplicadas por `.app-screen` em `app.css`): `img/svg/video { max-width: 100% }`, `overflow-wrap: anywhere` em títulos, parágrafos e células, `min-width: 0` nos filhos diretos do conteúdo e `overflow-x: clip` na casca. Elas evitam o estouro, mas não substituem as regras acima: um grid de 3 colunas "cabe" cortado, não legível.

## 10. Densidade profissional do admin

A área administrativa é uma ferramenta de trabalho em desktop: densidade e previsibilidade valem mais que respiro. Regras (fiscalizadas por `AdminDesignSystemTest::test_views_are_dense_and_never_open_empty_areas`):

- **Nada de área vazia.** O conteúdo começa logo abaixo do título (`main` com `content-start`); nenhuma grade com `flex-1` sem `content-start`; nenhum bloco com `py-10` ou mais; estados vazios compactos (`empty-state`, uma frase e uma ação).
- **Listas são tabelas.** Acima de cinco itens use `data-table` (linhas de 48 px, `dense` 40 px, cabeçalho fixo, zebra sutil, ordenação por coluna, última coluna de ações à direita); cartões altos por item (`rounded-xl border py-3`) só com `data-list-ok` e um motivo. Abaixo de `md` a tabela rola na horizontal dentro do próprio componente.
- **Filtros em uma linha** (`toolbar` com `search`/`select` `compact`, contagem à direita); sem rótulos visíveis que dupliquem o placeholder.
- **Uma hierarquia de texto**: título 2xl, títulos de seção `text-sm font-semibold`, corpo `text-sm`, rótulos `text-xs uppercase`. Números em `tabular-nums`.
- **Onde estou e o que faço aqui**: trilha "Grupo › Tela" no cabeçalho, descrição curta em cada item do menu, botão "Ajuda desta tela" com *para que serve / quando usar / passo a passo / telas relacionadas* (`App\AdminHelp`), busca global com Ctrl/⌘+K.
- **Espaçamento fixo**: `gap-4` entre blocos de página, `gap-2` dentro de barras e grades de indicadores, `p-4` em cartões. Sem `shadow` além de `shadow-sm` (cartões) e `shadow-xl` (menus e painéis).
