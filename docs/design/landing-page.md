# Landing page pública (`/`)

Página de apresentação do BULA-IA para visitantes, com acabamento comercial. Servida pela rota raiz (`routes/web.php`) quando não há sessão; usuários autenticados são redirecionados para a sua tela inicial (`User::homeRouteName()`). Criada em 05/11/2026 (sexta onda).

## Arquivos

| Arquivo | Papel |
| --- | --- |
| `resources/views/welcome.blade.php` | Monta a página: `<head>` com SEO, hero, faixa de confiança, seções e CTA final. Define os textos, as âncoras (`$links`) e o JSON-LD. |
| `resources/views/components/landing/header.blade.php` | Cabeçalho fixo (`sticky`) com logotipo, âncoras no desktop, botão Entrar/Abrir e menu do celular em `<details>` (sem JavaScript). |
| `resources/views/components/landing/phone-mockup.blade.php` | Celular desenhado em HTML/CSS mostrando "Remédios de hoje" (decorativo, `aria-hidden`). Sem captura de tela: não desatualiza quando a interface muda. |
| `resources/views/components/landing/section.blade.php` | Seção padrão: `id`, `eyebrow`, título (`h2` ligado por `aria-labelledby`), texto de apoio e tom (`white`, `surface`, `brand`). |
| `resources/views/components/landing/feature-card.blade.php`, `step.blade.php`, `faq-item.blade.php` | Cartão de recurso, passo numerado (`<li>`) e pergunta frequente (`<details>/<summary>`). |
| `resources/views/components/landing/footer.blade.php` | Rodapé com logotipo, navegação, acesso e a nota "Projeto acadêmico — TCC". |
| `resources/views/components/landing/icon.blade.php` | Ícones de linha (Heroicons outline, MIT) inline por nome. Sem fontes de ícones nem imagens externas. |
| `tests/Feature/LandingPageTest.php` | Testes (abaixo). |

## Estrutura da página (ordem)

1. **Hero** (`#inicio`): selo "Baseado nas bulas oficiais da ANVISA", título "Entenda seus remédios. Em linguagem simples.", proposta, CTA primário (Entrar no aplicativo → `route('login')`; "Abrir o aplicativo" quando autenticado) e secundário (Ver como funciona → `#como-funciona`), três destaques e o mockup do celular.
2. **Faixa de confiança**: Catálogo ANVISA · Interações DDInter · Respostas conferidas contra a bula · Dados de saúde com consentimento.
3. **Como funciona** (`#como-funciona`): três passos (fotografar a receita → conferir os horários → lembretes e dúvidas).
4. **Recursos** (`#recursos`): seis cartões (bula humanizada, remédios de hoje, lembretes, foto da receita, perguntar por texto ou voz com tolerância a erros de digitação, interações e alergias).
5. **Para quem** (`#para-quem`): para você · para quem cuida · para profissionais.
6. **Segurança e limites** (`#seguranca`, fundo `brand`): o aviso único do produto (`config('bulai.guidelines.disclaimer')`), a frase "“Nenhuma interação encontrada” **não** significa que a combinação seja segura", a conferência das respostas contra a bula e os cuidados com dados (consentimento, Argon2id, exclusão, nada identificável vai ao provedor de IA). O título "Limites desta ferramenta" é verificado por `ConsultaPwaTest`.
7. **Instale no celular** (`#instalar`): passo a passo para Android (Chrome: "Instalar aplicativo" / "Adicionar à tela inicial") e iPhone (Safari: Compartilhar → "Adicionar à Tela de Início").
8. **Perguntas frequentes** (`#perguntas`): seis itens em `<details>`.
9. **CTA final** e **rodapé**.

## Decisões

- **Sem dependências novas e sem recursos externos**: nenhuma fonte, imagem ou biblioteca de fora; ícones e o celular são HTML/CSS/SVG inline. Alpine não está carregado nesta página (não há `@livewireScripts`), por isso menu e FAQ usam `<details>`.
- **Tokens da identidade** (`brand`, `accent`, `surface`) e só classes Tailwind existentes; nada foi acrescentado a `resources/css/app.css`.
- **Tom e vocabulário**: português simples, sem promessa clínica, sem jargão técnico ("chunk", "embedding", "prompt", "LLM", "OCR" nunca aparecem). O texto de limites usa a diretriz única do produto (`docs/diretrizes-do-produto.md`).
- **Acessibilidade**: um único `h1`, `h2` por seção ligado por `aria-labelledby`, `nav` com `aria-label`, link "Ir para o conteúdo", foco visível (`focus-visible:ring`), alvos de toque ≥ 44 px, `prefers-reduced-motion` respeitado (`motion-reduce:*`, rolagem suave desligada).
- **Mobile-first**: grades `sm:grid-cols-2 lg:grid-cols-3`, `min-w-0` nos filhos de flex com texto, `max-w-full` nas imagens, `overflow-x-clip` no `body`; CTAs em coluna no celular.
- **SEO**: `<title>`, `meta description`, `canonical` (`url('/')`), Open Graph e Twitter Card (imagem: o ícone 512 px já existente), JSON-LD `SoftwareApplication`.

## Testes

`tests/Feature/LandingPageTest.php` (3 testes): página abre para visitante com metadados de SEO, seções/âncoras presentes, CTAs para o login, um `h1`, seis FAQs e JSON-LD válido; diretrizes do produto presentes, nenhum jargão nem segredo (chave da Anthropic, `APP_KEY`) no HTML e nenhuma promessa de "combinação segura"; usuário autenticado é redirecionado. `ConsultaPwaTest::test_guests_see_the_landing_and_protected_pages_require_login` continua passando.

## Limitações e próximos passos

- A imagem de compartilhamento (`og:image`) é o ícone 512×512; uma imagem 1200×630 em PNG com o mockup daria uma prévia melhor em redes sociais (exige gerar o PNG).
- O mockup é ilustrativo: se a tela "Remédios de hoje" mudar muito, ajuste `phone-mockup.blade.php`.
- O rodapé aponta "Sobre o BULA-IA e seus limites" para `#seguranca`, porque a página `/sobre` exige login.
- Não houve teste com pessoas idosas nem medição de desempenho em aparelho real; a página não carrega nada externo, então o peso é o do CSS/JS já existentes.

## Como editar

Textos e âncoras ficam em `welcome.blade.php` (`$links`, `$descricao`, seções). Para um novo recurso, acrescente um `<x-landing.feature-card icon="…" title="…">`; para um novo ícone, adicione o caminho SVG em `icon.blade.php`. Para uma nova pergunta frequente, acrescente `<x-landing.faq-item question="…">` e atualize a contagem (6) em `LandingPageTest`.
