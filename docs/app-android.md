# Aplicativo Android nativo (APK)

## Propósito

O BULA-IA instalado no celular como **aplicativo nativo**: telas em Kotlin + Jetpack Compose (Material 3), falando com o servidor por uma API REST própria (`/api/mobile/v1`), e o que só o aparelho faz bem: **alarme na hora exata de cada dose, mesmo sem internet, com o celular bloqueado e depois de reiniciar**, "Tomei", "Pular" e "Adiar" na notificação, modo despertador em tela cheia, widget e atalhos.

Não há WebView: as regras de negócio continuam no Laravel (os mesmos services que as telas Livewire usam) e o app só desenha o JSON.

## Etapas

| Etapa | Conteúdo | Situação |
| --- | --- | --- |
| 1. Fundação | API de conta; Entrar, Cadastro, Esqueci a senha, Primeiro acesso; menu inferior; Início; Remédios do dia (ontem/hoje/amanhã); Avisos (central, lembretes, alarmes do celular); WebView removido | **feita** |
| 2. Tratamento | Bula humanizada (bula simples) | **feita** (antecipada) |
| 2. Tratamento | Meus remédios (consentimento, remédios do catálogo ou pelo nome, "Dose e horários", receitas, alergias, apagar dados de saúde), lista de Bulas | **feita** |
| 3. Consultas | Perguntar (conversa, ditado, "Ouvir", correção do remédio, histórico) | **feita** (antecipada) |
| 3. Consultas | Interações (lista com os remédios em uso, alerta de alergia, resultado acompanhado, histórico) | **feita** |
| 4. Restante | Receita por foto (câmera ou galeria, leitura, revisão, confirmação) | **feita** (antecipada) |
| 4. Restante | Conta (nome de chamada, tema, lembretes, alarmes, senha, apagar conta) | **feita** |
| 4. Restante | Cuidadores com QR Code (Quem cuida de mim, Cuidar de alguém, dia de quem a pessoa cuida) e Resumo para o médico (PDF) | **feita** |
| 5. Publicação | assinatura, R8, versionamento, testes instrumentados, política de privacidade e "Segurança dos dados" da Play Store | a fazer |

Enquanto uma tela não existe no app, avisos e atalhos que apontam para ela abrem o Início.

## Arquitetura

```
┌──────────────────────── Aplicativo (mobile/android) ────────────────────────┐        ┌──────── Servidor Laravel ────────┐
│ MainActivity (Compose) ─ AppBulai: sessão → Conta | Primeiro acesso | Paciente│        │ /api/mobile/v1 (JSON)            │
│   telas (ui/*) ─ ViewModel (StateFlow) ─ Repositórios ─ Retrofit/OkHttp ──────┼─HTTPS─►│  entrar, cadastro, esqueci-senha │
│                         │                   │                                │ Bearer │  eu, primeiro-acesso, aparelho   │
│                         ▼                   ▼                                │        │  inicio, dia, dia/doses          │
│                                                                               │        │  perguntar, perguntas, remedios  │
│                                                                               │        │  receitas (+ horarios, confirmar)│
│                CacheEmDisco (última resposta)   Sessao (token no Keystore)   │        │  avisos (+ novos, lidos, …)      │
│                                                                               │        │  agenda, doses (alarmes)         │
│ Sincronizacao ─ SincronizarWorker / MarcarDoseWorker (ApiClient) ─────────────┼───────►│                                  │
│ AgendadorDeAlarmes (AlarmManager) ► AlarmeReceiver ► Notificacoes / AlarmeActivity      └──────────────────────────────────┘
│ ReinicioReceiver · ProximaDoseWidget · atalhos · VideoDeAbertura              │
└───────────────────────────────────────────────────────────────────────────────┘
```

1. **Entrar**: e-mail e senha em `POST /entrar` com o `installation_id` do aparelho; o servidor devolve o token `bulai_app_…` (o banco guarda só o sha256). O token fica cifrado com uma chave AES-GCM do Android Keystore (`dados/Cofre.kt`).
2. **Senha provisória**: com `must_change_password`, só `eu`, `primeiro-acesso` e `aparelho` respondem; o resto dá 403 `{code: "must_change_password"}`. O app mostra a tela de troca; a troca mantém este aparelho conectado e derruba as sessões do site e os outros aparelhos.
3. **Conta liberada**: `Sessao` liga a sincronização dos alarmes (`Sincronizacao.conectar`), que busca a agenda de hoje e amanhã e agenda um alarme exato por dose. Sincroniza a cada 30 minutos, ao abrir o app e depois de cada marcação ou mudança de lembrete feita nas telas.
4. **Telas**: cada tela abre com a última resposta guardada (`CacheEmDisco`) ou com o esqueleto de carregamento, e atualiza por cima. Puxar para baixo atualiza; voltar para a tela (ON_RESUME) também. Ações (Tomei, Pular, Desfazer, preferências, apagar aviso) mudam a tela na hora e se confirmam com o servidor; se falharem, voltam ao estado anterior e o snackbar explica. Tomei/Pular oferecem "Desfazer" no snackbar.
5. **Token recusado (401)** em qualquer chamada: o app cancela os alarmes, apaga os dados locais e volta para Entrar.
6. **Sair**: menu ⋮ › Sair da conta; avisa o servidor (`DELETE /aparelho`) e limpa o aparelho.

## API `/api/mobile/v1`

Respostas em JSON com `Cache-Control: no-store`; erros no formato do Laravel (`message`, e `errors` por campo no 422). Rotas em `routes/api.php`, controladores em `app/Http/Controllers/Api/Mobile`.

