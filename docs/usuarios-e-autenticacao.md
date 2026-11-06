# Usuários e autenticação

## Propósito
Gestão de acessos pela administração e scaffold completo de autenticação (entrar, lembrar-me, esqueci minha senha, primeiro acesso, Minha conta, sessões), com hash de senha Argon2id e trilha de auditoria por conta.

## Hash de senhas: Argon2id (e por que não "Argon2d")
- `config/hashing.php`: `HASH_DRIVER=argon2id` (padrão), parâmetros `HASH_ARGON_MEMORY` (65536 KiB), `HASH_ARGON_THREADS` (1), `HASH_ARGON_TIME` (4), `rehash_on_login=true`.
- O PHP (`password_hash`) oferece apenas `argon2i` e `argon2id`. O Argon2**d** puro não está disponível e, pela RFC 9106 e pelo OWASP, o **Argon2id** é a variante recomendada: combina a resistência a ataques por canal lateral do Argon2i com a resistência a GPUs/ASICs do Argon2d. Portanto o requisito "Argon2D" foi atendido com `argon2id`.
- Migração transparente: hashes bcrypt já gravados continuam válidos (`HASH_ARGON_VERIFY=false`, o algoritmo é lido do próprio hash) e são re-hasheados em argon2id no próximo login. Quando não restar bcrypt, `HASH_ARGON_VERIFY=true` recusa qualquer hash que não seja argon2id.
- Teste: `tests/Feature/Auth/PasswordHashingTest.php`.

## Fluxos e rotas (`routes/web.php`)
| Rota | Nome | Descrição |
| --- | --- | --- |
| `GET/POST /login` | `login`, `login.store` | e-mail normalizado, `Auth::attemptWhen` (conta inativa só é revelada após a senha conferir), "Manter conectado" (`remember`), regeneração de sessão, `last_login_at`/`last_login_ip` |
| `GET/POST /forgot-password` | `password.request`, `password.email` | link de redefinição (broker padrão, token de uso único, expira em `auth.passwords.users.expire` min). Resposta idêntica exista ou não o e-mail; contas inativas não recebem link; auditoria `password_reset_requested` |
| `GET /reset-password/{token}`, `POST /reset-password` | `password.reset`, `password.update` | redefine a senha, limpa a troca obrigatória, derruba todas as sessões, auditoria `password_reset` |
| `GET /first-access`, `PUT /first-access/password` | `first-access` | troca obrigatória da senha provisória |
| `GET /account`, `PUT /account/password` | `account` | Minha conta: dados, último acesso/IP, troca voluntária (pede a senha atual) |
| `POST /account/sessions/terminate` | `account.sessions.terminate` | "Sair de outros dispositivos" (pede a senha atual), auditoria `sessions_terminated` |
| `GET /account/history` | `account.history` | histórico de segurança da própria conta (eventos, IP, quem fez) |
| `POST /logout` | `logout` | invalida a sessão e o token CSRF |

Bloqueio progressivo (`AppServiceProvider`, limiter `login`): 5 tentativas/min **e** 20 tentativas/15 min por e-mail + IP; a mensagem volta ao formulário com o tempo restante (segundos ou minutos). Pedidos de link: 3/min por e-mail + IP (`password-reset`).

Política de senha (`Password::defaults()`): mínimo 12 caracteres com letras e números; em produção, também não constar em vazamentos conhecidos (`uncompromised`, consulta k-anonimizada ao Have I Been Pwned). Telas: `resources/views/layouts/auth.blade.php` (layout comum com a identidade visual) + `resources/views/auth/*`.

E-mails (`app/Notifications`): `ResetPasswordNotification` (link) e `TemporaryPasswordNotification` (senha provisória, só quando o administrador marca "enviar por e-mail"). Em desenvolvimento `MAIL_MAILER=log` grava o e-mail em `storage/logs`; em produção configure `MAIL_*` (`docker/production/env/app.env.example`).

## Gestão de usuários (admin, `/admin/users`)
- Lista: busca, filtros (situação inclusive "troca de senha pendente", perfil), ordenação (nome, acesso mais recente, cadastro mais recente), contagem, resumo (usuários, ativos, administradores ativos — alerta quando só há um —, pendentes). Cartões em qualquer largura.
- Cadastro: senha provisória exibida uma única vez, com opção de enviá-la por e-mail; edição de nome/e-mail/perfil (troca de perfil derruba sessões).
- Página do usuário: detalhes (último acesso + IP), uso do aplicativo (só contagens: consultas, perguntas, consentimento), histórico paginado (evento, motivo, data, IP, ator) e ações: nova senha provisória (com envio por e-mail opcional), exigir troca de senha no próximo acesso, encerrar sessões, desativar com motivo (auditado) / reativar.
- Travas mantidas: ninguém desativa, rebaixa, redefine ou força a troca da própria senha; o último administrador ativo não pode ser desativado nem rebaixado. Não há exclusão: desativação preserva histórico.
- Serviço: `App\Services\Users\UserAccountService` (toda ação roda em transação com `lockForUpdate`). Componentes: `App\Livewire\Admin\Users\{Index,Form,Show}`.

## Auditoria (`user_audit_events`)
Eventos: `created`, `updated` (campos alterados), `role_changed`, `deactivated` (com `reason`), `reactivated`, `temporary_password_issued`, `temporary_password_emailed`, `password_changed` (`via` first_access), `password_change_forced`, `password_reset_requested`, `password_reset` (`via` reset), `sessions_terminated` (`scope` all/others). Rótulos em `UserAuditEvent::label()`. Nunca grava senha ou hash; a senha provisória só existe no e-mail e na tela, uma vez.

## Testes
`tests/Feature/Auth/{AuthenticationTest,PasswordResetTest,PasswordHashingTest,AccountTest,FirstAccessTest,AccountStateMiddlewareTest,LivewirePersistentMiddlewareTest}.php`, `tests/Feature/Admin/UserManagementAdminTest.php`, `tests/Feature/Admin/AdminDesignSystemTest.php` (fiscal das telas de acesso e de usuários).
```bash
php artisan test --compact tests/Feature/Auth tests/Feature/Admin/UserManagementAdminTest.php
```

## Limitações conhecidas
- Encerrar sessões apaga as sessões do banco só com `SESSION_DRIVER=database`; com Redis (padrão em dev/produção) não há índice por usuário: o "Manter conectado" é invalidado e o middleware de conta ativa/perfil relido a cada requisição barra acessos desativados, mas uma sessão viva de um usuário ainda ativo só expira pelo `SESSION_LIFETIME`. Alternativas: ativar o middleware `AuthenticateSession` (invalida sessões ao trocar a senha) ou trocar para `database`.
- Verificação de e-mail e autenticação em dois fatores não fazem parte do escopo.
- A opção "enviar senha provisória por e-mail" transmite a senha em texto puro no e-mail; por isso é opcional e a troca no primeiro acesso é obrigatória.
- `uncompromised()` faz uma consulta externa (k-anonimidade) só em produção; sem saída para a internet a validação falha fechada — garanta a rede `egress` do deploy.

**Reformulação do admin (06/11/2026):** a lista de usuários é uma tabela ordenável pelos cabeçalhos (`sortBy`: nome, e-mail, perfil, último acesso, cadastro; o segundo clique inverte, parâmetros `ordem`/`sentido` na URL) com filtros em uma linha e "Limpar"; a página do usuário tem abas Dados · Acesso · Histórico (parâmetro `aba`), com as ações de acesso em tabela e o histórico em colunas (Evento · Detalhe · Quando · Por quem). Ver `docs/admin-design-system.md`.
