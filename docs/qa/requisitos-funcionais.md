# Requisitos funcionais para testes de QA

Lista para o QA planejar e executar testes de aceitação. Cada item traz o comportamento esperado verificável e a rota. Fonte: `docs/` e rotas (`php artisan route:list`). Detalhes técnicos de cada módulo estão nos documentos citados.

**Perfis:** `VISITANTE` (sem login), `PACIENTE` (perfil `USER`, app mobile/PWA), `ADMIN` (módulo administrativo), `INTEGRAÇÃO` (API interna com token Bearer).

**Premissas de ambiente:** exige base com catálogo ANVISA, bulas processadas e interações DDInter; provedor de IA configurado (Anthropic) ou fake; `php artisan schedule:work` ativo para lembretes. Ver `docs/deployment/kinghost.md`.

## 1. Autenticação e conta (`docs/usuarios-e-autenticacao.md`)

| ID | Requisito | Critério de aceite | Rota |
| --- | --- | --- | --- |
| RF-AUT-01 | Login por e-mail e senha | Credenciais válidas entram; inválidas mostram erro genérico; conta inativa é recusada só depois de a senha conferir; "Manter conectado" funciona | `/login` |
| RF-AUT-02 | Redirecionamento por perfil | ADMIN vai para o admin; PACIENTE vai para a área do paciente; visitante em rota protegida vai ao login | `/` |
| RF-AUT-03 | Esqueci minha senha | Mensagem idêntica exista ou não o e-mail; conta inativa não recebe link; link é de uso único e expira | `/forgot-password`, `/reset-password/{token}` |
| RF-AUT-04 | Redefinição de senha | Define nova senha, encerra todas as sessões e remove a troca obrigatória | `/reset-password` |
| RF-AUT-05 | Primeiro acesso | Usuário com senha provisória é forçado a trocá-la antes de usar o sistema; boas-vindas explicam o sistema conforme o perfil | `/first-access` |
| RF-AUT-06 | Minha conta | Mostra dados e último acesso/IP; troca de senha pede a senha atual | `/account` |
| RF-AUT-07 | Encerrar outros dispositivos | Pede a senha atual e derruba as demais sessões | `/account/sessions/terminate` |
| RF-AUT-08 | Histórico de segurança da conta | Lista eventos, IP e autor de cada evento | `/account/history` |
| RF-AUT-09 | Logout | Invalida a sessão | `/logout` |
| RF-AUT-10 | Senha com Argon2id | Hash gravado é argon2id; hash bcrypt antigo entra e é regravado | (login) |

## 2. Gestão de usuários (ADMIN)

| ID | Requisito | Critério de aceite | Rota |
| --- | --- | --- | --- |
| RF-USR-01 | Listar e buscar usuários | Busca e paginação no servidor | `/admin/users` |
| RF-USR-02 | Cadastrar usuário com senha provisória | Cria a conta e exige troca no primeiro acesso | `/admin/users/create` |
| RF-USR-03 | Editar, ativar e desativar | Conta desativada não consegue entrar | `/admin/users/{user}/edit` |
| RF-USR-04 | Gerar nova senha provisória | Nova senha exige troca no próximo login | `/admin/users/{user}` |
| RF-USR-05 | Auditoria de acessos | Cada ação gera evento com autor e IP | `/admin/users/{user}` |
| RF-USR-06 | Controle de acesso | PACIENTE recebe 403/redirecionamento em qualquer `/admin/*` | `/admin/*` |

## 3. Pesquisa e consulta de interações (PACIENTE) (`docs/consulta-pwa.md`)

| ID | Requisito | Critério de aceite | Rota |
| --- | --- | --- | --- |
| RF-CON-01 | Buscar medicamento no catálogo | Busca com debounce; aceita nome com erro de digitação ("lozartana" acha LOSARTANA) e mostra "parecido com" | `/consulta` |
| RF-CON-02 | Montar lista de 2 a 6 medicamentos | Menos de 2 ou mais de 6 é bloqueado com mensagem | `/consulta` |
| RF-CON-03 | Executar consulta de interações | Cria consulta assíncrona e leva ao resultado; envio duplicado em 10 s é travado | `/consulta` |
| RF-CON-04 | Resultado por par | Mostra veredito, gravidade (só quando há base), trechos de bula (documento, seção, página, fonte), explicação e limitações | `/consulta/{uuid}` |
| RF-CON-05 | Estados do resultado | Exibe corretamente `INTERACTION_FOUND`, `NOT_FOUND_IN_SOURCES` (nunca como "combinação segura"), `INSUFFICIENT_EVIDENCE`, `MEDICATION_NOT_IDENTIFIED`, `CONTRADICTORY_EVIDENCE`, `TECHNICAL_ERROR` | `/consulta/{uuid}` |
| RF-CON-06 | Acompanhamento em andamento | Resultado atualiza sozinho até terminar; consulta parada por mais de 5 min vira falha com "Tentar novamente" | `/consulta/{uuid}` |
| RF-CON-07 | Isolamento entre usuários | Abrir consulta de outro usuário retorna 404 | `/consulta/{uuid}` |
| RF-CON-08 | Limites de uso | 30 consultas/hora e 120 buscas/minuto por usuário; excedido mostra aviso | `/consulta` |
| RF-CON-09 | Alerta de alergia | Ao selecionar medicamento e no topo do resultado, alerta por substância ou classe; ausência de alerta nunca é "sem risco" (requer consentimento) | `/consulta` |
| RF-CON-10 | Aviso de que não substitui o médico | Presente em todas as telas do paciente | todas |