| Método e caminho | Uso | Observação |
| --- | --- | --- |
| `POST /entrar` | `{email, password, installation_id, device_name?, app_version?}` → `{token, user}` | throttle `mobile-login` (5/min e 20/15 min por e-mail + IP, resposta 429 em JSON). Mesmas mensagens do site: a de cadastro pendente/recusado/desativado só aparece para quem acertou a senha. Entrar de novo na mesma instalação troca o token |
| `POST /cadastro` | `{name, email, password, password_confirmation, terms}` → 201 `{message}` | throttle `mobile-register`; conta nasce aguardando aprovação, sem token |
| `POST /esqueci-senha` | `{email}` → `{message}` | throttle `mobile-password-reset`; mesma resposta exista ou não o e-mail |
| `GET /eu` | `{user}` | também com senha provisória pendente |
| `POST /primeiro-acesso` | `{password, password_confirmation}` → `{user}` | senha nova diferente da provisória |
| `DELETE /aparelho` | desconecta este aparelho | auditado |
| `GET /inicio` | saudação, data, consentimento, próxima dose (com `since`: a dose anterior do dia ou o começo do dia, para a barra de espera), progresso do dia, avisos não lidos, pessoas cuidadas | `App\Services\Treatment\PatientDayService::home` |
| `GET /dia?dia=-1\|0\|1` | resumo e doses do dia (com `period` manhã/tarde/noite, `adjusted_from` dos remédios dinâmicos) e remédios "quando precisar" | `PatientDayService::day` |
| `POST /dia/doses` | `{medication_id, scheduled_for, status: taken\|skipped, dia}` → dia atualizado | remédio de outra pessoa → 404 |
| `DELETE /dia/doses` | `{medication_id, scheduled_for, dia}` → dia atualizado | desfaz a marcação |
| `GET /avisos` | central: `unread`, preferências (`reminders`, `email`, `timezone`), fusos, até 30 avisos com `when`, `action`, `read` e `destination` `{route, params}` | `params` sempre objeto JSON |
| `POST /avisos/{id}/abrir` | marca como lido e devolve `destination` | |
| `POST /avisos/lidos`, `DELETE /avisos`, `DELETE /avisos/{id}` | marcar todos como lidos, apagar todos, apagar um | devolvem a central |
| `PUT /avisos/preferencias` | `{reminders?, email?, timezone?}` | só com consentimento; fuso só da lista do Brasil |
| `GET /avisos/novos?desde=ISO` | avisos não lidos novos (até 24 h, 20 itens) para notificações do sistema | usado pelo `SincronizarWorker` |
| `GET /remedios?q=` | busca no catálogo (`MedicationSearchService`, até 10) | correção do remédio no Perguntar e na receita |
| `GET /perguntar?medicamento=` | exemplos, remédios em uso, limites de tamanho, retenção e, vindo de um remédio, o painel "No seu tratamento" | |
| `POST /perguntar` | `{question, fixed?, removed?, turns?}` → `{answer}` | `turns` são só os `uuid` das trocas anteriores; o contexto é relido do histórico da própria pessoa (uuid de outra pessoa é ignorado). Mesmo limite por hora do site (429 em JSON). Resposta com `paragraphs` em trechos `{text, bold}`, `alert`, `source`, `suggestions`, `guesses`, `corrections`, `medicines`, `sources`, `treatments`, `crisis`, `help_lines` (telefones de ajuda `{tel, number, name, detail}` do caso: crise, superdose, sintoma grave, pedido recusado por risco; `QuestionConversation::helpLines`, o mesmo do site), `releases_fixed` |
| `GET /perguntas`, `GET /perguntas/{uuid}`, `DELETE /perguntas/{uuid}` | histórico paginado (20), uma pergunta no formato do balão, apagar (auditado) | de outra pessoa → 404 |
| `POST /receitas` | multipart `photo` (JPG/PNG/WebP, até `bulai.prescription.max_image_kilobytes`) → 201 `{prescription}` | sem consentimento → 409 `health_consent_required`; limite por hora do site → 429 |
| `GET /receitas/{id}` | `status`, `reading`, `error_message`, `legibility`, `ignored_text`, `header` e `items` (com horários sugeridos) | o app consulta a cada 2 s enquanto `reading`; leitura parada vira `PRESCRIPTION_TIMEOUT` |
| `POST /receitas/horarios` | `{frequency}` → horários sugeridos (`DoseScheduleParser`) | ao corrigir "quando tomar" |
| `POST /receitas/{id}/confirmar` | `{header: {prescriber, registration?, date?}, items: [...]}` → `{confirmed, unmatched, message}` | mesmas validações e mensagens do site; fora de revisão → 409 |
| `DELETE /receitas/{id}` | descarta a receita não confirmada | |
| `GET /bulas/{medicine}` | bula humanizada na hora: `state` (`pronto`, `carregando`, `sem_bula`, `nao_identificado`, `limite`), `cover` ("Para que serve"), `sections` (`key`, `label`, `tone`, até 3 `claims`, `sources`), `missing`, `prepared`, `risk` (frase de alergia/interação) e `treatment` | não gera nada; com `carregando` o app mostra o esqueleto e chama o POST |
| `POST /bulas/{medicine}/gerar` | prepara (ou reaproveita) o resumo e devolve o mesmo formato | mesmo limite por hora do site para gerar um resumo novo |
| `GET /conta` | dados da conta (com `preferred_name`) | |
| `PUT /conta/nome` | `{preferred_name}` (até 40, só letras, espaço, apóstrofo, ponto, hífen; vazio volta ao primeiro nome) → `{user}` | auditado (`preferred_name_changed`, de/para); o `first_name` de `/eu`, `/inicio` e a saudação do site passam a usar o nome de chamada (`User::callName()`) |
| `PUT /conta/senha` | `{current_password, password, password_confirmation}` → `{message}` | mesmas regras de Minha conta do site (nova diferente da atual); este aparelho continua conectado, as outras sessões e aparelhos caem; limite `mobile-password-change` (5 em 15 min por conta) |
| `GET /cuidadores` | "Quem cuida de mim": `health_consent`, `invite` (`code` "1234 5678", `url` `/cuidar?codigo=` que vai no QR Code, `expires_at`), `invite_expired`, `pending`/`active` (nome, e-mail, `permissions`), `ended` (até 10), `permission_labels` | `CaregiverController`, regras do `CaregiverLinkService` (as mesmas do site) |
| `POST /cuidadores/convite` · `DELETE /cuidadores/convite` | gera o convite (cancela o anterior; 48 h, uso único) → 201; sem consentimento → 409 `invite_unavailable` · cancela | auditado no vínculo |
| `POST /cuidadores/{link}/aprovar` · `/recusar` · `PUT /cuidadores/{link}/permissoes` · `DELETE /cuidadores/{link}` | `{permissions: {can_receive_alerts, can_view_medications, can_manage_medications}}`; recusar, mudar e tirar acesso → mesmo formato de `GET /cuidadores` | `CaregiverLinkPolicy::manage` (só o paciente; outra pessoa → 403); fora do estado certo → 409 |
| `GET /cuidando` | "Cuidar de alguém": `pending` e `active` (`can_view`, `can_mark`, `health_consent`, frases das permissões) — é a lista em que quem cuida **escolhe a pessoa** | |
| `POST /cuidando` | `{code}`: o texto lido no QR (o link inteiro serve) ou os 8 números → 201 com `message` e a lista | código errado/usado/vencido → 422 em `errors.code`; limite do serviço (5 erros em 15 min) e `throttle:mobile-password-change` |
| `DELETE /cuidando/{link}` | deixar de acompanhar | `CaregiverLinkPolicy::leave` |
| `GET /cuidando/{link}/dia?dia=` · `POST`/`DELETE /cuidando/{link}/dia/doses` | o mesmo formato de `/dia`, com `caring_for` {`link_id`, `first_name`} e `can_mark` | ver: `viewPatient`; marcar/desfazer: `managePatient`, via `CaregiverLinkService::markDose/unmarkDose` (auditoria do vínculo) |
| `GET /perfil` | "Meus remédios": `health_consent`, `medications` e `ended` (com `when`, `schedule` {chips, note, missing} e o formulário `posology`), `allergies`, `prescriptions` | `ProfileController`; regras em `HealthProfileService` (as mesmas do site: `Perfil` e `EditaPosologia` delegam a ele) |
| `POST /perfil/consentimento` · `DELETE /perfil/dados-de-saude` | autoriza (auditado `health_consent_given`) · apaga alergias, remédios, receitas e perguntas e revoga (auditado `health_data_deleted`) | |
| `POST /perfil/remedios` · `PUT /perfil/remedios/{id}` · `DELETE /perfil/remedios/{id}` | `{medicine_id}` ou `{name}` (fora do catálogo vira pendência) · `{posology}` (as regras e frases de validação do site) · tirar | sem consentimento → 409 `health_consent_required`; só os remédios da própria pessoa; respostas com `message` |
| `POST /perfil/receitas` · `POST /perfil/alergias` (e `DELETE` de cada) | receita à mão (data em dd/mm/aaaa ou ISO) · alergia (3 a 120 letras) | |
| `POST /posologia/sugestao` | `{frequency, posology, preset?}` → o formulário com horários sugeridos, "só quando precisar", intervalo e modo (nada é salvo); com `preset: true` (atalho tocado) os horários são refeitos do zero (`HealthProfileService::choosePreset`) | `DoseScheduleParser` |
| `GET /cuidando/{link}/remedios` · `PUT /cuidando/{link}/remedios/{id}` | remédios de quem a pessoa cuida (`can_edit`, `intro`, `posology_presets`) · "Dose e horários" por quem cuida | ver: `viewPatient`; editar: `managePatient`; auditoria `medication_updated` no vínculo |
| `GET /interacoes` | `mine` (remédios em uso já na lista, com alerta de alergia), `max`, `history` | `InteractionController` + `InteractionResultPresenter` (o mesmo do site) |
| `GET /interacoes/remedios/{id}?selected=` | um remédio para a lista; repetido ou acima do máximo → 422 com a frase | `InteractionResultPresenter::additionRefusal` (também no `Pesquisa` do site) |
| `POST /interacoes` · `GET /interacoes/{uuid}` · `POST /interacoes/{uuid}/repetir` · `DELETE /interacoes/{uuid}` | `{medicine_ids}` → 201 (limite por hora → 429 `rate_limited`) · andamento e pares com `level`, `tone`, `phrase`, `badge`, evidências, limitações e fontes · repetir · apagar (auditado) | a frase de cada par é a mesma do site (`InteractionResultPresenter::band`, usada também na view `resultado`) |
| `GET /bulas?q=` | sem `q`: as bulas prontas (filtradas no aparelho); com 3 letras ou mais: o catálogo com `has_leaflet` | `LeafletCatalog` (também no `Bulas` do site); limite de buscas por minuto |
| `GET /remedios?q=&exclude=` | busca no catálogo; `exclude` tira os já escolhidos | |
| `GET /resumo.pdf` | o mesmo PDF "Resumo para o médico" do site | sem consentimento → 409; download auditado (`medical_summary_downloaded`); geração compartilhada em `MedicalSummaryService::download` |
| `DELETE /conta` | `{password}` → `{message}` | apagar a conta (LGPD): `UserAccountService::deleteOwnAccount` apaga remédios, doses, receitas, alergias, perfil, perguntas, consultas, avisos, cuidadores, aparelhos, sessões e push; anonimiza nome, e-mail, senha e IP e desativa o cadastro; a trilha guarda `account_deleted` com as contagens. O e-mail fica livre para novo cadastro. Administrador → 403 `admin_account` |
| `GET /agenda`, `POST /doses` | agenda dos alarmes e marcação pela notificação/despertador/widget (`recorded_at` = hora real do toque, janela de 48 h) | `DeviceAgendaService` |

