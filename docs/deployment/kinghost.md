# Implantação do BULA-IA na VPS KingHost (Docker Compose + GitHub Actions)

Guia operacional da infraestrutura de produção: arquitetura, fronteira de confiança, preparação da VPS, primeiro deploy,
operação, backup, rollback e solução de falhas. Nenhum valor secreto aparece aqui; todos os exemplos usam *placeholders*.

> **Estado desta entrega:** o stack, o script de deploy e o fluxo de rollback foram executados e validados **localmente**
> com Docker real (ver [Verificações realizadas](#verificações-realizadas) e [Limitações](#limitações-e-pontos-a-validar-na-vps)).
> **Nada disto foi executado na VPS nem publicado em produção.**

## 1. Arquitetura

```
Internet → Cloudflare → Caddy (host, TLS) → 127.0.0.1:8080 ─┐
                                                            │ (única porta publicada)
   rede `edge`                      ┌─────────────────────┐ │
      web (Nginx, uid 10030) ◄──────┘                     │ │
        │ socket Unix (/srv/bulai/shared/run, 2750 10010:10030)
   redes `db` e `ai` (internal: sem saída para a internet; OCR/IA NÃO alcançam o banco)
      app (PHP-FPM 8.4, uid 10010) ── worker (queue:work, 1 job por vez, uid 10010)
        │  rede db                          │  rede ai
        └── postgres (pgvector/PG17)        ├── document-ocr (PaddleOCR, CPU)   ├── local-ai (llamafile + Qwen2.5-1.5B, CPU)
        │                                   └── embeddings (llamafiler + bge-m3, CPU; /v1/embeddings do RAG vetorial)
   rede `egress` (única com saída para a internet; nenhuma porta publicada): app/worker → api.anthropic.com (IA principal do RAG)
```

| Serviço | Imagem | Limites (8 GB RAM / 6 vCPU) | Observações |
|---|---|---|---|
| `web` | `bulai-web` (Nginx sem root) | 192 MB · 1 CPU | assets copiados da **mesma** imagem `app`; só ele publica porta |
| `app` | `bulai-app` | 768 MB · 2 CPU | PHP-FPM, 6 workers no máximo, FS somente leitura |
| `worker` | `bulai-app` | 768 MB · 2 CPU | **um** worker, um job por vez: OCR e IA nunca rodam em paralelo; `stop_grace_period` 480 s |
| `scheduler` | `bulai-app` | 256 MB · 0,5 CPU | `php artisan schedule:work`: lembretes de dose a cada 5 min e expurgo diário (ver [Agendador e lembretes](#agendador-e-lembretes)) |
| `postgres` | `pgvector/pgvector:pg17@sha256:…` | 768 MB · 2 CPU | `oom_score_adj -500` (o kernel o preserva por último) |
| `document-ocr` | `bulai-ocr` (modelos embutidos) | 2 GB · 2 CPU | concorrência 1 (`OCR_MAX_CONCURRENCY`), `oom_score_adj +500` |
| `local-ai` | `bulai-local-ai` | 2,3 GB · 3 CPU | modelo fora da imagem e do Git; `oom_score_adj +500` |
| `embeddings` | `bulai-local-ai` (mesma imagem, `LOCAL_AI_MODE=embedding`) | 1,5 GB · 2 CPU | bge-m3 Q8_0 (~635 MB) em `/srv/bulai/shared/models`; só `/v1/embeddings` e `/v1/models`; `oom_score_adj +500` |

Soma dos tetos: ≈ 8,3 GB (com `embeddings`). O pico real é bem menor: OCR e IA local rodam **em sequência** (um worker) e o serviço de embeddings usa ~0,8 GB em regime; se a VPS ficar apertada, reduza `local-ai` para 2 GB ou pare `embeddings` fora da janela de indexação (a recuperação volta ao modo lexical automaticamente quando o provedor falha).
Os limites usam `mem_limit`/`memswap_limit`/`cpus`, atributos de serviço do Compose Specification (efetivos com
`docker compose up`; confirmados por `docker inspect` nos testes). `memswap_limit = mem_limit` impede *swap thrashing*.

Decisões de segurança do stack:

- Containers: `cap_drop: ALL`, `no-new-privileges`, usuários não-root com **UIDs/GIDs altos** (`app`/`worker` 10010, `web` 10030, `postgres` 10020, `ocr` 10001, `local-ai` 10002; ver [UIDs, bind mounts e contas do host](#uids-bind-mounts-e-contas-do-host)),
  `read_only: true` em `app`/`worker`/`web`; só `storage/app`, o socket e `tmpfs` são graváveis. Código imutável (`root:root`).
- Redes `db` (app/worker ↔ PostgreSQL) e `ai` (app/worker ↔ OCR/IA) são `internal: true` (sem rota para fora) e nenhuma porta é publicada. OCR e IA, que processam PDFs não confiáveis,
  **não** compartilham rede com o PostgreSQL. O PHP-FPM **não** escuta em TCP: usa um socket Unix num diretório `2750` (dono `10010`, grupo `10030`). Quem alcançar esse socket executa PHP no
  container `app` (inclusive via parâmetros FastCGI), então o acesso é restrito ao grupo 10030 (nenhuma conta do host pertence a ele): o `web` (que tem saída de rede pela rede `edge`) é, portanto, um ponto de pivô a proteger (imagem fixa, `read_only`, sem capabilities).
- A rede existente `bulai-internal` **não é usada**: é compartilhada com contêineres que não controlamos e o socket dispensa rede.
  Se quiser anexar algo a ela, faça-o em um override aprovado, nunca expondo PHP-FPM, PostgreSQL, OCR ou IA.
- O PostgreSQL cria o papel `bulai` **sem superusuário**, dono do banco; a extensão `vector` é criada uma única vez pelo superusuário de manutenção.
- `app` e `worker` têm saída para a internet **apenas** pela rede `egress` (não interna, sem portas publicadas), necessária para a API da Anthropic (`AI_TEXT_DRIVER=anthropic`, IA principal do RAG). As redes `db`, `ai` e `cache` continuam internas; OCR, IA local, embeddings, Redis e PostgreSQL seguem sem rota para fora. Para restringir o destino a `api.anthropic.com`, use regras de saída no host (nftables/ufw por UID do container não é possível; filtre pela sub-rede `bulai_egress`).
- Cabeçalhos: o Caddy envia o IP real (`X-Real-IP` ← `CF-Connecting-IP`, só se a requisição vier do Cloudflare) e `X-Forwarded-Proto`. O Nginx só aceita `X-Real-IP` de redes privadas/loopback
  (`set_real_ip_from`); `X-Forwarded-Proto` é aceito de qualquer origem, o que só é seguro porque a porta está publicada **apenas em 127.0.0.1** (quem alcança 8080 já está no host). O `TRUSTED_PROXIES` do Laravel (`bootstrap/app.php`) existe para outras topologias e fica vazio.
- Limites de corpo: 2 MB por padrão; 110 MB somente em `/livewire-<hash>/upload-file` e `/api/internal/knowledge/` (verificado: 413 nas demais rotas).

## 2. Fronteira de confiança do CI/CD

O usuário `deploy` **não** pertence ao grupo Docker e isso foi preservado. Chamar um script root que executa Compose a partir de caminhos que o usuário controla
equivale a dar root a quem controla esses caminhos. Por isso:

| Elemento | Quem controla | Observação |
|---|---|---|
| `/usr/local/sbin/bulai-deploy` | root | script privilegiado; valida cada argumento (sha 40 hex, digests `sha256:<64 hex>`) |
| `/opt/bulai/trusted/` (compose aprovado, init do PostgreSQL, `model.env`) | root | única orquestração usada; **atualizada por um administrador após revisão** |
| `/etc/sudoers.d/bulai-ci` | root | o usuário `bulai-ci` só pode `bulai-deploy check/activate/rollback/status` |
| `/usr/local/bin/bulai-ci-entry` | root | comando **forçado** da chave SSH do CI (`restrict`): sem shell, sem encaminhamento, ações em lista fixa |
| `/var/lib/bulai-deploy/` (estado) | root | release atual/anterior |
| `/srv/bulai/shared/env/*.env` (segredos) | root `0600` | nunca passam pelo CI |
| `/srv/bulai/releases/` | `deploy` | continua do usuário `deploy`; o root **nunca lê nem escreve aqui**. Os manifests das releases ficam em `/var/lib/bulai-deploy/releases/<sha>/manifest.txt` (root) |
| Imagens no GHCR | GitHub Actions | a VPS baixa **por digest** e confere rótulos OCI (`source` em todas; `revision` = commit autorizado em `app` e `web`). Rótulos não autenticam a procedência (ver abaixo) |

O que o CI consegue: publicar imagens do repositório e pedir à VPS que ative **esses digests**. O que **não** consegue: alterar o compose, abrir portas,
montar caminhos do host, executar comandos arbitrários na VPS nem usar o Docker diretamente.

**Limite honesto do modelo:** uma imagem publicada pelo pipeline roda com `app.env` (APP_KEY, senha do banco) e acesso ao banco, e os rótulos OCI são texto livre. Quem conseguir
executar o workflow com o secret do ambiente `production` (por exemplo, a partir de outra branch) poderia publicar uma imagem maliciosa com os rótulos certos. A fronteira protege **o host e a orquestração**,
não os dados da aplicação contra uma `main`/Environment comprometidos. A defesa real é: *Environment `production`* com revisores obrigatórios e *Deployment branches = main* (o workflow também recusa refs ≠ `main`),
proteção da `main`, CODEOWNERS e 2FA. Endurecimento futuro recomendado: verificar na VPS a *attestation* de procedência (`gh attestation verify`/`cosign`) com a identidade do workflow e `refs/heads/main`. Se o compose do commit diferir do instalado, o deploy
**falha com código 20** até um administrador reinstalar o conjunto confiável (`install-on-vps.sh`). Risco residual: quem controla a `main` e o *Environment*
`production` controla o código que roda (inevitável); proteja com revisão obrigatória, CODEOWNERS e aprovação do ambiente.

### UIDs, bind mounts e contas do host

Os bind mounts de `/srv/bulai/shared` pertencem a UIDs/GIDs numéricos, que o kernel compara com as contas **do host**. Na VPS KingHost validada: `999 = caddy`, `101 = messagebus` (uid) e `lxd` (gid).
Com o layout inicial (PostgreSQL como `999:999`, socket com grupo `101`) isso daria **acesso indevido**: o `caddy` (e qualquer processo seu) leria e gravaria o diretório do PostgreSQL, e membros do grupo `lxd`
(normalmente administradores) alcançariam o socket do PHP-FPM e executariam PHP no container. Demonstrado em Docker real com `--user 999:999` e `--user 1234:101`.

Correção (sem alterar nenhuma conta da VPS): todos os containers usam IDs **altos**, sem equivalente no host, e o instalador **recusa** continuar se algum já existir (`getent`).

| ID | Uso | Bind mount / observação |
|---|---|---|
| 10010 | `app`, `worker` | `/srv/bulai/shared/storage` `0750 10010:10010` |
| 10020 | `postgres` | `/srv/bulai/shared/postgres` `0700 10020:10020`; `/etc/passwd` e `/etc/group` mínimos montados somente leitura (a imagem só inicializa o banco se o uid existir no passwd do container) |
| 10030 | `web` (Nginx) | `/srv/bulai/shared/web-cache` `0700 10030:10030`; grupo do socket |
| 10010:10030 | socket do PHP-FPM | `/srv/bulai/shared/run` `2750`, socket `0660`: só app e web |
| 10001 / 10002 | `document-ocr` / `local-ai` | sem bind mount gravável (`tmpfs`; modelo `root:root` somente leitura) |
| 0 | segredos `env/*.env`, `models`, `/var/lib/bulai-deploy` | `root:root` (`0600`/`0755`/`0700`); segredos chegam aos containers pelo daemon, nunca por bind mount |

Verificado em Docker real: o stack sobe com esses IDs (PostgreSQL inicializa, Nginx lê/grava o buffer e conecta ao socket, `tmpfs` com `uid/gid` aplicados), e processos com `--user 999:999`, `101:101` e `999:101`
**não conseguem listar** `postgres`, `storage`, `run` nem `web-cache` (apenas `models`, público por desenho: pesos abertos). O `bulai-deploy` confere dono e modo de cada diretório no `check`/`activate` e recusa o deploy se divergirem.
Se já existir um banco inicializado com outro dono (por exemplo o `999` padrão da imagem), o instalador migra a posse para `10020` (o `caddy` deixa de ter acesso).

**Limitações:**
- **User namespaces / Docker rootless:** com `userns-remap` ou modo *rootless* o uid do container é mapeado para um *subuid* do host e os donos acima deixariam de valer. Não é suportado: o instalador e o `bulai-deploy` recusam
  esses daemons (`docker info` com `name=userns`/`name=rootless`). A VPS atual não usa nenhum dos dois.
- Os IDs são **fixos** no compose, nos Dockerfiles, no instalador e no `bulai-deploy` (um teste em `scripts/deploy/tests/run.sh` confere a consistência). Para trocar, altere os quatro lugares e rode o instalador.
- O instalador só **verifica** colisões; se uma conta do host usar um desses IDs no futuro (ex.: um novo serviço criar o uid 10020), ela passará a ter acesso ao diretório correspondente. Revise `getent passwd` antes de instalar novos serviços na VPS.
- O mesmo princípio vale para `/srv/bulai/backups` (`root:root 0700`): os dumps não ficam acessíveis a contas do host.

## 3. Requisitos da VPS

- Ubuntu 24.04 LTS, Docker Engine + Compose (v2/v5), `curl`, `openssl`, `flock`, `visudo`, espaço livre ≥ 5 GB em `/srv/bulai/shared` (o deploy recusa com menos).
- Rede de saída só para o **daemon** Docker (GHCR e Hugging Face) e para `bulai-deploy ensure-model`.
- Verifique antes (valida as suposições de limites de memória):

```bash
docker version --format '{{.Server.Version}}'; docker compose version
docker info 2>/dev/null | grep -iE 'cgroup (driver|version)|swap'   # esperado: cgroup v2; sem "No swap limit support"
free -h; df -h /srv/bulai
docker network ls | grep bulai-internal                              # existe, mas não é usada por este stack
```

## 4. Preparação (uma vez, como administrador `willhome`)

1. **Obter o conjunto confiável a partir de um commit REVISADO, em uma cópia do root** (nunca do checkout do usuário `deploy`: o instalador roda como root e
   recusa árvores que não pertençam ao root). No **seu computador**, com o commit da PR/`main` já revisado:

   ```bash
   git archive --format=tar.gz -o bulai-trusted.tgz <SHA revisado> compose.prod.yaml docker/production scripts
   sha256sum bulai-trusted.tgz                          # anote o hash
   scp bulai-trusted.tgz willhome@vixforge02.vps-kinghost.net:~/   # home do willhome, não /tmp (diretório 1777 pré-criável por outros usuários)
   ```

   Na VPS:

   ```bash
   chmod 600 ~/bulai-trusted.tgz && sha256sum ~/bulai-trusted.tgz     # deve ser IGUAL ao hash anotado
   sudo install -d -m 0755 -o root -g root /root/bulai-src && sudo tar -xzf ~/bulai-trusted.tgz -C /root/bulai-src --no-same-owner
   sudo chown -R root:root /root/bulai-src && rm ~/bulai-trusted.tgz
   sha256sum /root/bulai-src/compose.prod.yaml           # anote: é o hash que o deploy confere
   ```

2. **Instalar o conjunto confiável** (cria `bulai-ci`, diretórios e permissões; não toca em Caddy, rede Docker, `willhome` ou `deploy`):

   ```bash
   sudo /root/bulai-src/scripts/deploy/install-on-vps.sh
   sudo /root/bulai-src/scripts/deploy/install-on-vps.sh --check   # compara repositório × instalado (drift); use depois de cada alteração
   ```

3. **Segredos** (`/srv/bulai/shared/env/{app,postgres,ocr}.env`, root `0600`; os valores são gerados aleatoriamente e nunca sobrescritos):

   ```bash
   sudo bulai-deploy init-secrets
   sudo editor /srv/bulai/shared/env/app.env     # preencha BULAI_ADMIN_EMAIL / BULAI_ADMIN_PASSWORD (temporário)
   ```

   Referência dos arquivos: `docker/production/env/*.env.example`. A senha `DB_PASSWORD` (app.env) deve ser **igual** a `BULAI_DB_PASSWORD` (postgres.env) e
   `DOCUMENT_OCR_TOKEN` igual a `OCR_API_TOKEN` (ocr.env); `init-secrets` já os alinha.

4. **Modelo da IA local** (fora do Git; validado por SHA-256; não é baixado de novo a cada deploy):

   ```bash
   sudo bulai-deploy ensure-model      # ~1,1 GB em /srv/bulai/shared/models; reexecutar é seguro (confere o hash)
   ```

5. **Acesso ao GHCR** para o *daemon* da VPS (somente leitura de pacotes; token fino com `read:packages`, nunca versionado):

   ```bash
   sudo docker login ghcr.io -u <usuario>          # cole o token quando solicitado
   ```

6. **Chave SSH exclusiva do CI** (no seu computador, não na VPS):

   ```bash
   ssh-keygen -t ed25519 -f bulai-ci -C bulai-ci-github-actions -N ""
   sha256sum bulai-ci.pub && scp bulai-ci.pub willhome@vixforge02.vps-kinghost.net:~/bulai-ci.pub
   # na VPS (confira o sha256sum do arquivo recebido):
   sudo /root/bulai-src/scripts/deploy/install-on-vps.sh --ci-public-key-file ~/bulai-ci.pub && rm ~/bulai-ci.pub
   ssh-keyscan -t ed25519 186.202.209.91        # confira a impressão digital com `ssh-keygen -lf /etc/ssh/ssh_host_ed25519_key.pub` na VPS
   ```

   A linha instalada é `restrict,command="/usr/local/bin/bulai-ci-entry" ssh-ed25519 …`: a chave só aciona o comando forçado.

7. **GitHub** (Settings do repositório):
   - *Environments → `production`* (crie **antes** do primeiro deploy): *Required reviewers* e *Deployment branches* = `main`; no próprio Environment adicione o secret
     **`KINGHOST_SSH_PRIVATE_KEY`** (conteúdo de `bulai-ci`), as *variables* **`KINGHOST_HOST`** (`vixforge02.vps-kinghost.net`),
     **`KINGHOST_SSH_KNOWN_HOSTS`** (linha do `ssh-keyscan` já conferida), **`PRODUCTION_PROTECTION=reviewers`** (declara que a aprovação está configurada; ver
     [Ordem de autorização](#ordem-de-autorização-do-deploy)) e, opcionais, `KINGHOST_SSH_PORT`, `KINGHOST_SSH_USER` (padrão `bulai-ci`) e `PUBLIC_HEALTHCHECK_URL` (só depois de o Caddy apontar para a aplicação).
   - *Branches → proteção da `main`*: exigir PR, exigir o check **“CI concluído”** e revisão dos CODEOWNERS (`.github/CODEOWNERS`).
   - *Actions → General*: permissões padrão do `GITHUB_TOKEN` = leitura.
   - Apague o par de chaves local depois de guardar a privada nos *secrets*.

### Ordem de autorização do deploy

O workflow `deploy.yml` foi organizado para que **nada seja publicado nem nenhum segredo seja exposto antes da aprovação humana**:

1. `authorize` (sem segredos, não publica nada): só roda a partir da `main`; confere `DEPLOY_ALLOWED_ACTORS` (se definida); para `deploy`, exige SHA completo, ancestral da `main` e **CI verde para esse SHA**.
2. `release` (ação `deploy`) e `operate` (`rollback`/`status`) rodam no *Environment* `production`. **A aprovação dos revisores é exigida antes de esses jobs começarem**, ou seja, antes de qualquer imagem ir ao GHCR e antes do secret SSH existir no runner.
   `rollback` e `status` **não contornam** a autorização: passam pelo mesmo Environment, pelas mesmas travas e pela mesma checagem de `main` e de executor.
3. A primeira etapa de `release`/`operate` é uma **trava**: sem `PRODUCTION_PROTECTION` declarada, o job falha antes de publicar ou conectar. Isso existe porque o GitHub **cria automaticamente** um Environment inexistente
   **sem nenhuma proteção** quando um job o referencia (hoje o `production` ainda não existe no repositório).
4. Rastreabilidade por digest: o build e o envio à VPS estão no **mesmo job**, então os digests publicados são exatamente os enviados. Antes de acionar a VPS o job confere que: o `HEAD` construído é o SHA autorizado, o hash de
   `compose.prod.yaml` é o autorizado e as imagens `app` e `web` puxadas **por digest** têm `org.opencontainers.image.revision` = SHA e `source` = este repositório. O resumo da execução lista commit → compose → 4 digests.
   Na VPS, `bulai-deploy` repete a checagem de rótulos e do hash do compose. As tags `sha-<commit>` servem só para localizar as imagens; a ativação usa sempre o digest.

### Quando os revisores obrigatórios não estão disponíveis

*Required reviewers* em Environments é gratuito em **repositórios públicos** (qualquer plano) e, em **repositórios privados**, depende do plano (GitHub Pro/Team/Enterprise). Este repositório é **público** e pertence a uma **conta pessoal**
(verificado: `private: false`, sem Environments criados). Se, mesmo assim, o recurso não estiver disponível (por exemplo, ao tornar o repositório privado em um plano sem o recurso):

- Defina no Environment `PRODUCTION_PROTECTION=actors` e no repositório a variable `DEPLOY_ALLOWED_ACTORS` (usuários separados por vírgula). O deploy passa a exigir que o executor esteja na lista (conferido em `authorize`, `release` e `operate`) e emite um **aviso**.
- Isso é um **controle compensatório, mais fraco**: não há segunda pessoa aprovando. Quem tem permissão de escrita no repositório pode alterar o workflow em outra branch; os segredos do Environment só são liberados para as branches permitidas
  (*Deployment branches* = `main`, que funciona sem revisores), então mantenha essa restrição e a proteção da `main` (PR + CI + CODEOWNERS), limite quem tem permissão de escrita e use 2FA.
- Sem Environments disponíveis, **não** use *repository secrets* para a chave SSH: qualquer branch poderia lê-los. Prefira o acesso restrito do lado da VPS (comando forçado, hash do compose, digests do GHCR), que continua valendo.
- Imagens do GHCR: confira em *Packages* a visibilidade dos pacotes `bulai-*` (o repositório é público; pacotes públicos não exigem login na VPS, privados exigem o `docker login` somente leitura).

## 5. Primeiro deploy

0. **Antes do primeiro deploy**, confirme que não existe outro projeto Compose chamado `bulai` na VPS (o deploy usa `--remove-orphans` nesse projeto):
   `docker ps -a --filter label=com.docker.compose.project=bulai` deve vir vazio.
1. Mescle o PR de infraestrutura na `main` (CI verde) e rode **Actions → Deploy (KingHost) → Run workflow**: `action = deploy`, `commit = <SHA completo da main>`.
   O workflow valida o commit (ancestral da `main`, CI verde), **espera a aprovação do Environment**, publica as 4 imagens e aciona `check` e `activate` na VPS usando os digests recém-publicados.
2. A VPS: confere o hash do compose, os arquivos de ambiente, os modelos (IA local **e** embeddings, se declarado em `model.env`), o espaço; baixa e verifica as imagens; faz backup (se o banco já existe);
   sobe o PostgreSQL; roda `php artisan migrate --force`; recria os containers aguardando os *healthchecks*; roda o *smoke test*; grava a release como ativa.
   Falha **antes** de aplicar: nada muda. Falha **depois**: restaura a release anterior (se existir) e termina com código 30.
3. Crie o administrador e **remova a senha do arquivo**:

   ```bash
   sudo bulai-deploy seed-admin        # idempotente; usa BULAI_ADMIN_* de app.env
   sudo editor /srv/bulai/shared/env/app.env   # apague BULAI_ADMIN_PASSWORD
   ```

   A senha continua no ambiente dos containers atuais até serem recriados: faça um novo deploy (ou `rollback`) em seguida para removê-la do ambiente em execução.

   O admin do seed é o acesso de recuperação: grava o e-mail em minúsculas e fica sempre **ativo e sem troca de senha obrigatória** (rodar `seed-admin` de novo reativa a conta). Os demais acessos são criados em **Admin → Usuários**, com senha provisória exibida uma única vez e troca obrigatória no primeiro login.

   A migration `add_account_state_to_users_table` normaliza os e-mails de `users` para minúsculas e **aborta sem alterar nada** se houver dois cadastros com o mesmo e-mail ignorando maiúsculas (a mensagem lista só os ids). Nesse caso, unifique ou altere os cadastros indicados e repita o deploy.

4. **Valide antes de mexer no Caddy** (a página temporária continua no ar):

   ```bash
   curl -i http://127.0.0.1:8080/up
   curl -i -H 'Host: bulai.vixforge.com.br' -H 'X-Forwarded-Proto: https' http://127.0.0.1:8080/login
   sudo bulai-deploy status
   ```

5. **Caddy** (manual, preservando TLS/Cloudflare): instale `docker/production/caddy/cloudflare-ips.caddy` em `/etc/caddy/bulai/cloudflare-ips.caddy`,
   substitua o conteúdo da página temporária pelo bloco `docker/production/caddy/bulai.caddy` mantendo as diretivas `tls`/`log` atuais,
   valide e recarregue: `caddy validate --config /etc/caddy/Caddyfile && sudo systemctl reload caddy`. Para voltar atrás, restaure o bloco anterior e recarregue.
   O Caddy só atende tráfego vindo do Cloudflare (o restante recebe 403) e envia ao Nginx o IP real e o esquema.
6. Defina a variable `PUBLIC_HEALTHCHECK_URL=https://bulai.vixforge.com.br/up` para os próximos deploys.
7. Importe o catálogo ANVISA em **Admin → Catálogo ANVISA** (upload do CSV; roda na fila) e as interações DDInter em **Admin → Interações**; depois gere o catálogo de princípios ativos: `sudo bulai-deploy artisan knowledge:build-ingredients`.
8. **IA e RAG (uma vez por ambiente):** preencha `AI_TEXT_DRIVER=anthropic`, `ANTHROPIC_API_KEY`, `ANTHROPIC_MODEL` e os orçamentos em `app.env` (ver `docker/production/env/app.env.example`); confirme que `AI_EMBEDDING_DRIVER=openai_compatible`, `AI_EMBEDDING_MODEL=bge-m3` e `BULAI_RETRIEVAL_MODE=hybrid` estão lá; rode `sudo bulai-deploy ensure-model` (baixa e valida o bge-m3 por SHA-256) e, com as bulas já processadas, `sudo bulai-deploy artisan knowledge:index-embeddings` (incremental; repita após novas ingestões ou agende). Verifique com `sudo bulai-deploy artisan bulai:pipeline-check`.
9. **Pós-deploy (toda release):** `php artisan migrate --force` já roda no deploy (migrations novas: `patient_care_and_answer_logs`, `interaction_consultations`, `patient_profile`, `active_ingredients`, `ai_usage_logs`); confira `sudo bulai-deploy status`, `sudo bulai-deploy artisan bulai:pipeline-check` (bulas READY, ingredientes ligados, DDInter, embeddings, provedores) e o agendador (serviço `scheduler`, ver [Agendador e lembretes](#agendador-e-lembretes): `treatment:send-reminders` a cada 5 min e `consultations:purge-expired` às 03:30).

### Agendador e lembretes

O serviço `scheduler` (mesma imagem `bulai-app`, comando `php artisan schedule:work`) executa o agendador do Laravel a cada minuto dentro do container; `onOneServer()` usa o lock do Redis, então um segundo agendador não duplica envios. Ele é obrigatório desde a release com lembretes de dose (`docs/notificacoes.md`): sem ele, os avisos de horário e o aviso de fim de tratamento nunca são gravados.

- **Esta release exige rebuild e novo deploy da imagem `bulai-app`** (código novo + migrations `treatment_schedule_tables` e `notifications`) e a cópia do `compose.prod.yaml` atualizado para `/opt/bulai/trusted` (novo serviço `scheduler`). `php artisan migrate --force` roda no deploy.
- Variáveis em `app.env` (ver `docker/production/env/app.env.example`): `BULAI_TREATMENT_TIMEZONE`, `BULAI_REMINDER_LEAD_MINUTES`, `BULAI_DOSE_LATE_AFTER_MINUTES`, `BULAI_REMINDER_QUEUE`. Para o lembrete **por e-mail** (opcional, escolhido pelo paciente em Avisos) `MAIL_MAILER`/`MAIL_*` precisam estar configurados; sem isso só o aviso no aplicativo é gravado.
- Conferir: `sudo bulai-deploy logs scheduler 50` deve mostrar `Running ['artisan' treatment:send-reminders]` a cada 5 minutos; `sudo bulai-deploy artisan treatment:send-reminders` executa uma rodada na hora e imprime os totais.
- Os avisos no navegador só aparecem com o aplicativo aberto (sem Web Push); não há nada a configurar no servidor para isso.

## 6. Deploy de rotina, rollback e status

- **Deploy:** Actions → *Deploy (KingHost)* → `deploy` + SHA da `main`; o job `release` espera a aprovação do *Environment* antes de publicar qualquer imagem.
- **Rollback:** Actions → `rollback` (ou `sudo bulai-deploy rollback` na VPS). Reaplica as **imagens** da release anterior; **não reverte o banco**.
- **Status:** Actions → `status`, ou `sudo bulai-deploy status`.
- **Códigos de saída:** `0` ok · `10` pré-requisito · `20` compose instalado difere do commit · `30` falhou e restaurou a anterior · `31` falhou sem anterior para restaurar · `32` falhou e a restauração também falhou (intervenção manual) · `40` outro deploy em andamento.
- Cada deploy grava `/var/log/bulai-deploy.log` (rotação mensal) e o manifest da release em `/var/lib/bulai-deploy/releases/<sha>/manifest.txt` (somente root). O script ignora HUP/PIPE/INT/TERM durante `activate`/`rollback`:
  uma queda do SSH não interrompe a publicação no meio (acompanhe o andamento em `/var/log/bulai-deploy.log`).
- `check` valida o hash do compose, arquivos de ambiente, diretórios, espaço, modelo e `compose config`; **não** baixa imagens nem confere rótulos (isso é feito no `activate`). O `rollback` não reconfere o hash do compose: ele reaplica as imagens anteriores com o compose instalado hoje.
- Se o PostgreSQL estiver parado com uma release ativa, o `activate` recusa (código 31): sem backup não há migration.

### Compatibilidade de schema (leia antes de escrever migrations)

O rollback troca código/imagens, não o schema. Por isso **toda migration deve ser compatível com a versão anterior do código** (padrão *expand/contract*):
adicionar colunas/tabelas anuláveis ou com *default* primeiro; só remover/renomear em uma release posterior, depois que nenhuma versão ativa dependa do item.
Migrations destrutivas (`drop`, `rename`, mudança de tipo) exigem backup verificado e janela de manutenção; o deploy **nunca** executa `migrate:fresh`/`migrate:reset`/`db:wipe`.
Cada deploy faz um `pg_dump -Fc` antes de migrar (`/srv/bulai/backups/db/bulai-<data>-pre-<sha>.dump`; 10 mais recentes por tipo: `pre-*` dos deploys e `manual` dos backups sob demanda/agendados). Os backups não saem da VPS: copie-os para fora.

Para restaurar o banco a um ponto anterior (último recurso, causa perda dos dados posteriores): ver [Backup e restauração](#8-backup-e-restauração).

## 7. Operação

```bash
sudo bulai-deploy status                       # release ativa e saúde dos containers
sudo bulai-deploy logs worker 200              # últimas linhas (web|app|worker|scheduler|postgres|document-ocr|local-ai|embeddings)
sudo bulai-deploy artisan queue:failed         # artisan na release ativa
sudo docker stats --no-stream                  # uso de CPU/RAM por container (compare com os limites)
sudo journalctl -u docker --since today        # eventos do daemon (OOM kills aparecem aqui e em `dmesg`)
```

- Logs de aplicação vão para `stderr` (`LOG_CHANNEL=stderr`) → `docker logs`, com rotação `json-file` (10 MB × 5 por container). Nada de conteúdo de PDF/prompts nos logs.
- O cabeçalho do Admin mostra a saúde da IA local e do OCR; `docker compose ps` mostra os *healthchecks*. Um OCR longo não bloqueia o `/health` do OCR (execução fora do *event loop*).
- **Atualizar o OCR:** o workflow `ocr-image.yml` constrói e testa a imagem (OCR real, token, Trivy) quando algo em `services/document-ocr/` muda e todo domingo/segunda faz um canário sem versões fixadas; ele **não publica**. A publicação continua no deploy (após a aprovação do Environment), que reutiliza o cache das camadas do OCR. Para atualizar as versões do Paddle: rode o canário (Actions → *OCR* → *Run workflow* com `use_constraints` desmarcado); se passar, regenere `services/document-ocr/constraints.txt` e abra um PR.
- **Atualizar containers:** é o deploy normal (imagens novas por digest). Imagens-base (PHP, Nginx, Node, Composer, PostgreSQL) ficam fixadas por digest nos Dockerfiles/compose (PHP em `php:8.4.27-fpm-bookworm`; o build da imagem `app` roda `apt-get upgrade` para aplicar correções do Debian publicadas depois da imagem-base);
  atualize-as por PR (compose → reinstalar o conjunto confiável com `install-on-vps.sh`).
- **Alterar o compose, o init do PostgreSQL, `model.env` ou o próprio script:** PR revisado → mesclar → administrador roda `install-on-vps.sh` (mostra o que mudou) → o próximo deploy passa a usar o novo arquivo.
- **Trocar o modelo da IA local ou de embeddings:** altere `docker/production/env/model.env` (URL, SHA-256, arquivo; chaves `LOCAL_AI_*` e `EMBEDDINGS_*`) via PR → reinstalar → `sudo bulai-deploy ensure-model`. Trocar o modelo de embeddings exige reindexar: `sudo bulai-deploy artisan knowledge:index-embeddings --model=<novo nome>` (os chunks guardam modelo e dimensão; a busca vetorial só usa vetores do modelo configurado).
- **Reindexar embeddings após ingestões:** `sudo bulai-deploy artisan knowledge:index-embeddings` (incremental: só chunks novos/alterados). Não há agendamento automático; agende no cron do host se a base mudar com frequência.
- **Custo da IA externa:** `ANTHROPIC_DAILY_USD_BUDGET`/`ANTHROPIC_MONTHLY_USD_BUDGET` em `app.env` bloqueiam chamadas acima do orçamento (`AI_BUDGET_EXCEEDED`, a interface mostra só os trechos de bula). Consumo em **Admin → Analytics** e na tabela `ai_usage_logs`.
- **Rotação de segredos:** `APP_KEY` (invalida sessões e dados cifrados); `OCR_API_TOKEN` e `DOCUMENT_OCR_TOKEN` (alterar **nos dois** arquivos);
  senha do banco: `sudo docker exec -it bulai-postgres-1 sh -c 'psql -U "$POSTGRES_USER" -d bulai'` → `ALTER ROLE bulai PASSWORD '<nova>';`, e atualize `DB_PASSWORD` (app.env)
  e `BULAI_DB_PASSWORD` (postgres.env). Depois rode um deploy (ou `rollback`/reaplicação) para recriar os containers com os valores novos.
- Caso adote a **fila** com mais carga: aumentar `worker` para 2 só se o OCR/IA estiverem ociosos; reavalie os tetos de memória antes.

## 8. Backup e restauração

**O que é preciso preservar:** banco (`/srv/bulai/shared/postgres` — via dump), arquivos privados (`/srv/bulai/shared/storage`), segredos (`/srv/bulai/shared/env`),
modelo (reconstruível por `ensure-model`) e `/var/lib/bulai-deploy` (estado dos deploys).

```bash
sudo bulai-deploy backup                                   # dump do PostgreSQL sob demanda
sudo tar -C /srv/bulai/shared -czf /srv/bulai/backups/storage-$(date -u +%Y%m%d).tar.gz storage   # arquivos privados
```

Agende (por exemplo, `cron` do root) um backup diário e **copie para fora da VPS**; teste a restauração periodicamente:

```
17 3 * * * /usr/local/sbin/bulai-deploy backup >>/var/log/bulai-deploy.log 2>&1
```

**Restaurar o banco** (a aplicação deve estar parada para evitar gravações; os dados posteriores ao dump serão perdidos):

```bash
sudo docker stop bulai-worker-1 bulai-app-1 bulai-web-1
sudo docker exec -i bulai-postgres-1 sh -c 'dropdb -U "$POSTGRES_USER" --if-exists bulai_restore && createdb -U "$POSTGRES_USER" -O bulai bulai_restore'
sudo docker exec -i bulai-postgres-1 sh -c 'pg_restore -U "$POSTGRES_USER" -d bulai_restore --no-owner --role=bulai' < /srv/bulai/backups/db/<arquivo>.dump
# confira os dados em bulai_restore; para substituir o banco: renomeie bulai → bulai_old e bulai_restore → bulai, depois `sudo bulai-deploy rollback`/novo deploy
```

(Os nomes dos containers seguem `bulai-<serviço>-1`; confirme com `sudo docker ps`.) Para restaurar **arquivos**, extraia o `tar` em `/srv/bulai/shared/` com o stack parado e mantenha o dono `10010:10010`.
Para recuperar o código: o digest de cada release fica em `/var/lib/bulai-deploy/releases/<sha>/manifest.txt`; um novo deploy dessa release a reativa.

## 9. Solução de falhas comuns

| Sintoma | Causa provável | O que fazer |
|---|---|---|
| Deploy termina com **20** | `compose.prod.yaml` do commit difere do instalado | revise o diff, rode `install-on-vps.sh` e repita |
| **10** “arquivo de ambiente ausente / permissão insegura” | `app/postgres/ocr.env` ausente ou fora de `0600 root` | `sudo bulai-deploy init-secrets`; `chmod 600` |
| **10** “modelo da IA local ausente” | modelo não instalado | `sudo bulai-deploy ensure-model` |
| **10** “imagens não passaram na verificação” / `denied` no pull | login do GHCR ausente/expirado ou rótulo de revisão divergente | `docker login ghcr.io`; confira o commit autorizado |
| **30** | release nova falhou (healthcheck/smoke/migration) e a anterior foi restaurada | `sudo bulai-deploy logs app 200`, `… worker`, corrija e reenvie; migrations já aplicadas permanecem |
| **31** na primeira implantação | não há release anterior | `sudo bulai-deploy logs <serviço>`; corrija a causa e rode o deploy de novo (idempotente) |
| `local-ai` *unhealthy* / `MODEL_NOT_INSTALLED` | modelo ausente ou corrompido; RAM insuficiente | `ensure-model`; `docker logs bulai-local-ai-1`; confira `docker stats` |
| `document-ocr` *unhealthy* logo após subir | carregamento dos modelos; ou OOM | aguarde `start_period`; `dmesg | grep -i oom`; reduza o tamanho dos PDFs |
| 413 em upload | limite do Caddy/Nginx/PHP (100 MB por lote) | os três já estão em 110 MB; reduza o lote |
| Worker parado / jobs presos | job > 450 s ou worker reiniciado | `sudo bulai-deploy logs worker`; `queue:failed`; `REDIS_QUEUE_RETRY_AFTER` (fixo em 900 no compose) deve ser > 450; se o Redis reiniciou, use "Reenfileirar parados" em Fila e serviços |
| `Permission denied` em `storage` | dono incorreto de `/srv/bulai/shared/storage` | `sudo chown -R 10010:10010 /srv/bulai/shared/storage` (com o stack parado); o `check` do deploy também aponta divergências de dono/modo |
| 502 no Nginx | `app` sem socket (container reiniciando) | `sudo bulai-deploy logs app`; confira `/srv/bulai/shared/run` (`2750 10010:10030`) |
| Disco cheio | imagens/logs/backups acumulados | `sudo bulai-deploy prune`; remova backups antigos; `docker system df` |

## 10. Verificações realizadas

Executadas **localmente** (Docker 28 / Compose 2.38, não na VPS):

- `docker compose -f compose.yaml config` (desenvolvimento) e `compose.prod.yaml config` válidos; só o `web` publica porta (`127.0.0.1:8080`); todos com `cap_drop ALL`, limite de memória e redes `db`/`ai` internas; OCR/IA sem acesso ao banco.
- Build das imagens `app` (PHP 8.4, extensões de `composer check-platform-reqs`), `web`, `document-ocr` (com modelos embutidos) e `local-ai`.
- Stack de produção completo em Docker: `postgres` (papel sem superusuário + `vector`), migrations, `app`/`worker`/`web` não-root com FS somente leitura, OCR e IA sob `cap_drop ALL`,
  upload real de PDFs pelo Nginx → fila → classificação com OCR/IA; limites de memória/CPU conferidos com `docker inspect`; bloqueio de saída nas redes internas.
- `bulai-deploy` exercitado contra o Docker real (modo de teste): `init-secrets`, `check`, `activate`, `seed-admin`, `backup`, **release ruim com restauração automática (código 30)**, nova release e `rollback`.
- `scripts/deploy/tests/run.sh`: 67 asserções (validação de argumentos e injeção, hash do compose, ordem *backup → migrate → up*, rollback automático, lock, comando forçado do CI).
- ShellCheck sem alertas (nível `warning`); `actionlint` sem erros; bloco do Caddy validado com a imagem `caddy:2.6.2`; `gitleaks` sem vazamentos.

## 11. Limitações e pontos a validar na VPS

- **Nada foi executado na VPS.** Valide: versão real do Docker/Compose (29.x / v5), cgroup v2 + *swap accounting* (`memswap_limit`), `tmpfs` com `uid/gid`, política de IP da bridge do Docker (o Nginx confia em `172.16/12`, `10/8`, `192.168/16`).
- O deploy exige que o **GHCR** esteja acessível à VPS e que o pacote `bulai-*` aceite *pull* com o token configurado. A primeira execução baixará ~3 GB (OCR 1,8 GB) e o modelo (~1,1 GB).
- `app` e `worker` agora têm saída para a internet pela rede `egress` (API da Anthropic). Não há filtro de destino no compose: um comprometimento do PHP poderia falar com qualquer host; mitigue no firewall do host (permitir apenas `api.anthropic.com` a partir da sub-rede `bulai_egress`).
- O serviço `embeddings` não foi executado na VPS; foi validado localmente (compose de dev, imagem idêntica) com indexação de 782 chunks e os experimentos de `docs/evaluation.md`.
- Durante um OCR longo o `/health` do OCR continua respondendo, mas o Laravel marca “IA/OCR indisponível” se o próprio serviço estiver reiniciando. O *healthcheck* do worker só confirma que o processo `queue:work` existe, não que consome a fila.
- O allowlist de IPs do Cloudflare no Caddy não impede que **outro cliente do Cloudflare** aponte o próprio domínio para o IP de origem. Para fechar isso, use *Authenticated Origin Pulls* (mTLS), Cloudflare Tunnel ou um segredo de cabeçalho conferido no Caddy (não implementado).
- O CI varre com Trivy apenas a imagem `app` (severidade CRÍTICA, sem pendências sem correção); `web`, `ocr` e `local-ai` e os digests publicados pelo deploy não são varridos. O build não é bit a bit reprodutível (`apt` e `pip` sem versões/hashes fixos; modelos do PaddleOCR baixados no build sem checksum).
- Concorrência do workflow de deploy: execuções simultâneas são serializadas, mas, havendo duas na fila, a mais antiga pendente é descartada (um `rollback` pendente pode sumir); confira o resultado.
- A restauração do banco é manual (procedimento acima); recomenda-se testá-la em ambiente de ensaio. Não há alerta externo de monitoramento/uptime configurado.
- O rollback não reverte migrations (ver compatibilidade de schema). Migrations destrutivas exigem procedimento próprio.
- O estado de `bulai-internal` na VPS (se é `--internal`, quem mais está nela) não foi inspecionado; por isso não é usada.
- Imagens-base fixadas por digest envelhecem: agende revisão mensal (Dependabot/Renovate para Docker e GitHub Actions é recomendado, **não configurado** aqui).

## Referência de secrets, variables e arquivos

| Onde | Nome | Conteúdo |
|---|---|---|
| GitHub *secret* (Environment `production`) | `KINGHOST_SSH_PRIVATE_KEY` | chave privada ed25519 exclusiva do CI |
| GitHub *variable* | `KINGHOST_HOST` | host SSH da VPS |
| GitHub *variable* | `KINGHOST_SSH_KNOWN_HOSTS` | linha(s) de `known_hosts` da VPS (host key fixada) |
| GitHub *variable* (Environment) | `PRODUCTION_PROTECTION` | `reviewers` (aprovação configurada) ou `actors` (controle compensatório); sem ela o deploy não inicia |
| GitHub *variable* (repositório) | `DEPLOY_ALLOWED_ACTORS` | usuários autorizados a executar o workflow (obrigatória no modo `actors`) |
| GitHub *variable* (opcional) | `KINGHOST_SSH_PORT`, `KINGHOST_SSH_USER`, `PUBLIC_HEALTHCHECK_URL` | porta, usuário (padrão `bulai-ci`), URL pública de verificação |
| VPS `/srv/bulai/shared/env/app.env` | `APP_KEY`, `DB_PASSWORD`, `DOCUMENT_OCR_TOKEN`, `BULAI_ADMIN_*`, `ANTHROPIC_API_KEY`, `AI_*`, `BULAI_RETRIEVAL_MODE`, … | ver `docker/production/env/app.env.example` |
| `/opt/bulai/trusted/model.env` | `LOCAL_AI_MODEL_*`, `EMBEDDINGS_MODEL_*` | modelos GGUF (nome, URL, SHA-256) instalados por `bulai-deploy ensure-model` |
| VPS `/srv/bulai/shared/env/postgres.env` | `POSTGRES_USER`, `POSTGRES_PASSWORD`, `BULAI_DB_PASSWORD` | superusuário de manutenção e senha do papel `bulai` |
| VPS `/srv/bulai/shared/env/ocr.env` | `OCR_API_TOKEN` | igual a `DOCUMENT_OCR_TOKEN` |
| VPS `/root/.docker/config.json` | credencial do GHCR | token de leitura de pacotes (somente root) |

Arquivos do repositório: `compose.prod.yaml`, `docker/production/{php,nginx,postgres,caddy,env}/`, `scripts/deploy/{bulai-deploy,bulai-ci-entry,install-on-vps.sh,sudoers-bulai-ci,tests/run.sh}`,
`.github/workflows/{ci,deploy}.yml`, `.github/CODEOWNERS`, `.dockerignore`, `.gitleaks.toml`.

## Autenticação: hash Argon2id e redefinição de senha por e-mail

No `app.env` (ver `docker/production/env/app.env.example`):

- `HASH_DRIVER=argon2id` (padrão do código). Parâmetros opcionais `HASH_ARGON_MEMORY` (KiB, 65536), `HASH_ARGON_THREADS` (1), `HASH_ARGON_TIME` (4). Hashes bcrypt existentes migram no próximo login; quando não restar nenhum, `HASH_ARGON_VERIFY=true` passa a recusar hashes que não sejam argon2id. Custo por hash: ~60 ms com 64 MiB (ajuste `HASH_ARGON_MEMORY` se o PHP-FPM tiver pouca memória; `memory_limit` precisa comportar o valor).
- "Esqueci minha senha" e envio opcional da senha provisória exigem um remetente real: `MAIL_MAILER=smtp`, `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_ENCRYPTION`, `MAIL_FROM_ADDRESS`, `MAIL_FROM_NAME`. Sem isso os e-mails caem no log (`MAIL_MAILER=log`) e o usuário nunca recebe o link.
- A política de senha em produção consulta o serviço Have I Been Pwned (k-anonimidade) para recusar senhas vazadas; app/worker precisam da rede `egress` já prevista para a API da Anthropic.
- Verifique após o deploy: `php artisan tinker --execute 'echo config("hashing.driver");'` e um login real (o hash do usuário passa a começar com `$argon2id$`).
