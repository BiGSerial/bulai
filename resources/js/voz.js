// Ditado por voz (Web Speech API) para o app do paciente: componente Alpine global `ditado`.
//
// Uso no Blade:
//   x-data="ditado({ aplicar: (texto) => {...}, confirmar: (texto) => $wire.set('campo', texto), texto: () => campo.value })"
// `aplicar` recebe o texto completo a cada resultado (final ou parcial) para mostrar na caixa; `confirmar` é chamado uma vez,
// com atraso, quando o usuário para de falar (sincroniza com o Livewire sem um pedido por palavra); `texto` devolve o que já
// está escrito para o ditado ACRESCENTAR em vez de substituir.
//
// Por que "hora funciona, hora não" antes: o reconhecimento era não contínuo (parava na primeira pausa), sem resultados
// parciais (parecia travado), substituía o texto digitado, não reiniciava quando o navegador encerrava sozinho e não
// explicava erros (microfone negado, sem internet, sem áudio).

const MENSAGENS = {
    'not-allowed': 'Permita o microfone nas configurações do navegador para falar a pergunta.',
    'service-not-allowed': 'Permita o microfone nas configurações do navegador para falar a pergunta.',
    'no-speech': 'Não ouvi nada. Fale perto do celular e tente de novo.',
    network: 'O ditado precisa de internet. Verifique a conexão.',
    'audio-capture': 'Nenhum microfone encontrado neste aparelho.',
    'language-not-supported': 'Este navegador não entende português no ditado. Use o microfone do teclado.',
};

const MAX_REINICIOS = 6;
const ATRASO_CONFIRMACAO_MS = 700;

function reconhecimentoDisponivel() {
    return typeof window !== 'undefined' && (('SpeechRecognition' in window) || ('webkitSpeechRecognition' in window));
}

export function ditado(opcoes = {}) {
    const aplicar = typeof opcoes.aplicar === 'function' ? opcoes.aplicar : () => {};
    const confirmar = typeof opcoes.confirmar === 'function' ? opcoes.confirmar : () => {};
    const textoAtual = typeof opcoes.texto === 'function' ? opcoes.texto : () => '';

    return {
        suportado: reconhecimentoDisponivel(),
        ouvindo: false,
        parcial: '',
        erro: '',
        rec: null,
        base: '',
        finais: '',
        reinicios: 0,
        pararPedido: false,
        timerConfirmacao: null,
        aoNavegar: null,
        aoVisibilidade: null,

        init() {
            this.aoNavegar = () => this.parar();
            this.aoVisibilidade = () => {
                if (document.visibilityState === 'hidden') {
                    this.parar();
                }
            };
            document.addEventListener('livewire:navigating', this.aoNavegar);
            document.addEventListener('visibilitychange', this.aoVisibilidade);
        },

        destroy() {
            this.parar();
            document.removeEventListener('livewire:navigating', this.aoNavegar);
            document.removeEventListener('visibilitychange', this.aoVisibilidade);
        },

        alternar() {
            if (this.ouvindo) {
                this.parar();
            } else {
                this.iniciar();
            }
        },

        iniciar() {
            if (!this.suportado || this.ouvindo) {
                return;
            }
            this.erro = '';
            this.parcial = '';
            this.finais = '';
            this.reinicios = 0;
            this.pararPedido = false;
            this.base = (textoAtual() || '').trim();
            this.ligar();
        },

        // Cria uma instância nova a cada (re)início: reutilizar a anterior falha em alguns navegadores Android.
        ligar() {
            const Reconhecimento = window.SpeechRecognition || window.webkitSpeechRecognition;
            const rec = new Reconhecimento();
            rec.lang = 'pt-BR';
            rec.continuous = true;
            rec.interimResults = true;
            rec.maxAlternatives = 1;

            rec.onstart = () => {
                this.ouvindo = true;
            };
            rec.onresult = (evento) => {
                let parcial = '';
                for (let i = evento.resultIndex; i < evento.results.length; i++) {
                    const resultado = evento.results[i];
                    const trecho = (resultado[0]?.transcript ?? '').trim();
                    if (trecho === '') {
                        continue;
                    }
                    if (resultado.isFinal) {
                        this.finais = `${this.finais} ${trecho}`.trim();
                    } else {
                        parcial = `${parcial} ${trecho}`.trim();
                    }
                }
                this.parcial = parcial;
                aplicar(this.textoCompleto());
                this.agendarConfirmacao();
            };
            rec.onerror = (evento) => {
                const codigo = evento?.error ?? '';
                if (codigo === 'aborted') {
                    return;
                }
                if (codigo === 'no-speech' && !this.pararPedido && this.reinicios < MAX_REINICIOS) {
                    // Silêncio prolongado: o navegador encerra; `onend` reinicia enquanto o usuário não pediu para parar.
                    return;
                }
                this.erro = MENSAGENS[codigo] ?? 'Não foi possível usar o microfone agora. Você pode digitar a pergunta.';
                this.pararPedido = true;
            };
            rec.onend = () => {
                this.ouvindo = false;
                this.rec = null;
                if (!this.pararPedido && this.reinicios < MAX_REINICIOS) {
                    this.reinicios += 1;
                    try {
                        this.ligar();

                        return;
                    } catch (e) {
                        // cai no encerramento normal abaixo
                    }
                }
                this.parcial = '';
                aplicar(this.textoCompleto());
                this.confirmarAgora();
            };

            this.rec = rec;
            try {
                rec.start();
                this.ouvindo = true;
            } catch (e) {
                this.ouvindo = false;
                this.rec = null;
                this.erro = 'Não foi possível iniciar o microfone. Tente de novo.';
            }
        },

        parar() {
            this.pararPedido = true;
            if (this.rec) {
                try {
                    this.rec.stop();
                } catch (e) {
                    // já parado
                }
            } else {
                this.ouvindo = false;
            }
        },

        textoCompleto() {
            return [this.base, this.finais, this.parcial].filter((parte) => parte !== '').join(' ').trim();
        },

        agendarConfirmacao() {
            clearTimeout(this.timerConfirmacao);
            this.timerConfirmacao = setTimeout(() => this.confirmarAgora(), ATRASO_CONFIRMACAO_MS);
        },

        confirmarAgora() {
            clearTimeout(this.timerConfirmacao);
            this.timerConfirmacao = null;
            const texto = [this.base, this.finais].filter((parte) => parte !== '').join(' ').trim();
            confirmar(texto);
        },
    };
}

document.addEventListener('alpine:init', () => {
    window.Alpine?.data('ditado', ditado);
});