Autenticação: `App\Http\Middleware\AuthenticateMobileDevice` (Bearer; conta desativada ou não aprovada → 401; senha provisória → 403 fora das rotas `:primeiro-acesso`). Limite geral `mobile-api`: 120/min por aparelho e 300/min por IP. Auditoria: `mobile_device_connected` / `mobile_device_disconnected`. `UserAccountService::revokeAccess()` apaga os aparelhos (encerrar sessões, desativação, redefinição de senha); na troca de senha feita pelo app, o aparelho que trocou continua (`$currentMobileDeviceId`).

Mudanças de apoio no backend: `TreatmentScheduleService::annotateAdjustments()` (antes `Hoje::anotarAjustes`, que agora delega) e `PatientProfile::TIMEZONES` (antes só em `Avisos`).

Regras extraídas dos componentes Livewire para serviços usados pelo site e pela API (o site não mudou):

- `App\Services\Patient\QuestionConversation`: limite por hora, contexto das trocas, troca enxuta, remédios em uso, painel prévio e a apresentação do balão (`speech`, `alert`, `source`, `paragraphs`/`paragraphSegments`, `sources`, `shortSpeech`, `rephrasings`, `listNames`). `Livewire\Consulta\Perguntar` só guarda o estado da tela.
- `App\Services\Patient\PrescriptionReviewService`: envio (área transitória + leitura), expiração da leitura parada, mensagem de falha, cabeçalho e itens para revisão, horários sugeridos e confirmação (validação e gravação no tratamento). `Receita\Nova` e `Receita\Revisar` delegam.
- `App\Services\Leaflet\LeafletPage`: estados da bula humanizada, geração com limite, seções com fontes, estilo das seções e a nota "Baseado na bula oficial…". `Livewire\Consulta\Bula` delega.
- `TreatmentContextService::riskSentence()` (a frase de risco do componente `x-consulta.risco`), `::panel()` (quadro "No seu tratamento" no formato da API) e `::severityLabel()`.
- `AnswerHighlighter::segments()`: o mesmo destaque do HTML em pedaços de texto puro (o HTML agora é montado a partir deles).