## 4. Bula humanizada (`docs/consulta-pwa.md`)

| ID | Requisito | Critério de aceite | Rota |
| --- | --- | --- | --- |
| RF-BUL-01 | Resumo em linguagem simples | Seções: para que serve, como usar, benefícios, riscos, efeitos comuns, "não use se", interações, quando procurar ajuda; só com base nas bulas cadastradas | `/bula/{id}` |
| RF-BUL-02 | Geração e cache | Primeira abertura mostra "Preparando…" e gera; abertura seguinte é imediata; produtos com os mesmos ativos compartilham o resumo | `/bula/{id}` |
| RF-BUL-03 | Estados sem conteúdo | Sem princípio ativo, sem bula ou provedor indisponível mostra mensagem clara, sem texto inventado | `/bula/{id}` |
| RF-BUL-04 | Bloco "No seu tratamento" | Mostra em uso, posologia, alergias e interações com os remédios do paciente; sem consentimento não aparece | `/bula/{id}` |
| RF-BUL-05 | Atalhos | Botão "Bula" na pesquisa e no resultado; "Perguntar sobre ele" abre `/perguntar?medicamento={id}` | `/consulta` |

## 5. Perguntar (chat) (`docs/consulta-pwa.md`)

| ID | Requisito | Critério de aceite | Rota |
| --- | --- | --- | --- |
| RF-PER-01 | Pergunta livre sobre remédio | Reconhece o(s) medicamento(s) citados e responde só com trechos de bula e fatos do tratamento, com fontes | `/perguntar` |
| RF-PER-02 | Sem evidência, sem resposta | Quando não há trecho relevante, diz que não sabe e orienta consultar bula/profissional | `/perguntar` |
| RF-PER-03 | Conversa com contexto | "E com café?" reaproveita o remédio da pergunta anterior; até 6 trocas na tela; "Começar outra conversa" limpa | `/perguntar` |
| RF-PER-04 | Correção de digitação | Mostra "Entendemos X como Y. Não é isso?" com "Trocar o remédio" e "Não era esse remédio" | `/perguntar` |
| RF-PER-05 | Ditado por voz | Botão Falar escreve a pergunta; erros de microfone têm mensagem; sem suporte do navegador o botão não aparece e há teclado | `/perguntar` |
| RF-PER-06 | Ouvir a resposta | Leitura em voz alta onde o navegador suporta | `/perguntar` |
| RF-PER-07 | Triagem de entrada | Instrução maliciosa, código, pedido de dano ou assunto fora do tema é recusado sem chamar o modelo; risco de dano destaca o CVV 188 | `/perguntar` |
| RF-PER-08 | Sem conduta médica | Respostas não mandam suspender, trocar, ajustar dose nem iniciar medicamento | `/perguntar` |
| RF-PER-09 | Registro da pergunta | Toda pergunta fica em Histórico com resposta, fontes e contexto da época | `/historico/perguntas/{uuid}` |

## 6. Perfil, alergias e remédios em uso (`docs/consulta-pwa.md`, `docs/tratamento.md`)

| ID | Requisito | Critério de aceite | Rota |
| --- | --- | --- | --- |
| RF-PRF-01 | Consentimento de dados de saúde | Sem consentimento nada é gravado nem usado; consentir e revogar geram auditoria | `/perfil` |
| RF-PRF-02 | Cadastro de alergias | Adiciona e remove; alimenta os alertas | `/perfil` |
| RF-PRF-03 | Medicamentos em uso com posologia | Dose, frequência, período, receita vinculada; tratamento com data final passada sai do cruzamento | `/perfil` |
| RF-PRF-04 | Remédio fora do catálogo | "Cadastrar assim mesmo" registra pelo nome; marcado como não verificável | `/perfil` |
| RF-PRF-05 | Receitas | Cadastra prescritor (nome obrigatório); remover receita mantém os medicamentos | `/perfil` |
| RF-PRF-06 | Apagar todos os meus dados de saúde | Remove alergias, medicamentos, receitas e perguntas e revoga o consentimento | `/perfil` |

