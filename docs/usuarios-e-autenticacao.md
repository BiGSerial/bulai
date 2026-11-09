# Usuários e autenticação

## Propósito
Gestão de acessos pela administração, cadastro pela própria pessoa com aprovação do administrador e scaffold completo de autenticação (entrar, lembrar-me, esqueci minha senha, primeiro acesso, Minha conta, sessões), com hash de senha Argon2id e trilha de auditoria por conta.

## Hash de senhas: Argon2id (e por que não "Argon2d")
- `config/hashing.php`: `HASH_DRIVER=argon2id` (padrão), parâmetros `HASH_ARGON_MEMORY` (65536 KiB), `HASH_ARGON_THREADS` (1), `HASH_ARGON_TIME` (4), `rehash_on_login=true`.
- O PHP (`password_hash`) oferece apenas `argon2i` e `argon2id`. O Argon2**d** puro não está disponível e, pela RFC 9106 e pelo OWASP, o **Argon2id** é a variante recomendada: combina a resistência a ataques por canal lateral do Argon2i com a resistência a GPUs/ASICs do Argon2d. Portanto o requisito "Argon2D" foi atendido com `argon2id`.
- Migração transparente: hashes bcrypt já gravados continuam válidos (`HASH_ARGON_VERIFY=false`, o algoritmo é lido do próprio hash) e são re-hasheados em argon2id no próximo login. Quando não restar bcrypt, `HASH_ARGON_VERIFY=true` recusa qualquer hash que não seja argon2id.
- Teste: `tests/Feature/Auth/PasswordHashingTest.php`.

## Fluxos e rotas (`routes/web.php`)
| Rota | Nome | Descrição |
| --- | --- | --- |
| `GET/POST /login` | `login`, `login.store` | e-mail normalizado, `Auth::attemptWhen` (conta inativa só é revelada após a senha conferir), "Manter conectado" (`remember`), regeneração de sessão, `last_login_at`/`last_login_ip` |
| `GET/POST /register` | `register`, `register.store` | cadastro pela própria pessoa (ver "Cadastro com aprovação"); limiter `register` |
| `GET/POST /forgot-password` | `password.request`, `password.email` | link de redefinição (broker padrão, token de uso único, expira em `auth.passwords.users.expire` min). Resposta idêntica exista ou não o e-mail; contas inativas ou com cadastro não aprovado não recebem link; auditoria `password_reset_requested` |
| `GET /reset-password/{token}`, `POST /reset-password` | `password.reset`, `password.update` | redefine a senha, limpa a troca obrigatória, derruba todas as sessões, auditoria `password_reset` |
| `GET /first-access`, `PUT /first-access/password` | `first-access` | troca obrigatória da senha provisória |
| `GET /account`, `PUT /account/password` | `account` | Minha conta: dados, último acesso/IP, troca voluntária (pede a senha atual) |
| `POST /account/sessions/terminate` | `account.sessions.terminate` | "Sair de outros dispositivos" (pede a senha atual), auditoria `sessions_terminated` |
| `GET /account/history` | `account.history` | histórico de segurança da própria conta (eventos, IP, quem fez) |
| `POST /logout` | `logout` | invalida a sessão e o token CSRF |

Bloqueio progressivo (`AppServiceProvider`, limiter `login`): 5 tentativas/min **e** 20 tentativas/15 min por e-mail + IP; a mensagem volta ao formulário com o tempo restante (segundos ou minutos). Pedidos de link: 3/min por e-mail + IP (`password-reset`). Cadastro: 3/min **e** 10/hora por IP (`register`), com a mensagem no formulário.

Política de senha (`Password::defaults()`): mínimo 12 caracteres com letras e números; em produção, também não constar em vazamentos conhecidos (`uncompromised`, consulta k-anonimizada ao Have I Been Pwned). Telas: `resources/views/layouts/auth.blade.php` (layout comum com a identidade visual) + `resources/views/auth/*`.

E-mails (`app/Notifications`): `ResetPasswordNotification` (link) e `TemporaryPasswordNotification` (senha provisória, só quando o administrador marca "enviar por e-mail"). Em desenvolvimento `MAIL_MAILER=log` grava o e-mail em `storage/logs`; em produção configure `MAIL_*` (`docker/production/env/app.env.example`). Se o transporte recusar o envio (API da KingHost sem crédito/domínio/token, SMTP fora do ar), `UserAccountService::emailTemporaryPassword` registra `temporary_password_email_failed` no log (sem a senha) e lança `TemporaryPasswordEmailException`; as telas "Gerar nova senha" e "Novo usuário" mostram a senha normalmente com um aviso em vermelho no modal explicando o motivo, e o evento `temporary_password_emailed` não é auditado. Antes isso respondia 500.