## Aplicativo (mobile/android)

| Pasta / arquivo | O que faz |
| --- | --- |
| `MainActivity.kt` | única Activity das telas; splash do sistema, vídeo de abertura e `bulai://abrir/{caminho}` (notificações, widget, atalhos) |
| `BulaiApp.kt` | `Container` com as dependências (sessão, API, repositórios, cache) |
| `dados/api/` | `BulaiApi` (Retrofit), `Modelos` (kotlinx.serialization), `Rede` (OkHttp com token, 401 → sessão encerrada, tradução dos erros para mensagens) |
| `dados/Sessao.kt`, `Cofre.kt`, `Preferencias.kt` | estado da conta (Visitante, TrocarSenha, Conectado), token cifrado no Keystore, preferências locais fora de backup |
| `dados/Repositorios.kt`, `CacheEmDisco.kt` | conta, tratamento e avisos; última resposta de cada tela em `noBackupFilesDir/telas` |
| `ui/tema/` | cores da marca (as do site), modo escuro, tipografia grande (corpo 18 sp, respeita a escala de fonte do sistema) |
| `ui/componentes/` | botões de 56–60 dp, campos com erro por campo e mostrar senha, cartões, faixa de erro com "Tentar de novo", estado vazio, esqueletos com brilho |
| `ui/conta/`, `ui/inicio/`, `ui/dia/`, `ui/avisos/` | telas e ViewModels |
| `ui/perguntar/` | conversa (`PerguntarTela`, `PerguntarViewModel`), balões e painel "No seu tratamento" (`Balao.kt`), busca para corrigir o remédio, Minhas perguntas e pergunta anterior (`HistoricoTela.kt`) |
| `ui/receita/` | foto (câmera pelo app do aparelho via `FileProvider`, ou seletor de fotos do Android), acompanhamento da leitura e revisão editável (quem receitou, data, remédio do catálogo, dose, "quando tomar", horários com seletor de hora, modo por intervalo) |
| `ui/bula/BulaTela.kt` | bula simples: capa com "Para que serve" e "Ouvir", frase de risco, seções recolhíveis por cor (as duas primeiras abertas) com "De onde veio?", nota de preparo, "No seu tratamento" e o botão fixo "Perguntar sobre este remédio"; guardada no aparelho depois de pronta |
| `ui/sistema/Voz.kt` | ditado pelo reconhecedor de voz do sistema (sem pedir permissão de microfone) e "Ouvir" com a voz do Android (pt-BR) |
| `dados/ConsultasRepositorio.kt` | `PerguntasRepositorio`, `ReceitasRepositorio` e `Fotos` (reduz a foto a 2400 px e endireita pelo EXIF antes de subir) |
| `ui/navegacao/` | `AppBulai` (sessão → navegação), menu inferior com contador de avisos, snackbar com "Desfazer", rotas e tradução de `destination`/caminho para tela |
| `ui/sistema/Permissoes.kt`, `alarmes/AjustesDoCelular.kt` | permissões dos alarmes (notificações, hora exata, tela cheia, bateria) e preferências do despertador |
| `alarmes/`, `sync/`, `widget/` | alarmes exatos, notificações com ações, despertador em tela cheia, sincronização em segundo plano e widget (sem mudança de regra) |
| `abertura/VideoDeAbertura.kt`, `res/raw/abertura.mp4` | vídeo de abertura só ao entrar pelo ícone (ver abaixo) |
| `res/drawable/marca_logo.xml` (+ `drawable-night`) | logo do site convertido para vetor, com o texto claro no modo escuro |

