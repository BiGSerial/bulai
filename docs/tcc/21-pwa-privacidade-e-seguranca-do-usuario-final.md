# PWA do usuário final: usabilidade, privacidade e segurança

Mapeamento no docx: Cap. 3, 3.3.2 etapa 4, 3.4 Fase 4, 3.5.2 e 3.5.3; Cap. 2, 2.7.2. Documento técnico: `docs/consulta-pwa.md`.

## Objetivo do tópico

Descrever a interface construída, as decisões de segurança e privacidade e o que ainda **não** foi verificado.

## 1. Interface

Mobile-first, instalável (manifesto, *service worker*), separada do Admin. Telas: página inicial pública com limites da ferramenta; pesquisa (autocompletar por nome comercial ou princípio ativo, desambiguação por titular, aviso quando o princípio ativo não foi identificado, mensagem para medicamento fora do catálogo); lista de 2 a 6 itens sem duplicatas; resultado por par com veredito, gravidade (cor **sempre acompanhada de texto**), explicação validada, princípios ativos, evidências expansíveis (documento, seção, páginas, fonte, versão, trecho, score, quais afirmações sustenta), limitações e fonte estruturada; histórico com exclusão. Estados de processamento, falha, tempo esgotado e "tentar novamente". Os estados inconclusivos têm tratamento visual e textual distinto de "documentada" para o usuário não confundir ausência de evidência com segurança.

Referências visuais usadas: dois exemplos de aplicativos de interações (cartões por par, gravidade em quadrado colorido, chips de medicamentos, detalhamento da interação). Elementos que **não** foram adotados: o selo "seguro para tomar junto" e a recomendação de conduta (evitar, substituir, monitorar INR, ajustar dose), pois contrariam a regra de que o sistema não recomenda conduta e de que ausência de interação não prova segurança.

## 2. Segurança

| Risco | Controle | Evidência |
| --- | --- | --- |
| Acesso a consulta de outro usuário | busca pela chave `uuid` + verificação de dono (404) | `test_results_are_private_to_their_owner` |
| Exclusão de dados alheios | exclusão sempre filtrada por `user_id` | `test_user_can_delete_one_or_all_consultations...` |
| Abuso/custos | limite por usuário: 30 consultas/h, 120 buscas/min, trava anti-duplicidade | testes de rate limit |
| Injeção de instruções via bula | trechos delimitados, `<`/`>` neutralizados, saída validada | `test_leaflet_text_is_sent_as_delimited_untrusted_data` |
| Segredos no navegador | o frontend só fala com Laravel; chaves ficam em `config/ai.php`/`.env` | `test_pages_never_expose_provider_credentials_or_keys` |
| Cache de dados sensíveis | páginas nunca em cache do service worker; só estáticos públicos e `/offline` | `PwaAssetTest` |
| Vazamento em logs | logs só com ids/contagens/classe de exceção; `ai_usage_logs` sem prompt/resposta | testes do provedor |
| Isolamento Admin × usuário | middleware `admin` mantido; `/consulta*` exige conta ativa e senha trocada | testes de autenticação |

## 3. Privacidade (LGPD)

A lista de medicamentos de uma pessoa é **dado de saúde** (dado pessoal sensível). Mitigações implementadas: coleta mínima (lista + resultado, sem texto livre, sem prompts), vínculo apenas ao usuário autenticado, retenção de 90 dias com expurgo diário, exclusão individual/total pelo usuário, trilha de auditoria da exclusão (só contagem). **Pendências institucionais**: base legal/consentimento explícito, política de privacidade, encarregado (DPO), e avaliação do envio de nomes de medicamentos a provedores externos (a mensagem ao provedor não contém identificação do usuário, mas contém a combinação de medicamentos). [referência a incluir: Lei 13.709/2018 (LGPD), arts. sobre dados sensíveis e finalidade.]

## 4. O que não foi verificado

Inspeção visual em dispositivos reais; auditoria de acessibilidade (contraste, leitor de tela); instalação em iOS (ícones só SVG); teste com usuários (SUS). Os testes automatizados verificam renderização, regras e isolamento, não a experiência.
