# Identidade visual do BULA-IA

Guia único para a administração e o app do paciente. Os valores vivem em `resources/css/app.css` (`@theme`); as telas usam só classes Tailwind com esses tokens e os componentes Blade de `resources/views/components` (`knowledge-admin/*` e `consulta/*`). Nenhuma cor é escrita diretamente nas views, exceto na tela `offline` (sem CSS compilado) e nos metadados (`theme-color`, manifesto).

## 1. Paleta

Derivada do logotipo (`public/brand`): azul-marinho `#12344D`, laranja `#D9661F`, creme `#F7F4EE`.

| Token | Valor | Uso |
| --- | --- | --- |
| `brand-700` | `#12344D` | Cor da marca. Botão primário, texto de títulos de destaque, item ativo da navegação, faixas de aviso do sistema. |
| `brand-800` / `brand-900` | `#0E2A3F` / `#091D2C` | Hover do primário; títulos sobre fundo claro. |
| `brand-600` | `#1F4A66` | Anel de foco (`ring-brand-600`), bordas de ênfase. |
| `brand-50` / `brand-100` | `#EEF3F7` / `#D8E3EC` | Chips, fundo do item ativo, bordas do cabeçalho e da barra inferior. |
| `accent-600` | `#B8521A` | **Ação principal da tela** (Perguntar, Fotografar receita, Entrar) com texto branco. |
| `accent-700` | `#944114` | Hover da ação principal. |
| `accent-500` | `#D9661F` | Marcadores decorativos (traço do item ativo, pontos). Nunca com texto branco por cima (contraste 3,58:1). |
| `accent-50` / `accent-700` | `#FDF3EC` / `#944114` | Selo de atenção não clínica (ex.: "confira"). |
| `surface-50` / `surface-100` | `#FBFAF7` / `#F7F4EE` | Fundo das páginas; fundo de cartões de destaque. Cartões de conteúdo continuam brancos. |
| `slate-*` | Tailwind | Texto corrente (`slate-900`, `slate-700`, `slate-600`), bordes neutras (`slate-200/300`), painéis informativos neutros (`slate-50`). |

**Gravidade clínica** não usa a paleta da marca: maior `rose-600`, moderada `amber-500`, menor `yellow-300`, não classificada `slate-400` (`x-consulta.severity`), sempre com o rótulo em texto. Alertas de alergia: `rose-50/900`. A cor nunca é o único portador da informação.

Regra: **uma** ação em `accent` por tela; o resto em `brand` (primário) ou secundário (branco com borda).

## 2. Tipografia

Fonte do sistema (`--font-sans`): sem download, melhor desempenho e leitura nativa em cada aparelho.

| Papel | Classes |
| --- | --- |
| Título da tela (cabeçalho) | `text-lg sm:text-xl font-semibold text-slate-900` |
| Título de seção | `text-sm font-semibold text-slate-800` (app) · `text-2xl font-semibold` (admin) |
| Corpo | `text-sm leading-relaxed text-slate-800` |
| Apoio / metadados | `text-xs text-slate-500/600` |
| Números em destaque | `text-2xl font-semibold tabular-nums` |

## 3. Espaçamento, raios e elevação

- Grade vertical em `gap-4` (telas) e `gap-2/3` (dentro dos cartões); nunca margens entre irmãos.
- Raios: `rounded-lg` (controles, botões, chips pequenos), `rounded-xl` (listas, painéis), `rounded-2xl` (cartões). `rounded-md` não é usado.
- Elevação: `shadow-sm` em cartões; `ring-1 ring-slate-200` para listas internas; sem sombras fortes.
- Largura máxima: app `max-w-3xl`; admin `max-w-7xl`.

## 4. Ícones

Contorno 24×24 (`viewBox="0 0 24 24"`, `stroke="currentColor"`, `stroke-width="1.7"`, `fill="none"`), estilo Heroicons outline, sempre `aria-hidden="true"` acompanhados de texto. Tamanhos `size-5` (inline) e `size-6` (navegação).

## 5. Componentes

| Componente | Classes-chave |
| --- | --- |
| Botão primário (`x-knowledge-admin.button`) | `bg-brand-700 text-white hover:bg-brand-800`, `min-h-11`/`min-h-12` no app |
| Botão de destaque (`variant="accent"`) | `bg-accent-600 text-white hover:bg-accent-700` |
| Botão secundário (`variant="secondary"`) | `border-slate-300 bg-white text-slate-800 hover:bg-slate-50` |
| Botão de risco (`variant="danger"`) | `bg-rose-700 text-white` |
| Cartão | `rounded-2xl border border-slate-200 bg-white p-4 shadow-sm` |
| Chip de medicamento | `rounded-full bg-brand-50 px-3 py-1 text-sm font-medium text-brand-800 ring-1 ring-inset ring-brand-600/20` |
| Selo de gravidade | `x-consulta.severity` (quadrado colorido + rótulo) |
| Barra inferior (app) | 4 itens, `min-h-16`, item ativo `text-brand-700` com cápsula `bg-brand-50` e traço superior `accent-500` |
| Cabeçalho (app) | símbolo da marca ou Voltar, título, avatar `bg-brand-50 text-brand-800` |
| Controle de formulário | `.ui-control` (foco `border-brand-600` + `ring-brand-600/20`) |

## 6. Estados