Detalhes de experiência:

- **Carregamento**: esqueletos com as proporções reais da tela na primeira abertura; depois, os dados guardados aparecem na hora e a atualização é silenciosa (indicador de puxar para atualizar).
- **Início no formato do site**: saudação (com a pílula "N avisos novos" que abre os avisos), cartão azul do dia com o anel "X/Y DOSES" (fundo claro, avanço em laranja, verde quando completo), "HOJE · X de Y doses tomadas" e o bloco do próximo remédio; abaixo, ações grandes uma por linha: **Perguntar sobre um remédio em laranja**, Remédios de hoje (com o próximo horário), Fotografar minha receita e Ver o que já perguntei.
- **Remédios do dia no formato do site**: linha do tempo com o horário à esquerda, a bolinha no trilho (verde tomada, cinza pulada, vermelha atrasada, laranja na hora, vazada a tomar) e o cartão à direita com nome, dose e o selo do estado ("A tomar", "Agora", "Atrasado", "Pulei", "Tomei 08:21" no fuso do tratamento); "Tomei" verde e largo ao lado de "Pular"; o efeito do Tomei continua o mesmo.
- **Próximo remédio (Início)**: o fundo do cartão enche da esquerda para a direita, de `since` até o horário (atualiza a cada 20 s, com transição suave), e a ponta balança no lugar como líquido mexido (soma de ondas estacionárias de comprimentos e ritmos diferentes, sem deslocar para cima ou para baixo, nunca no mesmo desenho); mostra "Faltam X h Y min". Na hora ou atrasado, cheio. Com as animações do sistema desligadas, sem ondulação.
- **Remédios do dia**: abas Ontem/Hoje/Amanhã com deslize lateral (`HorizontalPager`), cada dia buscado quando aparece; linha do tempo por período; "Tomei" com vibração de confirmação e efeito próprio: o verde opaco nasce num círculo a partir do botão e cobre o cartão inteiro, textos inclusive (~520 ms); o joinha salta no centro com mola, dá um pulinho e balança; depois o véu verde clareia, os textos voltam por cima do fundo verde já com "Tomado", e o joinha some com fade-out (~1,5 s no total). "Desfazer" (ou o servidor recusar) recolhe o verde de volta ao botão. Com as animações do sistema desligadas, o cartão muda direto; o joinha não é lido pelo TalkBack (o estado "Tomado" é anunciado).
- **Acessibilidade**: títulos marcados como cabeçalho, estado das doses lido pelo TalkBack, erros de campo anunciados, "Apagar aviso" como ação de acessibilidade (além do arrastar), alvos de toque ≥ 48 dp, letra grande.
- **Telas largas**: conteúdo limitado a 520–640 dp e centralizado.
- **Tema escuro**: as telas usam só cores do tema (claro e escuro). Atenção (interação, "não encontrado no catálogo", leitura incerta, sem bula) usa o par âmbar `secondaryContainer`/`onSecondaryContainer`; alergia e erro usam `errorContainer`; o símbolo do BULA-IA fica num círculo branco nos dois temas (a marca precisa de fundo claro).
- **Voltar preditivo** (`enableOnBackInvokedCallback`): o gesto de voltar mostra a tela anterior enquanto arrasta, nas telas empilhadas e nas folhas de busca.
- **Autopreenchimento**: e-mail e senhas marcados para o gerenciador de senhas.
- **Perguntar** (aba própria, como no site): a pergunta aparece na hora e o "digitando" fica até a resposta; a caixa de texto acompanha o teclado, que fecha ao enviar; Enter de teclado físico envia. Remédios, componentes e frases de alerta em negrito; alergia → balão vermelho e aviso em destaque; interação → amarelo com a gravidade (cor + texto); telefones de ajuda (`help_lines`) em botões de ligar grandes e em destaque, logo abaixo do aviso de urgência (o primeiro cheio em vermelho; abre o discador). A caixa de texto é uma caixa só, com o texto, o microfone e o botão de enviar juntos; ao enviar, a pergunta sai da caixa e voa até o balão dela na conversa (`VooDaPergunta`: um balão "fantasma" vai do lugar e tamanho da caixa ao do balão, que fica escondido até o pouso; a conversa vai direto ao balão novo), e então aparece "Enviada" (o PWA faz o mesmo efeito). Sugestões em pílulas de 48 dp, "Não é esse remédio?" abre a busca. "Fontes utilizadas" (recolhido) segue o PWA: cada fonte num cartão separado com o título (bula), seção · página e a data da bula; o trecho daquela página usado na resposta fica recolhido e abre ao tocar no cartão (com rolagem própria). A linha "Baseado na bula…" não aparece no balão nem nas fontes; o painel "No seu tratamento" (posologia) também não fica ali. Só a resposta mais recente tem ações; as anteriores ficam como texto.
- **Receita por foto**: etapas Foto → Conferir → Pronto; prévia antes de enviar; leitura acompanhada com esqueleto; revisão com barra fixa "Confirmar e salvar"; ao confirmar, os alarmes do aparelho sincronizam e o app abre Hoje com o resumo no snackbar.
- **Bula humanizada**: no Perguntar e na pergunta anterior, cada remédio de "Falando de:" (com o ícone do livro quando há bula) abre a bula simples; dela, "Perguntar sobre este remédio" abre o Perguntar com o remédio escolhido ("Sobre X:" e o quadro do tratamento), como o `?medicamento=` do site. Primeira abertura: esqueleto enquanto o servidor prepara; depois abre na hora.
- **Conta** (aba do menu inferior): saudação com o nome de chamada; "Como quer ser chamado(a)?"; Aparência (igual ao celular, sempre claro, sempre escuro; guardada no aparelho, muda na hora, inclusive as barras do sistema e o logo); Lembretes (lembrar nos horários, e-mail, fuso); Alarmes neste celular (permissões, despertador, adiar, testar); Senha (recolhida); Minhas perguntas; Sair da conta; Apagar minha conta (aviso com tudo o que será apagado, senha atual e botão vermelho; depois o aparelho é limpo e volta para Entrar).
- **Cuidadores (QR Code)**: em Conta › Quem cuida de mim, o paciente toca em "Gerar QR Code" e mostra a tela para quem vai cuidar dele (o QR é o link `/cuidar?codigo=`, desenhado com ZXing sempre escuro sobre branco; embaixo, o código de 8 números, a validade e "Enviar o link do convite"). Quem cuida abre Conta › Cuidar de alguém › "Ler QR Code" (leitor do Google Play, `play-services-code-scanner`, sem permissão de câmera no app) ou "Digitar o código". O pedido aparece para o paciente com as três permissões marcadas; ele desmarca o que não quer e aprova, recusa, muda depois ou tira o acesso.
- **Escolher de quem ver os remédios**: quem cuida vê as pessoas acompanhadas no Início ("Quem você acompanha", só aparece para quem acompanha alguém) e em Cuidar de alguém; tocar numa pessoa abre o dia dela (`cuidando/{vínculo}`, a mesma tela Hoje com ontem/hoje/amanhã), com o título "Remédios de Fulana" e uma faixa dizendo se pode anotar. Sem a permissão "marcar e editar", os botões somem; com ela, "Tomou"/"Pular" e o snackbar "Anotado: Fulana tomou…". O aviso de dose atrasada da pessoa (`consulta.cuidando.hoje`) abre direto o dia dela.
- **Meus remédios** (Início, Conta, cartão "Autorizar agora" e o aviso de consentimento de Hoje e da receita): autorização dos dados de saúde explicada; abas Remédios, Receitas e Alergias; "Adicionar remédio" busca no catálogo (ou "usar pelo nome") e já abre "Dose e horários"; cartão com ícone, dose, horários em pílulas (ou "A cada 12 h · contando de quando tomar"), botões Horários e Bula; tratamentos encerrados; "Apagar meus dados de saúde" discreto no fim, com confirmação.
- **Dose e horários** (folha inferior, também para quem cuida), em três passos numerados, igual ao PWA: **1. Quanto tomar** (atalhos de dose em pílulas + campo livre); **2. Quando tomar** (atalhos grandes, 2 por linha: 1, 2 ou 3 vezes ao dia, de 8 em 8 h, de 12 em 12 h, só se precisar; "Outro jeito?" abre o texto da receita, que sugere horários pelo servidor); **3. Horários do dia** (cada horário numa linha com ícone e nome do período, Manhã/Tarde/Noite/Madrugada, a hora grande, tocar para mudar no relógio, tirar; em ordem do dia; novo horário sugerido 4 h depois do último; contar a partir de quando tomar ou fixos; lembretes). Datas, receita e observações em "Mais detalhes", recolhido. Os atalhos vêm do servidor (`posology_presets` em `GET /perfil` e nos remédios de quem cuida). Salvar ressincroniza os alarmes do aparelho.
- **Interações**: os remédios em uso já entram; alerta de alergia em cada remédio; "Verificar interações" abre o resultado, acompanhado a cada 2 s até terminar (progresso), com um cartão por par (faixa de cor pela gravidade, selo, a frase do site, "O que a bula diz" recolhido); alerta de alergia no topo; tentar de novo / repetir; consultas anteriores (abrir, apagar). A bula simples tem "Ver interações com os meus remédios".
- **Bula simples (lista)**: bulas prontas filtradas a cada letra; com 3 letras, também o catálogo (avisando se a bula é preparada na hora).
- **Quem cuida**: na faixa do dia da pessoa, "Remédios, dose e horários" abre a lista dela; com a permissão "marcar e editar", cada remédio abre "Dose e horários".
- **Onde ficam as regras**: o que é sensível (permissões, dono dos dados, limites, validações, consentimento, auditoria) fica no servidor e é conferido a cada pedido; o app só antecipa o que melhora a experiência (resumo do dia recalculado no toque e substituído pela resposta; botões que só habilitam com o mínimo preenchido) e mostra as frases que o servidor manda.
- **Resumo para o médico**: em Conta, baixa o PDF do site para `cache/resumos` e abre no leitor de PDF do celular (sem leitor, oferece compartilhar).
- **Avisos**: só a lista de avisos, aberta pelo sininho da barra superior (com o número de não lidos); um link leva aos ajustes de lembretes na Conta.
- **Navegação**: Início, Perguntar, Hoje e Conta no menu inferior; sininho dos avisos na barra superior; histórico e receita são telas empilhadas (botão voltar, sem o menu). Atalhos do ícone: Hoje, Perguntar e Fotografar receita; `bulai://abrir/receitas/{id}/revisar` abre uma receita em revisão.

