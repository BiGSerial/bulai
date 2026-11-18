// Ponte com o aplicativo Android (mobile/android, docs/app-android.md).
//
// Dentro do app, o WebView expõe `window.BulaiNative` (interface Java) antes de qualquer script da página. Esta camada:
//   - liga o aparelho à conta logada (POST /aparelho com a sessão) e entrega o token à parte nativa, que passa a buscar
//     a agenda e a agendar alarmes exatos para cada dose; numa página de visitante, desconecta o aparelho (saiu da conta);
//   - pede uma nova sincronização sempre que uma tela que mexe no tratamento conclui uma ação (Tomei, Pular, posologia);
//   - expõe `window.bulaiNativo` para as telas (estado das permissões, modo despertador, teste de alarme);
//   - completa o `navigator.share`, que o WebView do Android não tem, com o compartilhamento nativo.
// No navegador comum nada disso roda.

const nativo = window.BulaiNative;

// Telas cujas ações mudam a agenda das doses (nomes dos componentes Livewire).
const TELAS_DO_TRATAMENTO = ['consulta.tratamento.hoje', 'consulta.inicio', 'consulta.perfil', 'consulta.receita.revisar', 'consulta.avisos'];

function lerEstado() {
    try {
        return JSON.parse(nativo.estado());
    } catch (e) {
        return null;
    }
}

function anunciar() {
    window.dispatchEvent(new CustomEvent('bulai-nativo-estado', { detail: lerEstado() }));
}

let conectando = false;

async function conferirConta() {
    const meta = document.querySelector('meta[name="bulai-sessao"]');
    if (meta === null) {
        // Página sem a marcação (tela offline, erro): não dá para saber se há sessão; não mexe no aparelho.
        return;
    }
    const usuario = meta.content;
    const conectado = nativo.usuarioConectado();
    if (usuario === '') {
        if (conectado !== '') {
            nativo.desconectar();
        }

        return;
    }
    if (conectado === usuario || conectando) {
        return;
    }
    conectando = true;
    try {
        const resposta = await fetch('/aparelho', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '' },
            body: JSON.stringify({ installation_id: nativo.instalacao(), device_name: nativo.aparelho(), app_version: nativo.versao() }),
        });
        if (resposta.ok) {
            const dados = await resposta.json();
            nativo.conectar(dados.token, String(dados.user_id));
            anunciar();
        }
    } catch (e) {
        // Sem rede: tenta de novo na próxima navegação.
    } finally {
        conectando = false;
    }
}

let sincronizacaoPendente = null;

function pedirSincronizacao() {
    window.clearTimeout(sincronizacaoPendente);
    sincronizacaoPendente = window.setTimeout(() => nativo.sincronizar(), 1500);
}

// `SpeechRecognition` sobre o reconhecimento de voz do Android (mobile/android/.../web/Ditado.kt). Implementa só o que
// resources/js/voz.js usa: lang, continuous, interimResults, onstart/onresult/onerror/onend, start/stop/abort.
// O Android entrega uma frase por sessão; o `onend` faz voz.js religar enquanto a pessoa não pedir para parar.
class ReconhecimentoNativo {
    constructor() {
        this.lang = 'pt-BR';
        this.continuous = false;
        this.interimResults = false;
        this.maxAlternatives = 1;
        this.onstart = null;
        this.onresult = null;
        this.onerror = null;
        this.onend = null;
    }

    start() {
        ReconhecimentoNativo.ativo = this;
        nativo.ouvirIniciar(this.lang);
    }

    stop() {
        nativo.ouvirParar();
    }

    abort() {
        nativo.ouvirCancelar();
    }
}

ReconhecimentoNativo.ativo = null;

function eventoDeVoz(tipo, dados = {}) {
    const rec = ReconhecimentoNativo.ativo;
    if (rec === null) {
        return;
    }
    if (tipo === 'start') {
        rec.onstart?.({});
    } else if (tipo === 'result') {
        const resultado = [{ transcript: dados.texto ?? '', confidence: 1 }];
        resultado.isFinal = Boolean(dados.final);
        rec.onresult?.({ resultIndex: 0, results: [resultado] });
    } else if (tipo === 'error') {
        rec.onerror?.({ error: dados.codigo ?? 'unknown' });
    } else if (tipo === 'end') {
        ReconhecimentoNativo.ativo = null;
        rec.onend?.({});
    }
}

if (nativo) {
    document.documentElement.classList.add('app-android');

    if (!('SpeechRecognition' in window) && !('webkitSpeechRecognition' in window) && nativo.ditadoDisponivel()) {
        window.SpeechRecognition = ReconhecimentoNativo;
        window.__bulaiVoz = eventoDeVoz;
    }

    if (typeof navigator.share !== 'function') {
        navigator.share = (dados = {}) => {
            nativo.compartilhar(dados.title ?? '', dados.text ?? '', dados.url ?? '');

            return Promise.resolve();
        };
    }

    window.bulaiNativo = {
        disponivel: true,
        estado: lerEstado,
        pedirPermissao: (tipo) => nativo.pedirPermissao(tipo),
        definirModoAlarme: (ligado) => {
            nativo.definirModoAlarme(Boolean(ligado));
            anunciar();
        },
        definirAdiamento: (minutos) => {
            nativo.definirAdiamento(Number(minutos));
            anunciar();
        },
        testarAlarme: () => nativo.testarAlarme(),
        sincronizar: () => nativo.sincronizar(),
    };

    document.addEventListener('DOMContentLoaded', conferirConta);
    document.addEventListener('livewire:navigated', conferirConta);
    const observarLivewire = () => window.Livewire.hook('commit', ({ component, succeed }) => {
        if (TELAS_DO_TRATAMENTO.includes(component?.name)) {
            succeed(pedirSincronizacao);
        }
    });
    if (window.Livewire?.hook) {
        observarLivewire();
    } else {
        document.addEventListener('livewire:init', observarLivewire, { once: true });
    }
    // A parte nativa avisa quando volta ao primeiro plano ou quando uma permissão muda.
    window.addEventListener('bulai-nativo-mudou', anunciar);
}
