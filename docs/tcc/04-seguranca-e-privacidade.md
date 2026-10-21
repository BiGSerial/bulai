# Segurança, privacidade e riscos

## Contexto

O sistema armazena documentos farmacêuticos e metadados operacionais. Mesmo quando o corpus não contém dados pessoais, pode existir conteúdo licenciado, interno ou sujeito a regras de distribuição. Segurança deve ser apresentada no TCC como requisito transversal, não como consequência automática do framework.

## Controles implementados

### Autenticação e autorização web

- autenticação por sessão Laravel;
- proteção CSRF nas rotas web;
- middleware `auth` para sessão válida;
- middleware `admin` para o papel `ADMIN`;
- invalidação da sessão no logout;
- usuário `USER` recebe `403` nas páginas administrativas;
- limite de 5 tentativas de login por minuto para o mesmo e-mail e IP, com a mensagem no próprio formulário;
- conta desativada não entra, e a mensagem de desativação só aparece quando a senha está correta (não revela a existência do e-mail);
- senha com pelo menos 12 caracteres, letras e números; acessos novos recebem senha provisória exibida uma única vez e trocada obrigatoriamente na tela de primeiro acesso, que recusa repetir a senha provisória e só troca a senha enquanto houver a pendência (fora dela, a troca exige a senha atual, em "Minha conta");
- desativar, trocar o perfil ou gerar nova senha provisória encerra as sessões da pessoa e invalida o "Manter conectado";
- as ações Livewire reaplicam as checagens da rota (conta ativa, perfil `ADMIN`, troca de senha pendente), o que impede um administrador rebaixado de agir em uma tela já aberta;
- o sistema nunca fica sem administrador ativo e ninguém desativa ou rebaixa o próprio acesso;
- trilha de auditoria da gestão de usuários (`user_audit_events`), sem senha.

### Autenticação da API interna

- Bearer Token temporário;
- segredo persistido apenas como SHA-256;
- escopo obrigatório `knowledge:ingest`;
- validação de expiração e revogação;
- limitação de requisições pelo limiter `knowledge-ingest`;
- token em texto puro exibido somente no momento da emissão.

### Proteção de arquivos

- PDFs ficam em disco privado, fora de `public/`;
- chaves de destino são geradas pela aplicação, não pelo filename fornecido;
- MIME, tamanho e checksum são validados;
- caminhos físicos e chaves privadas não são enviados nas respostas;
- o service worker não armazena navegações autenticadas de `/admin` e `/login`.

### Integridade e concorrência

- SHA-256 para verificar os bytes;
- bloqueios de linha antes de anexar ou associar;
- proibição de sobrescrita silenciosa;
- transação por item de lote;
- staging e compensação restritos ao UUID da operação;
- revalidação do arquivo antes do processamento e da associação manual.

### Tratamento de erros

- respostas utilizam códigos controlados;
- erros inesperados recebem `error_id`;
- SQL, stack trace, caminhos, nomes de infraestrutura e segredos ficam nos logs;
- metadados apresentados no Admin omitem chaves potencialmente privadas.

## Modelo simplificado de ameaças

| Ameaça | Controle atual | Risco residual |
| --- | --- | --- |
| Acesso web por usuário sem privilégio | Sessão + papel `ADMIN` + conta ativa, reaplicados nas ações Livewire | Falhas futuras de autorização em novas rotas |
| Tentativa de senha por força bruta | Limite por e-mail + IP e senha mínima de 12 caracteres | Ataque distribuído por muitos IPs; sem segundo fator |
| Acesso remanescente de quem saiu da equipe | Desativação com encerramento de sessões e auditoria | Depende de o administrador desativar a conta a tempo |
| Uso indevido da API | Token com escopo, expiração, revogação e rate limit | Vazamento do token durante seu período de validade |
| Publicação acidental de PDF | Disco privado e ausência de URL pública | Configuração incorreta de um futuro driver remoto |
| Path traversal | Chaves geradas e validação do prefixo esperado | Novos pontos de leitura devem manter a mesma regra |
| Sobrescrita concorrente | Transações e `lockForUpdate()` | Banco e filesystem não são uma transação distribuída |
| Arquivo adulterado | SHA-256 antes de promover/processar | SHA-256 comprova bytes, não legitimidade da fonte |
| Vazamento em mensagens de erro | Erros sanitizados e referência interna | Logs ainda exigem controle de acesso e retenção |
| PDF malicioso ou excessivo | MIME e limites configurados | Parser continua sendo uma superfície de ataque |
| Cache de conteúdo autenticado | Exclusão das rotas administrativas no service worker | Cache do navegador e máquina cliente permanecem fora do sistema |

## Privacidade e governança

Antes de usar dados reais no TCC, deve-se registrar:

- origem e licença de cada documento;
- finalidade e base autorizativa para armazenamento;
- presença ou ausência de dados pessoais;
- política de retenção, exclusão e acesso;
- responsáveis pela curadoria e validação;
- procedimento para correção de conteúdo;
- ambiente onde PDFs e backups serão armazenados.

Caso haja dados pessoais, a análise deve considerar a LGPD e ser revisada pelo orientador ou responsável institucional. Não se deve afirmar conformidade legal apenas com base nos controles técnicos descritos aqui.

## Controles ainda necessários para produção

- HTTPS obrigatório e configuração segura de cookies;
- gestão web ou cofre para emissão, rotação e revogação de tokens;
- política de retenção e limpeza de quarentena/staging;
- comando de reconciliação entre banco e filesystem;
- criptografia e políticas de acesso no object storage e nos backups;
- monitoramento e alertas de autenticação, ingestão e fila;
- varredura de dependências e atualização contínua;
- avaliação de PDFs hostis e isolamento do parser;
- trilha de auditoria administrativa dedicada para as demais áreas (a gestão de usuários já possui a sua);
- segundo fator de autenticação e redefinição de senha por e-mail;
- plano de backup, restauração e resposta a incidentes;
- revisão formal de ameaça antes de expor o sistema externamente.

## Cuidados na apresentação do TCC

- anonimize e-mails, tokens, IDs sensíveis e nomes internos;
- não use PDFs sem autorização de redistribuição;
- não inclua `.env`, credenciais, logs completos ou caminhos de máquina;
- deixe explícito que integridade do arquivo não garante correção farmacêutica;
- não apresente a aplicação como ferramenta de diagnóstico ou prescrição.