## 7. Receita por foto

| ID | Requisito | Critério de aceite | Rota |
| --- | --- | --- | --- |
| RF-REC-01 | Enviar foto da receita | Exige consentimento; aceita jpg/png/webp até 8 MB; câmera ou galeria; 10 envios/hora | `/receitas/nova` |
| RF-REC-02 | Leitura da receita | Extrai prescritor, data e itens; campo ilegível fica vazio, nunca completado | `/receitas/nova` |
| RF-REC-03 | Conferência obrigatória | Paciente revisa e edita cada item; item de baixa confiança tem selo "confira"; pode não cadastrar item | `/receitas/{id}/revisar` |
| RF-REC-04 | Confirmar e salvar | Só após confirmar a receita e os remédios entram no perfil, com horários sugeridos editáveis | `/receitas/{id}/revisar` |
| RF-REC-05 | Privacidade da imagem | A foto não fica gravada após a leitura (sucesso ou falha) | (servidor) |

## 8. Remédios de hoje, avisos e lembretes (`docs/tratamento.md`, `docs/notificacoes.md`)

| ID | Requisito | Critério de aceite | Rota |
| --- | --- | --- | --- |
| RF-TRA-01 | Agenda do dia | Horários agrupados em Manhã/Tarde/Noite com estado (A tomar, Agora, Atrasado, Tomei, Pulei); navegar Ontem/Amanhã | `/hoje` |
| RF-TRA-02 | Marcar dose | "Tomei" e "Pular" em um toque, com "Desfazer" | `/hoje` |
| RF-TRA-03 | Programação por intervalo | "De 8 em 8 horas" recalcula os próximos horários a partir da dose realmente tomada | `/hoje` |
| RF-TRA-04 | Próxima dose na Início | Botão mostra "Próximo: hora · remédio"; atrasada em vermelho | `/inicio` |
| RF-AVI-01 | Lembrete de horário | Um aviso por dose na janela configurada; não envia se a dose já foi marcada, sem consentimento, com lembrete desligado ou "se precisar" | `/avisos` |
| RF-AVI-02 | Aviso de fim de tratamento | No último dia, a partir das 08:00, sem duplicar | `/avisos` |
| RF-AVI-03 | Aviso de remédio vinculado | Quando o admin liga ao catálogo um remédio cadastrado pelo nome | `/avisos` |
| RF-AVI-04 | Central de avisos | Lista os mais recentes, "Novo", marcar todos como lidos, apagar, ação por aviso | `/avisos` |
| RF-AVI-05 | Sino | Contagem de não lidos no cabeçalho; aviso do navegador com permissão e app aberto | (cabeçalho) |
| RF-AVI-06 | Preferências | Ligar/desligar lembretes, e-mail opcional e fuso | `/avisos` |

## 9. Histórico e privacidade do paciente

| ID | Requisito | Critério de aceite | Rota |
| --- | --- | --- | --- |
| RF-HIS-01 | Listar consultas e perguntas | Duas listas paginadas separadas | `/historico` |
| RF-HIS-02 | Excluir individual ou tudo | Remove e registra auditoria apenas com a contagem | `/historico` |
| RF-HIS-03 | Retenção | Consultas e perguntas expiram em 90 dias (`consultations:purge-expired`) | (comando) |

## 10. PWA e navegação

| ID | Requisito | Critério de aceite | Rota |
| --- | --- | --- | --- |
| RF-PWA-01 | Instalação | App instalável; abre em `/consulta`; ícones corretos | `manifest` |
| RF-PWA-02 | Sem conexão | Faixa "sem conexão"; análise exige conexão; tela offline pública | `/offline` |
| RF-PWA-03 | Atualização do app | Nova versão pede confirmação "Atualizar agora" | (service worker) |
| RF-PWA-04 | Navegação em 4 destinos e início com 5 botões | Funciona em 360 px de largura, alvos de toque grandes, foco visível | `/inicio` |
| RF-PWA-05 | Sobre | Página pública de informação do produto | `/sobre` |

## 11. Base de conhecimento (ADMIN) (`docs/tcc/01-visao-geral-e-arquitetura.md`)