- Foco visível: `focus-visible:ring-2 focus-visible:ring-brand-600` em tudo que é clicável.
- Carregando: `wire:loading` com texto ("Procurando nas bulas…") e, em esperas longas, painel `bg-brand-50 text-brand-800` com `role="status" aria-live="polite"`.
- Vazio: cartão tracejado `border-dashed border-slate-300` com uma ação.
- Erro/aviso: `rose-50/900` (erro, alergia), `amber-50/900` (atenção), ambos com `role="alert"`.
- Desabilitado: `disabled:opacity-50`.

## 7. Acessibilidade — contraste calculado (WCAG 2.1, luminância relativa)

| Par (texto / fundo) | Razão | AA texto normal (≥ 4,5) |
| --- | --- | --- |
| branco / `brand-700` | 12,93 | sim |
| branco / `brand-800` | 14,77 | sim |
| branco / `brand-600` | 9,42 | sim |
| branco / `accent-600` | 4,93 | sim |
| branco / `accent-700` | 6,94 | sim |
| branco / `accent-500` | 3,58 | **não** (só decorativo) |
| `brand-800` / `brand-50` | 13,22 | sim |
| `brand-700` / branco | 12,93 | sim |
| `brand-700` / `surface-50` | 12,39 | sim |
| `accent-700` / `accent-50` | 6,35 | sim |
| `slate-600` / `surface-50` | 7,26 | sim |
| `slate-900` / `surface-50` | 17,10 | sim |
| `slate-500` / branco | 4,76 | sim (limite; só em texto de apoio) |
| `rose-900` / `rose-50` | 8,71 | sim |
| `amber-900` / `amber-50` | 8,75 | sim |
| branco / `rose-700` | 6,29 | sim |

Alvos de toque ≥ 44 px (`min-h-11`), rótulos sempre presentes (`aria-label` quando só ícone), `aria-current="page"` na navegação, `role="tablist"` nos segmentos.

## 8. Logotipo

Arquivos em `public/brand`: `bulai-logo-horizontal.svg` (fundo creme), `bulai-logo-horizontal-transparente.svg` (uso padrão sobre fundo claro), `bulai-logo-vertical-escuro.svg` (fundo escuro), `bulai-simbolo.svg` e `bulai-icone-app.svg` (símbolo; ícones PNG 180/192/512 derivados, com fundo `brand-700`). Área de proteção: metade da altura do símbolo em todos os lados. Altura mínima: 32 px (símbolo) e 48 px (horizontal). Não recolorir, não distorcer, não aplicar sombra. Sobre fundo escuro use a versão vertical escura ou o símbolo.

## 9. Faça / não faça

- Faça: uma ação laranja por tela; títulos em azul-marinho; fundos creme com cartões brancos.
- Não faça: laranja `accent-500` com texto branco; usar azul/laranja para indicar gravidade; mais de uma ação de destaque na mesma tela; cores sem texto; fontes externas.

## 12. E-mails (07/11/2026)

Os e-mails transacionais seguem a mesma identidade, em vez do modelo padrão do Laravel (preto e branco, em inglês, com "Laravel" e "All rights reserved").

| Elemento | Regra |
| --- | --- |
| Tema | `resources/views/vendor/mail/html/themes/bulai.css` (selecionado em `config/mail.php`, `markdown.theme`, ou `MAIL_MARKDOWN_THEME`). Fundo creme `#ECE6DA`, cartão branco de cantos arredondados, texto `#334155` de 16 px, títulos `#12344D`. |
| Cabeçalho | Faixa azul-marinho `#12344D` com filete laranja `#D9661F`, o símbolo (`public/brand/bulai-icone-192.png`, **PNG com endereço absoluto** a partir de `APP_URL`: e-mail não aceita SVG) e o nome "BULA-IA" com o lema "Bulas inteligentes" em texto, que continua legível quando o cliente bloqueia imagens. |
| Botão | Laranja `#B8521A` (texto branco, contraste AA), cantos arredondados, texto de 17 px em negrito. Verde e vinho para os níveis `success`/`error`. |
| Painel | Fundo creme `#F7F4EE` com barra lateral azul-marinho; usado para a senha provisória (monoespaçada) e para o horário do remédio. |
| Rodapé | Nome e lema, o aviso do produto (`bulai.guidelines.disclaimer`: não substitui médico ou farmacêutico) e "Você recebeu esta mensagem porque tem uma conta no BULA-IA" com o caminho de volta ao aplicativo. |
| Idioma | Tudo em português: saudação "Olá!", assinatura "Equipe BULA-IA", texto do link alternativo do botão ("Se o botão “…” não funcionar, copie e cole o endereço abaixo no navegador"). |
| Texto simples | A versão `text/` tem o mesmo cabeçalho e rodapé. A API da KingHost envia só o corpo HTML; a versão de texto vale para outros transportes. |

Layouts dedicados: `resources/views/mail/senha-provisoria.blade.php` (senha em painel, três passos) e `resources/views/mail/lembrete-de-dose.blade.php` (horário e remédio em painel, um botão). Redefinição de senha e fim do tratamento usam `vendor/notifications/email.blade.php` com o tema.

Exemplos renderizados (abra no navegador; os links de ação apontam para o ambiente de desenvolvimento): `docs/design/emails/*.html`. Teste: `tests/Feature/Infrastructure/EmailIdentidadeTest.php`.

**Limitações.** Clientes de e-mail renderizam CSS de forma diferente (Outlook ignora cantos arredondados e sombras; modo escuro pode inverter cores); o desenho foi feito para degradar sem perder leitura. Não foi conferido em clientes reais: enviar um e-mail de teste para Gmail e para o celular depois do deploy. O símbolo só aparece se `https://<APP_URL>/brand/bulai-icone-192.png` for público.