Versões: AGP 9.5.1, Kotlin 2.4.21 (plugins Compose e serialization), Gradle 9.9.1, `compileSdk 37`, `targetSdk 36`, `minSdk 26`. Dependências: AndroidX core-ktx, activity-compose, work-runtime-ktx, core-splashscreen, browser, navigation-compose 2.10.2, lifecycle 2.11.0; Compose BOM 2026.09.00 (Material 3 e ícones); kotlinx-coroutines 1.11.0; kotlinx-serialization 1.13.0; Retrofit 3.0.0 + conversor kotlinx; OkHttp 5.5.1; ZXing core 3.5.3 (gerar o QR) e Google Code Scanner 16.1.0 (ler o QR). Testes: JUnit 4, org.json, coroutines-test, MockWebServer.

Permissões: internet; notificações; `SCHEDULE_EXACT_ALARM`; `USE_FULL_SCREEN_INTENT`; vibração; iniciar com o aparelho; pedido para ignorar a economia de bateria. **Sem** permissão de câmera ou microfone: a foto usa o app de câmera do aparelho (`ACTION_IMAGE_CAPTURE` gravando em `cache/fotos` pelo `FileProvider` `${applicationId}.arquivos`) ou o seletor de fotos; o ditado usa a tela de voz do sistema. `<queries>` declara o reconhecedor de voz e o serviço de leitura em voz alta.

