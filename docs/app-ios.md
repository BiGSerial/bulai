# Aplicativo iOS nativo

## Propósito

O BULA-IA no iPhone como **aplicativo nativo** (Swift + SwiftUI), com as mesmas funcionalidades do app Android ([`app-android.md`](app-android.md)) e falando com **a mesma API** `/api/mobile/v1` do Laravel. Não há WebView nem backend novo: as regras de negócio (consentimento, permissões dos cuidadores, limites, validações, DDInter, RAG, validador, frases de segurança, remédios não reconhecidos) continuam no servidor; o app desenha o JSON e faz o que só o aparelho faz bem — lembretes locais das doses com "Tomei", "Adiar" e "Pular", widget, atalhos, câmera, voz e Keychain.

## Situação

| Etapa | Conteúdo | Situação |
| --- | --- | --- |
| Backend | `platform` (`android`\|`ios`) no `POST /entrar`; auditoria de conexão com a plataforma | **feita** (testada) |
| Núcleo `BulaiCore` | modelos dos contratos, cliente HTTP, erros, sessão/token, cache, regras dos lembretes, fila de marcações, navegação, ViewModels de todas as telas | **feita** (75 testes `swift test` passando no Linux) |
| Telas SwiftUI | todas as do Android (lista abaixo) | **escritas, não compiladas** (sem macOS/Xcode no ambiente) |
| Widget, atalhos, projeto Xcode, ícone, configuração | WidgetKit + App Intents, `BulaIA.xcodeproj`, xcconfigs, Info.plist, entitlements, manifesto de privacidade | **escritos, não compilados** |
| Publicação | App Store Connect, TestFlight, política de privacidade da loja | a fazer |