## Cadastro com aprovação (auto-cadastro)
- **Tela** `resources/views/auth/register.blade.php` (`Auth\RegisteredUserController`), com link na tela de entrada, no cabeçalho/rodapé da landing, no aviso acadêmico e no CTA final. Pede só **nome, e-mail, senha (+ confirmação) e o aceite** do aviso "Como cuidamos dos seus dados" (`terms` obrigatório). Nenhum dado de saúde: esses continuam exigindo o consentimento próprio em Perfil (`patient_profiles.health_consent_at`). Senha segue `Password::defaults()`.
- **Aviso acadêmico**: caixa em destaque no topo da tela de cadastro (protótipo de TCC, acesso limitado, sujeito a aprovação) e, na landing, faixa `#projeto-academico` logo após o hero + linha no hero + rodapé + FAQ.
- **Conta nasce pendente**: `UserAccountService::register()` grava perfil USER, `approval_status=pending`, `must_change_password=false` (a senha é da própria pessoa), `created_by=null`, `terms_accepted_at=now()` e audita `self_registered`. Depois do envio a tela mostra "Cadastro recebido!" (flash `registered`); ninguém é autenticado. E-mail já cadastrado responde com mensagem gentil (entre ou use "Esqueci minha senha").
- **Login**: `User::signInBlockedMessage()` é avaliado no callback de `Auth::attemptWhen` (só depois que a senha confere): `auth.awaiting_approval`, `auth.rejected` ou `auth.inactive`. Com senha errada a mensagem é sempre a genérica (`auth.failed`), sem revelar o cadastro. `EnsureUserIsActive` usa o mesmo método e derruba qualquer sessão não aprovada.
- **Admin** (`/admin/users`): aviso "N cadastros aguardando aprovação" com atalho "Ver pendentes" (`showAwaitingApproval`), filtros de situação `awaiting_approval` e `rejected`, selos "Aguardando aprovação"/"Cadastro recusado" e ações Aprovar/Recusar na linha (confirm-modal). Na página do usuário, painel de aprovação no topo (aprovar; recusar com motivo opcional que vai só para a auditoria), fato "Aprovação do cadastro" (quem e quando) e "Cadastrado por: a própria pessoa, pelo site". O filtro/total "Ativos" passou a contar só aprovados.
- **Regras** (`UserAccountService::approve/reject`, transação + `lockForUpdate`): aprova pendente ou recusado; recusa só pendente (senão erro `approval`); grava `approval_reviewed_by`/`approval_reviewed_at`; recusar gira o remember_token/apaga sessões. Usuários criados pelo admin (`create()`) nascem aprovados pelo próprio admin.
- **E-mails** (`app/Notifications`, Markdown com a identidade `resources/views/vendor/mail` da PR #44): `AccountApprovedNotification` → `mail.cadastro-aprovado` (para a pessoa, ao aprovar; auditado como `approval_emailed`); `RegistrationAwaitingApprovalNotification` → `mail.cadastro-pendente` (para cada ADMIN ativo e aprovado, a cada cadastro, com link para a página do usuário). Falha de transporte não desfaz nada: aprovação continua valendo (aviso vermelho na tela, log `approval_email_failed`) e o cadastro continua registrado (log `registration_admin_email_failed`).
- **Banco** (migration `2026_11_09_015218_add_approval_to_users_table`): `users.approval_status` (string, padrão `approved`, indexado; enum `App\UserApprovalStatus`: approved/pending/rejected), `approval_reviewed_by` (FK users, `nullOnDelete`), `approval_reviewed_at`, `terms_accepted_at`. Backfill: todos os usuários existentes ficam `approved` (sem revisor/data: "Aprovado (anterior à aprovação de cadastros)"). Factory: estados `awaitingApproval()` e `rejected()`.

## Gestão de usuários (admin, `/admin/users`)
- Lista: busca, filtros (situação inclusive "troca de senha pendente", perfil), ordenação (nome, acesso mais recente, cadastro mais recente), contagem, resumo (usuários, ativos, administradores ativos — alerta quando só há um —, pendentes). Cartões em qualquer largura.
- Cadastro: senha provisória exibida uma única vez, com opção de enviá-la por e-mail; edição de nome/e-mail/perfil (troca de perfil derruba sessões).
- Página do usuário: detalhes (último acesso + IP), uso do aplicativo (só contagens: consultas, perguntas, consentimento), histórico paginado (evento, motivo, data, IP, ator) e ações: nova senha provisória (com envio por e-mail opcional), exigir troca de senha no próximo acesso, encerrar sessões, desativar com motivo (auditado) / reativar.
- Travas mantidas: ninguém desativa, rebaixa, redefine ou força a troca da própria senha; o último administrador ativo não pode ser desativado nem rebaixado. Não há exclusão: desativação preserva histórico.
- Serviço: `App\Services\Users\UserAccountService` (toda ação roda em transação com `lockForUpdate`). Componentes: `App\Livewire\Admin\Users\{Index,Form,Show}`.

## Auditoria (`user_audit_events`)
Eventos: `created`, `updated` (campos alterados), `role_changed`, `deactivated` (com `reason`), `reactivated`, `temporary_password_issued`, `temporary_password_emailed`, `password_changed` (`via` first_access), `password_change_forced`, `password_reset_requested`, `password_reset` (`via` reset), `sessions_terminated` (`scope` all/others), `self_registered`, `registration_approved` (`from`), `registration_rejected` (`reason`), `approval_emailed`. Rótulos em `UserAuditEvent::label()`. Nunca grava senha ou hash; a senha provisória só existe no e-mail e na tela, uma vez.

## Testes
`tests/Feature/Auth/RegistrationTest.php` (tela com aviso acadêmico e só campos não sensíveis, cadastro pendente + aviso aos admins, validação/senha/duplicado, rate limit), `tests/Feature/Admin/UserApprovalAdminTest.php` (pendente/recusado não entram e a mensagem só aparece com a senha certa, sessão não aprovada derrubada, antigos e criados pelo admin seguem entrando, aprovar/recusar com auditoria e e-mail, falha de e-mail, filtros, só admin), `tests/Feature/LandingPageTest.php` (aviso acadêmico em destaque), `tests/Feature/Auth/{AuthenticationTest,PasswordResetTest,PasswordHashingTest,AccountTest,FirstAccessTest,AccountStateMiddlewareTest,LivewirePersistentMiddlewareTest}.php`, `tests/Feature/Admin/UserManagementAdminTest.php`, `tests/Feature/Admin/AdminDesignSystemTest.php` (fiscal das telas de acesso e de usuários).
```bash
php artisan test --compact tests/Feature/Auth tests/Feature/Admin/UserManagementAdminTest.php tests/Feature/Admin/UserApprovalAdminTest.php tests/Feature/LandingPageTest.php
```

## Limitações conhecidas
- Encerrar sessões apaga as sessões do banco só com `SESSION_DRIVER=database`; com Redis (padrão em dev/produção) não há índice por usuário: o "Manter conectado" é invalidado e o middleware de conta ativa/perfil relido a cada requisição barra acessos desativados, mas uma sessão viva de um usuário ainda ativo só expira pelo `SESSION_LIFETIME`. Alternativas: ativar o middleware `AuthenticateSession` (invalida sessões ao trocar a senha) ou trocar para `database`.
- Verificação de e-mail e autenticação em dois fatores não fazem parte do escopo. No auto-cadastro o e-mail não é verificado antes da aprovação: o administrador decide com base em nome/e-mail.
- Não há páginas formais de Termos de Uso/Política de Privacidade no projeto; o aceite do cadastro (`terms_accepted_at`) refere-se ao aviso resumido exibido na própria tela. Se as páginas forem criadas, ligue-as ao texto do aceite.
- O cadastro informa quando o e-mail já existe (enumeração possível, contida pelo limiter `register`); o login continua sem revelar.
- O e-mail aos administradores sai a cada cadastro (sem resumo diário); com muitos cadastros, considere agrupar.
- Os e-mails são síncronos (como os demais do projeto): um transporte lento atrasa a resposta do cadastro/aprovação.
- A opção "enviar senha provisória por e-mail" transmite a senha em texto puro no e-mail; por isso é opcional e a troca no primeiro acesso é obrigatória.
- `uncompromised()` faz uma consulta externa (k-anonimidade) só em produção; sem saída para a internet a validação falha fechada — garanta a rede `egress` do deploy.

**Reformulação do admin (06/11/2026):** a lista de usuários é uma tabela ordenável pelos cabeçalhos (`sortBy`: nome, e-mail, perfil, último acesso, cadastro; o segundo clique inverte, parâmetros `ordem`/`sentido` na URL) com filtros em uma linha e "Limpar"; a página do usuário tem abas Dados · Acesso · Histórico (parâmetro `aba`), com as ações de acesso em tabela e o histórico em colunas (Evento · Detalhe · Quando · Por quem). Ver `docs/admin-design-system.md`.