| ID | Requisito | Critério de aceite | Rota |
| --- | --- | --- | --- |
| RF-KNO-01 | Origens documentais | Cadastrar, editar, consultar, ativar e desativar | `/admin/knowledge/sources` |
| RF-KNO-02 | Documentos | Cadastrar e consultar documento lógico independente do PDF | `/admin/knowledge/documents` |
| RF-KNO-03 | Upload de PDF | Anexa PDF a um documento; upload múltiplo em lote, cada item tratado isoladamente | `/admin/knowledge/upload` |
| RF-KNO-04 | Duplicidade | Mesmo SHA-256 é detectado e sinalizado | `/admin/knowledge/batches/{batch}` |
| RF-KNO-05 | Quarentena e associação manual | Arquivo sem associação segura fica privado; admin associa a documento novo ou existente | `/admin/knowledge/pending` |
| RF-KNO-06 | Processamento | Extrai texto (com OCR quando preciso), detecta seções, cria trechos com documento, seção e páginas | `/admin/knowledge/queue` |
| RF-KNO-07 | Reprocessar | Falha no reprocessamento não apaga trechos válidos existentes | `/admin/knowledge/documents/{id}` |
| RF-KNO-08 | Progresso em tempo real | Fila e lote mostram etapa e progresso por página via stream | `/admin/knowledge/queue` |
| RF-KNO-09 | Revisão de item do lote | Visualizar e baixar o arquivo, revisar e associar | `/admin/knowledge/batches/{b}/items/{i}/review` |
| RF-KNO-10 | Catálogo e interações | Consultar catálogo ANVISA e interações DDInter | `/admin/knowledge/catalog`, `/interactions` |
| RF-KNO-11 | Remédios não encontrados | Listar o que pacientes cadastraram fora do catálogo e ligar ao catálogo (gera aviso ao paciente) | `/admin/knowledge/unmatched-medicines` |
| RF-KNO-12 | Saúde dos serviços | Painel de estado dos serviços (OCR, classificador, provedor, banco) | `/admin/knowledge/services/health` |
| RF-KNO-13 | API interna | Importar metadados/chunks, lote, arquivo e associação com token; sem token retorna 401 | `/api/internal/knowledge/*` |
| RF-KNO-14 | Segurança dos arquivos | PDFs não são publicados diretamente; erros não vazam caminho, SQL ou stack trace | (todas) |

## 12. Analytics e relatórios (ADMIN) (`docs/analytics.md`, `docs/relatorios.md`)

| ID | Requisito | Critério de aceite | Rota |
| --- | --- | --- | --- |
| RF-ANA-01 | Painel de analytics | Métricas do período (consultas, perguntas, bulas, estados, custo) | `/admin/analytics` |
| RF-ANA-02 | Auditoria das respostas da IA | Detalhe de consulta, pergunta e resumo de bula com evidências e afirmações aceitas/rejeitadas | `/admin/analytics/{consultas,perguntas,bulas}` |
| RF-REL-01 | Gerar relatório | Tipos: analytics, inventário, consumo de IA, auditoria de usuários, avaliação; formatos CSV/JSON; período quando aplicável | `/admin/relatorios` |
| RF-REL-02 | Baixar e apagar | Download só para admin; 404 se arquivo ausente; apagar pede confirmação | `/admin/relatorios` |
| RF-REL-03 | Anonimização | Relatórios de pacientes não trazem nome nem e-mail; auditoria não traz senha/token | (arquivos gerados) |
| RF-REL-04 | Limite de geração | 20 gerações em 10 minutos por admin | `/admin/relatorios` |

## 13. Segurança do conteúdo (transversal) (`docs/diretrizes-do-produto.md`)

| ID | Requisito | Critério de aceite |
| --- | --- | --- |
| RF-SEG-01 | Respostas só com evidência | Afirmação sem citação ou sem base no trecho é descartada |
| RF-SEG-02 | Gravidade vem da base estruturada | O modelo nunca define existência ou gravidade de interação |
| RF-SEG-03 | Entrada não confiável | Tentativas de injeção de instruções em perguntas, nomes e imagens não alteram o comportamento |
| RF-SEG-04 | Autorização | Todas as rotas exceto login, recuperação de senha, `/`, `/sobre` e `/offline` exigem autenticação |

## Pendências conhecidas (não reportar como defeito)

- Sem Web Push: aviso do navegador só com o app aberto.
- Leitura de receita: uma foto por receita; manuscrito ilegível gera itens de baixa confiança.
- Classes de alergia pendentes de revisão farmacêutica; sem reação cruzada entre classes.
- Voz depende do navegador; layout e acessibilidade ainda sem auditoria em dispositivo real ou leitor de tela.
- Relatórios sem PDF e sem expurgo automático.