> **Importante:** o app e o widget **não foram compilados nem executados** — o desenvolvimento foi feito em Linux/WSL, sem Xcode. O núcleo (tudo menos as telas, o widget e a camada Apple) compila em Swift 6 estrito e tem os testes rodando. Ver [Pendências](#pendências-que-dependem-de-macos-xcode-assinatura-ou-aparelho).

## Arquitetura

```
┌──────────────────────────── mobile/ios ────────────────────────────┐        ┌──── Laravel ────┐
│ BulaIA (app SwiftUI)                                                │        │ /api/mobile/v1  │
│   RaizView: Sessão → Entrar | Primeiro acesso | AbasView            │        │ (o mesmo do     │
│   Telas (Telas/*) ─ ViewModels @Observable (BulaiCore) ─────────────┼─HTTPS─►│  Android)       │
│   Ambiente: Dependencias + Navegador + Voz                          │ Bearer │                 │
│   AppDelegate: ações das notificações · .backgroundTask(appRefresh) │        │                 │
│ BulaiWidget (WidgetKit) ─ MarcarTomeiIntent ────────────────────────┼───────►│ POST /doses     │
│                                                                     │        └─────────────────┘
│ BulaiCore (pacote Swift, sem UIKit): Modelos · ClienteDaApi · Sessao · CacheEmDisco ·            │
│   PlanejadorDeLembretes · Sincronizador · Rota · ViewModels · Apple/ (Keychain, UserNotifications)│
└───────────────────────────────────────────────────────────────────────────────────────────────────┘
```

- **MVVM**: cada tela é uma `View` SwiftUI com um ViewModel `@Observable` (`@MainActor`) do `BulaiCore`. Os ViewModels não conhecem SwiftUI/UIKit, por isso são testados com `swift test` (inclusive no Linux).
- **Swift Concurrency**: toda chamada de rede é `async/await` (`URLSession.data(for:)`), sem callbacks; o núcleo compila em **Swift 6 com concorrência estrita**.
- **Mesma lógica do Android**: cada ViewModel reproduz o equivalente Kotlin (marcação otimista com desfazer, cache da última resposta, acompanhamento a cada 2 s da receita e das interações, conversa com os `uuid` das trocas anteriores, busca com espera de digitação etc.).

### Fluxo de conta e token

1. **Entrar**: `POST /entrar` com `email`, `password`, `installation_id` (UUID desta instalação), **`platform: "ios"`**, `device_name` (modelo e versão do iOS) e `app_version`. O servidor devolve o token `bulai_app_…` (guarda só o sha256) — o mesmo mecanismo de token individual por instalação do Android.
2. O token vai para o **Keychain** (`kSecAttrAccessibleAfterFirstUnlockThisDeviceOnly`: não sai do aparelho, não vai para backup, e as ações da notificação funcionam com a tela bloqueada). Em memória, uma `CaixaDoToken` protegida por trava. Nada de token, corpo de pedido ou dado de saúde vai para log.
3. **Senha provisória** (`must_change_password`): só a tela de troca; o 403 `must_change_password` das outras rotas vira `FalhaDaApi.trocarSenha`.
4. **Token recusado (401)** em qualquer chamada autenticada: a sessão local é encerrada (Keychain, conta, agenda, fila, cache das telas e notificações apagados) e o app volta para Entrar — inclusive quando o 401 acontece no segundo plano.
5. **Sair**: limpa o aparelho na hora e avisa o servidor (`DELETE /aparelho`) com o token antigo (melhor esforço).
6. **Reinstalação**: o Keychain do iOS sobrevive à remoção do app, os UserDefaults não. Um token sem conta guardada é de uma instalação anterior e é descartado ao abrir (só pelo app; o widget nunca apaga o token).
7. **Outra conta no mesmo iPhone**: ao entrar com outro usuário, nada da conta anterior continua (lembretes, cache, fila).

## Funcionalidades e telas

| Android | iOS (arquivo) | Observações |
| --- | --- | --- |
| Entrar, Cadastro, Esqueci a senha, Primeiro acesso | `Telas/Conta/EntrarView.swift` | erros por campo do 422; gerenciador de senhas pelo `textContentType`; aceite do aviso do projeto acadêmico |
| Início (saudação, avisos novos, cartão do dia com anel e próximo remédio, ações, quem você acompanha) | `Telas/Inicio/InicioView.swift` | barra de espera de `since` até o horário (atualiza a cada 20 s, `TimelineView`); "Tomei" otimista |
| Hoje (ontem/hoje/amanhã, linha do tempo por período, Tomei/Pular/Desfazer, quando precisar, modo cuidador) | `Telas/Dia/DiaView.swift` | abas com deslize (`TabView` paginado); efeito do "Tomei" (joinha) respeita "Reduzir movimento"; vibração de confirmação |
| Avisos (sininho) | `Telas/Avisos/AvisosView.swift` | deslizar para apagar + ação do VoiceOver; abrir leva à tela do aviso |
| Perguntar (conversa, ditado, Ouvir, alergia, blocos de ajuda, respostas rápidas, correção do remédio, fontes, histórico) | `Telas/Perguntar/*` | "Atenção à sua alergia" em alerta; telefones de ajuda abrem o discador (`tel:`); só a resposta mais recente tem ações |
| Receita por foto (câmera/galeria, leitura, revisão, confirmação) | `Telas/Receita/ReceitaViews.swift` | foto reduzida a 2400 px e endireitada antes de subir; revisão com horários, modo por intervalo, "Não cadastrar este"; nada é salvo sem "Confirmar e salvar" |
| Meus remédios (consentimento, remédios, receitas, alergias, apagar dados de saúde) | `Telas/Perfil/PerfilView.swift` | "Adicionar remédio" busca no catálogo ou usa o nome (fora do catálogo vira pendência no servidor) e já abre "Dose e horários" |
| Dose e horários (3 passos) | `Telas/Perfil/PosologiaView.swift` + `EditorDePosologia` | atalhos do servidor (`posology_presets`); "Outro jeito?" sugere horários pelo servidor; também para quem cuida |
| Interações (lista com os remédios em uso, alergia, resultado acompanhado, histórico) | `Telas/Interacoes/InteracoesViews.swift` | a frase de cada par é a do servidor; cor + selo (nunca só cor) |
| Bula simples (lista e bula) | `Telas/Bula/BulaViews.swift` | esqueleto enquanto prepara; "Ouvir"; "De onde veio?"; bula pronta guardada no aparelho |
| Cuidadores com QR Code (Quem cuida de mim, Cuidar de alguém, dia e remédios de quem cuido) | `Telas/Cuidado/CuidadoViews.swift` | QR gerado com Core Image (escuro sobre branco); leitura com VisionKit; "Digitar o código" sempre disponível |
| Resumo para o médico (PDF) | `Telas/Conta/ContaView.swift` | baixa para a pasta temporária e abre no Quick Look (compartilhar pelo próprio visualizador) |
| Conta (nome de chamada, aparência, lembretes, alarmes, senha, sair, apagar conta) | `Telas/Conta/ContaView.swift` | seção "Lembretes neste iPhone" no lugar de "Alarmes neste celular" (ver diferenças) |
| Notificações das doses com Tomei/Adiar/Pular | `BulaiCore/Apple/NotificacoesLocais.swift` + `AppDelegate` | ver diferenças |
| Widget "Próximo remédio" com Tomei | `Widget/ProximaDoseWidget.swift` | WidgetKit + botão interativo (App Intents, iOS 17); também na tela bloqueada |
| Atalhos do ícone (Hoje, Perguntar, Fotografar receita) | `Intents/AtalhosDoApp.swift` | App Shortcuts (Siri, Spotlight, botão de Ação) |
| `bulai://abrir/{caminho}` | `Rota.doLink` | mesmos caminhos do Android; avisos com `destination` → `Rota.doDestino` |
| Vídeo de abertura | `Sistema/VideoDeAbertura.swift` | o mesmo `abertura.mp4`; só ao abrir do zero; sem som; não toca com "Reduzir movimento"; limite de 6 s |

Experiência iOS: navegação em `NavigationStack` por aba (Início, Perguntar, Hoje, Conta) com o sininho na barra; folhas (`sheet`) para "Dose e horários", buscas e formulários; `confirmationDialog` para ações destrutivas; puxar para atualizar; esqueletos com brilho na primeira abertura e a última resposta guardada nas seguintes; modo claro/escuro (ou fixo, em Conta › Aparência) com as cores da marca; **Dynamic Type** em todo texto (estilos de texto, nunca tamanho fixo); alvos de toque ≥ 44 pt; títulos marcados como cabeçalho, estados das doses lidos pelo VoiceOver, ações de acessibilidade para apagar; largura limitada a 640–720 pt em iPad.

## Reutilização da API e dos serviços

O app usa **somente** `/api/mobile/v1` (`BulaiCore/Rede/Endpoints.swift` lista todas as chamadas, as mesmas do `BulaiApi.kt`). Os contratos estão em `BulaiCore/Modelos/*.swift`, com campos opcionais tolerantes (chave ausente ou `null` usa o padrão, como o `coerceInputValues` do Android; `params: []` do PHP vira objeto vazio).

**Adaptação no backend (a única necessária):** o login gravava `platform = 'android'` fixo em `MobileDevice::issueFor`. Agora `POST /entrar` aceita `platform` opcional (`android`\|`ios`, validado; sem o campo vale `android`, então os APKs atuais não mudam), e a auditoria `mobile_device_connected`/`mobile_device_disconnected` guarda a plataforma (rótulos "Aplicativo do celular conectado/desconectado"). Arquivos: `app/Models/MobileDevice.php`, `app/Http/Controllers/Api/Mobile/AuthController.php`, `DeviceController.php`, `app/Models/UserAuditEvent.php`, `app/Http/Middleware/AuthenticateMobileDevice.php` (comentário). Teste: `AppAndroidTest::test_login_records_the_platform_of_the_installation_defaulting_to_android`. Sem migração (a coluna `platform` já existia). Nada mudou em regras farmacológicas, DDInter, RAG ou validador.

## Diferenças inevitáveis em relação ao Android

| Android | iOS | Por quê |
| --- | --- | --- |
| Alarme **exato** (`AlarmManager.setAlarmClock`), mesmo com o celular parado | **Notificação local agendada** (`UNCalendarNotificationTrigger`) na hora da dose, entregue pelo sistema mesmo sem internet e com o app fechado | o iOS não permite que um app acorde em hora exata nem rode código no disparo; a entrega costuma ser pontual, mas **não é garantida** como um despertador |
| Modo despertador em **tela cheia** com som contínuo | Notificação **"Sensível ao tempo"** (quando a capacidade está assinada) + opção **"Insistir até eu marcar"**: repete o lembrete 2 vezes no intervalo de "Adiar" | não existe tela cheia de terceiros no iOS; alertas críticos exigem autorização especial da Apple (não pedida). O modo Silencioso e o Foco podem tirar o som — a Conta orienta liberar o BULA-IA no Foco |
| Alarmes ilimitados | No máximo **56 lembretes** pendentes (o iOS guarda até 64 por app; sobram vagas para avisos e o teste) — os mais próximos | limite do sistema; a agenda cobre hoje e amanhã e é remontada a cada sincronização |
| Dose atrasada até 15 min toca logo após religar | Igual quando a agenda chega atrasada; notificações já agendadas o próprio iOS entrega ao religar | — |
| Sincronização a cada 30 min (WorkManager) | Ao abrir o app, ao voltar para ele, depois de cada mudança e em **atualização em segundo plano** (`BGAppRefreshTask`) quando o iOS permitir (algumas vezes por dia, conforme o uso) | o iOS decide quando roda tarefas em segundo plano; não há execução contínua |
| Avisos (não de dose) como notificação a cada sincronização | Os mesmos, mas só quando a sincronização roda (abrir o app ou atualização em segundo plano) | sem push do servidor (APNs exigiria conta paga e mudança no backend; o Android também não tem FCM) |
| "Tomei/Pular" pela notificação vão para o servidor pelo WorkManager, repetindo até conseguir | Valem na hora no aparelho; o app tenta enviar no próprio toque (o iOS acorda o app por alguns segundos) e, sem rede, a marcação fica numa **fila** enviada na próxima sincronização | — |
| Permissões de hora exata, tela cheia e bateria | Só a de notificações (com atalho para os Ajustes) | o iOS não tem essas permissões |
| Ditado pela tela de voz do sistema, sem permissão | `SFSpeechRecognizer` (no aparelho quando disponível) com permissão de microfone e de fala; o teclado do iOS também tem o ditado do sistema | API de fala do iOS exige as permissões |
| Câmera pelo app de câmera do aparelho, sem permissão | Câmera do iOS (`UIImagePickerController`) pede a permissão de câmera; a galeria usa o seletor do sistema (`PhotosPicker`), **sem** permissão de fotos | — |
| Leitor de QR do Google Play Services | VisionKit (`DataScannerViewController`, iPhone XS/XR ou mais novo); sem suporte ou sem câmera, "Digitar o código" | — |
| Widget com "Tomei" a partir de 1 h antes | Igual (botão interativo iOS 17). Com **conta Apple gratuita** sem App Group, o widget mostra "Abra o BULA-IA e entre na sua conta" | o widget lê a agenda do App Group e o token do grupo de Keychain |
| PDF aberto no leitor do celular | Quick Look dentro do app (com compartilhar) | — |
| Voltar preditivo, menu inferior escondido em telas empilhadas | Gesto de voltar do iOS; o menu de abas continua visível nas telas empilhadas (convenção do iOS) | — |
| Efeito "voo da pergunta" (balão fantasma) | Transição de entrada do balão (deslize + opacidade) | efeito simplificado; o "Enviada" e o resto da conversa são iguais |

## Projeto, configuração e assinatura

```
mobile/ios/
├── BulaIA.xcodeproj           projeto Xcode 16+ (pastas sincronizadas; gerado por scripts/gerar-projeto.py)
├── BulaIA/                    alvo do app (tudo nesta pasta entra no app)
│   ├── App/                   BulaiApp (@main), AppDelegate, Ambiente (dependências e navegação), RaizView/abas
│   ├── Telas/                 Conta, Inicio, Dia, Avisos, Perguntar, Receita, Perfil, Interacoes, Bula, Cuidado
│   ├── Tema/                  cores da marca (claro/escuro), tipografia, componentes reutilizáveis
│   ├── Sistema/               voz (ouvir/ditar), câmera e fotos, QR Code, vídeo de abertura
│   ├── Intents/               App Shortcuts
│   └── Recursos/              Assets.xcassets (ícone, logo, símbolo, cores), abertura.mp4, PrivacyInfo.xcprivacy
├── Widget/                    alvo do widget (WidgetKit + App Intent "Tomei") e suas cores
├── BulaiCore/                 pacote Swift (núcleo) + Tests/BulaiCoreTests (75 testes, fixtures JSON reais)
├── Config/                    Base/Debug/Release.xcconfig, Local.xcconfig.example, Info.plist, entitlements
└── scripts/                   testar.sh, compilar.sh, gerar-ipa.sh, gerar-projeto.py, gerar-recursos.py
```

| Configuração | Onde | Padrão |
| --- | --- | --- |
| Identificador do app (`BULAI_BUNDLE_ID`; o widget usa `.widget`) | `Config/Base.xcconfig` (sobrescreva em `Local.xcconfig`) | `br.com.vixforge.bulai` |
| Versão e build (`MARKETING_VERSION`, `CURRENT_PROJECT_VERSION`) | `Config/Base.xcconfig` | `1.0.0` (1) |
| URL da API (`BULAI_API_URL` → `Info.plist` `BulaiAPIURL`) | `Config/Base.xcconfig` / `Local.xcconfig` | `https://bulai.vixforge.com.br` |
| Equipe de assinatura (`DEVELOPMENT_TEAM`) | **só** em `Config/Local.xcconfig` (fora do Git) | vazio |
| App Group / grupo de Keychain (widget) | `BULAI_APP_GROUP`, `BULAI_KEYCHAIN_GROUP` + `Config/*.entitlements` | `group.<id>` / `<prefixo>.<id>.compartilhado` |
| Conta gratuita sem essas capacidades | `BULAI_ENTITLEMENTS_APP/WIDGET = Config/*-Simples.entitlements` | — |
| Ícone e tela de abertura | `Recursos/Assets.xcassets` (`AppIcon` 1024 px, `FundoAbertura`, `Simbolo`) gerados de `public/brand` por `scripts/gerar-recursos.py` | — |
| Permissões (`Info.plist`) | câmera (receita e QR), microfone e reconhecimento de fala (ditado); notificações pedidas ao entrar | — |
| HTTP local | `NSAllowsLocalNetworking` (localhost e nomes `.local`); produção só HTTPS (ATS) | — |

Nada de certificado, perfil de provisionamento, `.p12`, equipe ou segredo vai para o Git (`mobile/ios/.gitignore` cobre `Config/Local.xcconfig`, `*.mobileprovision`, `*.p12`, `*.ipa`, `*.xcarchive`). A assinatura é a automática do Xcode, com a conta do desenvolvedor no Mac.

## Como compilar, testar e instalar

Requisitos: macOS com **Xcode 16 ou mais novo** (iOS 17+ no aparelho). O núcleo também testa no Linux.

```bash
cd mobile/ios
cp Config/Local.xcconfig.example Config/Local.xcconfig   # ajuste DEVELOPMENT_TEAM e BULAI_BUNDLE_ID
scripts/testar.sh            # swift test no núcleo + xcodebuild test (no macOS)
scripts/compilar.sh          # app e widget para o simulador, sem assinatura
open BulaIA.xcodeproj        # ou abra no Xcode e use ⌘R
```

**Simulador:** escolha um iPhone no Xcode e ⌘R. Para um servidor local, `BULAI_API_URL = http:/$()/localhost:8000` em `Local.xcconfig` e `php artisan serve` no Mac.

**iPhone físico com uma Conta Apple (gratuita serve):**

1. Xcode › Settings › Accounts › adicione a Conta Apple; anote o Team ID.
2. Em `Config/Local.xcconfig`: `DEVELOPMENT_TEAM = <Team ID>` e um `BULAI_BUNDLE_ID` único (ex.: `br.com.seunome.bulai`). Com conta gratuita, se a assinatura reclamar de App Groups/Keychain Sharing/Time Sensitive, use `BULAI_ENTITLEMENTS_APP = Config/BulaIA-Simples.entitlements` e `BULAI_ENTITLEMENTS_WIDGET = Config/Widget-Simples.entitlements` (o app funciona igual; o widget mostra "Abra o BULA-IA").
3. Conecte o iPhone, ative o **Modo de Desenvolvedor** (Ajustes › Privacidade e Segurança), escolha o aparelho no Xcode e ⌘R.
4. No iPhone, confie no desenvolvedor em Ajustes › Geral › VPN e Gerenciamento de Dispositivo. Com conta gratuita, o app vale por 7 dias (rode de novo pelo Xcode para renovar).
5. Servidor de desenvolvimento a partir do iPhone: use o nome `.local` do Mac (`http:/$()/meu-mac.local:8000`) ou um túnel HTTPS.

**IPA** (com assinatura e Xcode): `BULAI_TEAM=<Team ID> scripts/gerar-ipa.sh` gera `dist/bulai-<versão>-debugging.ipa` (aparelhos registrados); `release-testing` (Ad Hoc) e `app-store-connect` exigem o Apple Developer Program (pago). O script só arquiva e exporta: **não envia nada para a App Store**.

### Codemagic (build em Mac na nuvem)

Sem Mac por perto, o `codemagic.yaml` da raiz do repositório compila o app no Codemagic:

- **`ios-verificacao`** (sem assinatura, roda a cada push/PR que mexe em `mobile/ios/`): `swift test` no núcleo, build do app e do widget para o simulador (`CODE_SIGNING_ALLOWED=NO`), `xcodebuild test` do esquema num iPhone simulado e o `BulaIA-simulador.zip` como artefato. É o primeiro passo das pendências: mostra os erros de compilação das telas.
- **`ios-testflight`** (manual): `xcode-project use-profiles`, número do build = último do App Store Connect + 1 (no `Config/Base.xcconfig`), `xcode-project build-ipa` e envio ao **TestFlight** (não envia à App Store). Exige Apple Developer Program, o app criado no App Store Connect (troque `APP_STORE_APPLE_ID`) e a integração App Store Connect com a chave de API no Codemagic (Teams › Integrations). Perfis e certificados ficam no Codemagic, nunca no Git.

- **`ios-ipa-sem-assinatura`** (manual): IPA para iPhone **sem assinatura** (`bulai-<versão>-sem-assinatura.ipa`), compilado com os entitlements "-Simples" de conta gratuita. Serve para instalar sem Mac e sem Apple Developer Program:
  1. Baixe o `.ipa` nos artefatos do build no Codemagic.
  2. No Windows, instale o **iTunes** (versão do site da Apple, não a da Microsoft Store) e o **Sideloadly** (sideloadly.io).
  3. Conecte o iPhone pelo cabo, abra o Sideloadly, arraste o `.ipa`, informe o seu Apple ID e clique em Start (o Apple ID vai para os servidores da Apple, não para o projeto).
  4. No iPhone: Ajustes › Privacidade e Segurança › **Modo de Desenvolvedor** ligado; depois Ajustes › Geral › VPN e Gerenciamento de Dispositivo › confiar no seu Apple ID.
  5. Vale **7 dias**: depois disso, repita o passo 3 (os dados do app continuam). Conta gratuita: até 3 apps instalados assim e 10 identificadores por semana (o widget conta como um). Se o Sideloadly reclamar do widget, use a opção de remover extensões; o app funciona igual.
  Caminho fora da distribuição oficial da Apple; não testado com este projeto.

Como ligar: em codemagic.io, adicione o repositório `BiGSerial/bulai`, escolha "codemagic.yaml" e rode `ios-verificacao` na branch.

## Testes

`BulaiCore/Tests/BulaiCoreTests` — **75 testes, todos passando** com `swift test` (Swift 6.4.2, Linux/WSL; o mesmo comando roda no macOS e pelo esquema `BulaIA` do Xcode):

| Suíte | O que cobre |
| --- | --- |
| `ContratosTests` (13) | **64 respostas reais** de `/api/mobile/v1` (capturadas dos testes Laravel do app: `Tests/BulaiCoreTests/Contratos/*.json`) lidas pelos tipos do app; Início, dia de quem cuido (sem permissão), avisos com destino, Perguntar com blocos de ajuda, receita em revisão, bula pronta com risco, interações, cuidadores, agenda; ida e volta do cache; padrões para chaves ausentes/nulas |
| `RedeTests` (11) | cabeçalhos (JSON, `BulaiIOS/<versão>`, Bearer), tempo maior para IA, 422 por campo, 401 encerra a sessão, 403 de senha provisória e de permissão, 404, 409 com código, 429, sem rede, **timeout**, resposta fora do formato, multipart da foto, DELETE com corpo, `exclude`, `null` explícito no nome de chamada, padrões enviados na confirmação da receita |
| `SessaoTests` (10) | entrar com `installation_id` e `platform=ios`, token no cofre e lembretes ligados; senha errada; campos vazios; senha provisória até a troca; reabrir com a conta guardada; token de reinstalação descartado (o widget nunca apaga); **token revogado** apaga tudo; sair avisa o servidor com o token antigo; outra conta não herda dados; apagar a conta |
| `PlanejadorTests` (9) | só doses com lembrete e não marcadas; atraso de até 15 min; lembrete já entregue não repete; adiar; insistir (inclusive depois do primeiro); limite de notificações do iOS; identificadores; próxima dose do widget |
| `SincronizadorTests` (7) | sincronização com avisos novos (sem duplicar, sem os de dose); **Tomei sem internet** → fila → envio com a hora real do toque; marcação recusada volta a lembrar; adiar; widget sem token não apaga lembretes; desconectado; **offline** mantém a agenda e os lembretes |
| `RotaTests` (2) | rotas do site e caminhos `bulai://abrir/…` → telas |
| `TelasTests` (23) | estados das telas: cache + erro sem apagar a tela; marcação otimista e desfazer; falha volta a dose; **cuidador sem permissão** (403, botões escondidos) e com permissão; avisos; Perguntar (curta demais, `turns`, falha e tentar de novo, correção com remédio fixo, "Sobre X:"); receita (acompanha a leitura, valida prescritor, confirma, horários refeitos, sem consentimento); interações (repetido recusado, resultado acompanhado); bula carregando → gerar → guardada; Dose e horários (atalho pelo servidor, horários em ordem, máximo de 12); aprovar cuidador com as permissões marcadas; código de convite inválido; PDF sem consentimento; preferências de lembrete revertidas |

Backend: `php artisan test --compact tests/Feature/Consulta/` — 296 testes passando (inclui o novo teste de `platform`).

## Pendências que dependem de macOS, Xcode, assinatura ou aparelho

- **Compilar o app e o widget** (`scripts/compilar.sh` num Mac ou o workflow `ios-verificacao` do Codemagic) e corrigir o que o compilador apontar: as telas SwiftUI, o widget e `BulaiCore/Apple/*` nunca passaram por um compilador. O `project.pbxproj` foi validado por um leitor de OpenStep plist (alvos, fases, pastas sincronizadas e configurações), não pelo Xcode.
- Rodar `xcodebuild test` (o mesmo núcleo já testado) e testes de interface (XCUITest) — não escritos.
- Executar no simulador e num iPhone: entrar, Hoje, Tomei pela notificação com a tela bloqueada, Adiar, widget com Tomei, câmera, galeria, QR Code (VisionKit precisa de aparelho), ditado e Ouvir, Quick Look do PDF, tema escuro, Dynamic Type grande e VoiceOver.
- Confirmar com a assinatura real: App Group, Keychain compartilhado e "Sensível ao tempo" (com conta gratuita, usar os entitlements "-Simples").
- Medir a pontualidade das notificações e o comportamento do `BGAppRefreshTask` num aparelho real.
- Publicação: Apple Developer Program, App Store Connect, "Privacidade do app" (o `PrivacyInfo.xcprivacy` já declara dados de saúde, nome e e-mail só para funcionamento, sem rastreamento), TestFlight.

## Limitações conhecidas

- Mesmas do Android: sem push do servidor; quem cuida não cadastra nem tira remédios da pessoa; a conversa do Perguntar vive na memória (o histórico fica em Minhas perguntas); corrigir o remédio conta no limite por hora.
- Lembretes do iOS não são alarmes (ver diferenças). Uma conta em mais de um aparelho recebe o lembrete em todos.
- O widget calcula "amanhã" pelo fuso do iPhone, não pelo fuso escolhido em Lembretes.
- A agenda e a conta (nome, e-mail, remédios do dia) ficam nos UserDefaults do App Group, protegidos pela criptografia do iOS (como as SharedPreferences do Android); o cache das telas fica em Application Support, fora do backup, com proteção de arquivo; o token fica só no Keychain.
- Com conta Apple gratuita: app válido por 7 dias por instalação e widget sem dados se o App Group não for assinado.