### Vídeo de abertura

`res/raw/abertura.mp4` (720×1280, H.264, sem áudio, ~730 KB): 3 s do martelo na forja e 1 s parado na logo, depois fade para as telas, que carregaram por baixo. Só ao entrar pelo ícone; não toca ao voltar do segundo plano, ao girar a tela, por notificação, atalho, widget ou link, nem com "remover animações" ligado. A tela de abertura do sistema segura até o primeiro quadro; se o vídeo falhar ou travar, o app segue (limite de 6 s). Não pede foco de áudio. As faixas acima e abaixo do vídeo (telas mais altas que 9:16) usam o mesmo cinza das bordas.

Gerado do vídeo original da marca (5 s):

```bash
ffmpeg -i original.mp4 -an -vf "setpts=PTS*3/5.0347,fps=30,tpad=stop_mode=clone:stop_duration=1,format=yuv420p" \
  -c:v libx264 -profile:v main -preset slow -crf 26 -movflags +faststart abertura.mp4
```

## Ambiente e geração do APK

Uma vez por máquina (Linux/WSL, sem sudo):

```bash
mobile/android/scripts/preparar-ambiente.sh
```

Usa o JDK do sistema se for 17 ou mais novo (senão baixa o Temurin 21 para `~/.local/share/bulai-jdk`), instala o Android SDK em `~/Android/Sdk` e grava `mobile/android/local.properties`.

```bash
mobile/android/scripts/gerar-apk.sh                                 # debug → produção (https://bulai.vixforge.com.br)
mobile/android/scripts/gerar-apk.sh debug http://192.168.0.10:8000  # debug → servidor de desenvolvimento na rede local
mobile/android/scripts/criar-chave-release.sh                       # uma vez: chave de assinatura + keystore.properties
mobile/android/scripts/gerar-apk.sh release                         # versão de publicação assinada
```

O APK vai para `mobile/android/dist/bulai-{versão}-{tipo}.apk` (fora do Git). A versão de depuração tem o pacote `br.com.vixforge.bulai.debug` e aceita HTTP. **A chave de publicação e as senhas ficam fora do repositório**: sem elas não dá para atualizar o app nos aparelhos.

### Emulador (WSL + emulador do Windows/Orca)

- Túnel do adb: `adb.exe reverse tcp:8000 tcp:8000`, servidor com `PHP_CLI_SERVER_WORKERS=6 php artisan serve --host=0.0.0.0 --port=8000` e APK com `gerar-apk.sh debug http://localhost:8000` (pelo `10.0.2.2` pedidos em paralelo falham ao acaso).
- Não deixe o adb do WSL rodando junto com o do Windows: os dois disputam a porta 5037 e o Orca deixa de ver o emulador.
- Para testar sem mexer no banco de desenvolvimento, use um banco à parte: `CREATE DATABASE bulai_app_demo`, `DB_DATABASE=bulai_app_demo php artisan migrate` e suba o servidor com o mesmo `DB_DATABASE`.
- Para o Perguntar e a receita responderem de verdade nesse banco, copie só o catálogo e as bulas (leitura do banco de dev, escrita no de demonstração): `pg_dump -U postgres -d bulai --data-only --disable-triggers -t knowledge_sources -t active_ingredients -t active_ingredient_aliases -t anvisa_medicines -t anvisa_medicine_ingredients -t drug_interactions -t knowledge_documents -t knowledge_chunks -t knowledge_document_ingredients -t knowledge_leaflet_facts -t medicine_leaflet_summaries | psql -U postgres -d bulai_app_demo`. As perguntas e a leitura da receita usam o provedor de IA do `.env`.
- Foto de receita no emulador: `adb push receita.png /sdcard/Pictures/` e `am broadcast -a android.intent.action.MEDIA_SCANNER_SCAN_FILE -d file:///sdcard/Pictures/receita.png`; depois "Escolher da galeria".

## Deploy do servidor

Migrações `mobile_devices` e `preferred_name` em `users` (nome de chamada) e as rotas novas de `routes/api.php`; sem variáveis novas e sem build de front. Perguntar e receita usam os mesmos provedores de IA e limites (`bulai.consultation.rate_limit_per_hour`, `bulai.prescription.*`) do site.

## Testes

- `tests/Feature/Consulta/AppAndroidTest.php`: entrar (só o hash no banco, entrar de novo troca o token, auditoria, e-mail sem diferença de maiúsculas), senha errada e conta bloqueada, limite de tentativas em JSON, cadastro aguardando aprovação, "esqueci a senha" com a mesma resposta, senha provisória (403 fora do primeiro acesso, troca mantém o aparelho e derruba os outros), Início, dia com ontem/amanhã e marcar/desfazer, central de avisos (abrir, lidos, apagar, preferências, aviso de outra pessoa → 404), agenda dos alarmes, marcação pela notificação, avisos novos, tokens inválidos e revogação.
- `BulaHumanizadaTest::test_the_app_opens_the_leaflet_at_once_and_generates_it_on_request_with_the_same_rules`: abrir não gera (`carregando`), gerar devolve capa, fontes, seções que faltam e nota; risco de alergia; reabrir usa o pronto; remédio sem princípio ativo; 404.
- `tests/Feature/Consulta/AppAndroidConsultasTest.php`: pergunta com contexto relido só do histórico da própria pessoa (uuid de outra pessoa ignorado) e destaques em texto puro; pergunta curta (422), remédio não reconhecido, limite por hora (429); histórico listado/aberto/apagado só pelo dono; receita lida, revisada (horários sugeridos), validação do prescritor e confirmação no tratamento (e 409 se confirmar de novo); consentimento (409), arquivo que não é imagem e receita de outra pessoa (404); busca no catálogo.
- `tests/Feature/Consulta/AppAndroidPerfilTest.php`: consentimento exigido (409) e auditado; remédio do catálogo e pelo nome (mensagem e "sem horário"); sugestão de horários ("de 12 em 12 horas"); dose e horários salvos (chips e mensagem) e data final antes do início (422 com a frase do site); receita com data dd/mm/aaaa; alergia; itens de outra pessoa intocados; apagar dados de saúde. Interações: remédios em uso e alerta de alergia, repetido recusado (422), busca com `exclude`, menos de dois (422), resultado com a faixa e a frase do site, histórico, outra pessoa (404), apagar; limite por hora (429). Bulas: busca no catálogo. Quem cuida: dose e horários só com a permissão, auditados; outra pessoa (403).
- `tests/Feature/Consulta/AppAndroidCuidadoTest.php`: convite com QR (o link inteiro serve como código), pedido, aprovação só para ver (vê o dia, marcar → 403), permissão de marcar liberada (dose marcada no tratamento do paciente), outra pessoa → 403, quem cuida sai e perde o acesso; convite sem consentimento (409), código curto (422), convite cancelado deixa de valer, tirar acesso; PDF só com consentimento.
- Os testes do site de Perguntar e Receita (`PerguntaLivreTest`, `ConversaHumanizadaTest`, `PerguntarConfiavelTest`, `ReceitaPorFotoTest`, `ExternalLabelsTest`…) continuam passando sobre os serviços extraídos.
- `mobile/android/app/src/test/`: `AgendadorDeAlarmesTest` (regras dos alarmes), `VideoDeAberturaTest` (quando o vídeo toca), `RedeTest` (cabeçalhos, leitura do JSON, 422 por campo, 401 encerra a sessão, 403 de senha provisória, 409 vira `Conflito`, 429, sem rede, campos no valor padrão enviados na confirmação da receita, resposta do Perguntar em trechos; com MockWebServer), `NavegacaoEDiaTest` (aviso/caminho → tela, inclusive receita, pergunta e modo cuidador; marcação otimista recalcula o resumo).
- Rodados também: `./gradlew lintDebug` e o fluxo no emulador: entrar, Início, Tomei, Remédios do dia, Avisos (lista, lembretes, alarmes do celular), alarme de teste com Tomei/Adiar/Pular, abrir por `bulai://abrir/avisos`, sair, senha provisória. Etapa Perguntar/Receita, com IA real: pergunta respondida com fontes, continuação "e com café?" usando a conversa, alergia registrada → aviso e balão vermelho, Minhas perguntas e pergunta anterior; receita escolhida da galeria, lida em ~3 s, troca de remédio pela busca, "quando tomar" corrigido virando "só quando precisar", horário novo pelo seletor, confirmação e abertura de Hoje com os remédios novos.

## Limitações

- Por fazer: publicação (assinatura, R8, Play Store). Quem cuida não cadastra nem tira remédios da pessoa (como no site: isso continua com o paciente).
- O leitor de QR Code depende do Google Play Services; sem ele, "Digitar o código" continua funcionando. O atalho de Interações volta com a tela.
- A conversa do Perguntar vive na memória do app (como no site, vive na tela): fechar o app começa outra conversa; o que foi perguntado continua em Minhas perguntas.
- Corrigir o remédio (Não é esse? / Não era esse) refaz a pergunta e conta no limite por hora (no site, a correção não conta).
- O trabalho em segundo plano (`SincronizarWorker`, `MarcarDoseWorker`) ainda usa o `ApiClient` (HttpURLConnection) da primeira versão; as telas usam Retrofit/OkHttp.
- **Sem push do servidor (FCM)**: avisos que não são de dose chegam na sincronização (30 min ou ao abrir o app).
- Fabricantes com economia de bateria agressiva podem segurar alarmes; a tela Avisos pede para liberar. Na Play Store, `REQUEST_IGNORE_BATTERY_OPTIMIZATIONS` e `USE_FULL_SCREEN_INTENT` passam por análise de política.
- Uma conta logada em mais de um celular recebe o alarme em todos.
- O widget mostra "amanhã" pelo fuso do celular, não pelo fuso escolhido em Avisos.
