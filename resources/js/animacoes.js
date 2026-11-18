// Efeitos do app do paciente, os mesmos do aplicativo Android (mobile/android, ui/dia e ui/inicio):
// - `doseAnimada`: "Tomei" na tela Hoje. O verde nasce num círculo a partir do botão e cobre o cartão (textos inclusive),
//   o joinha salta com mola, pula e balança; depois o verde clareia e os textos voltam já com "Tomei". "Desfazer" recolhe
//   o verde de volta para o botão.
// - `proximaDose`: no Início, o fundo do bloco "Próximo" enche até o horário da dose, com a ponta balançando irregular
//   no lugar, como líquido mexido.
// As animações usam a Web Animations API (sobrevivem à atualização do Livewire, que só troca atributos e textos) e
// respeitam "reduzir movimento" do sistema.

const reduzirMovimento = () => window.matchMedia('(prefers-reduced-motion: reduce)').matches;

/** Raio que cobre o elemento inteiro a partir de (x, y). */
function raioAteOsCantos(largura, altura, x, y) {
    return Math.max(Math.hypot(x, y), Math.hypot(largura - x, y), Math.hypot(x, altura - y), Math.hypot(largura - x, altura - y));
}

function doseAnimada() {
    return {
        /** Só o cartão recém-marcado faz o "Tomei 08:31" aparecer devagar (os outros já abrem prontos). */
        recemTomada: false,
        /** Enquanto o véu cobre tudo, o conteúdo fica invisível (o Chrome às vezes desenha ícones animados por cima do véu). */
        cobrindo: false,
        /** Do toque até o véu clarear, os botões do cartão (Tomei/Pular, depois Desfazer) ficam escondidos. */
        celebrando: false,

        origem(evento) {
            const cartao = this.$el.getBoundingClientRect();
            const botao = evento.currentTarget.getBoundingClientRect();

            return { x: botao.left + botao.width / 2 - cartao.left, y: botao.top + botao.height / 2 - cartao.top, largura: cartao.width, altura: cartao.height };
        },

        celebrar(evento) {
            if (reduzirMovimento()) {
                return;
            }
            const { x, y, largura, altura } = this.origem(evento);
            const raio = raioAteOsCantos(largura, altura, x, y);
            const veu = this.$refs.veu;
            const joinha = this.$refs.joinha;
            this.recemTomada = true;
            this.celebrando = true;
            setTimeout(() => { this.recemTomada = false; }, 2000);
            veu.getAnimations().forEach((a) => a.cancel());
            joinha.getAnimations().forEach((a) => a.cancel());

            // 1. Verde opaco cobre o cartão a partir do botão (~520 ms).
            const cobrir = veu.animate(
                [{ clipPath: `circle(0px at ${x}px ${y}px)`, opacity: 1 }, { clipPath: `circle(${raio}px at ${x}px ${y}px)`, opacity: 1 }],
                { duration: 520, easing: 'cubic-bezier(.4,0,.2,1)', fill: 'forwards' },
            );
            // 2. Joinha: salta com mola, dá um pulinho e balança; some junto com o véu.
            joinha.animate(
                [
                    { opacity: 0, transform: 'translateY(0) rotate(-25deg) scale(.2)', offset: 0 },
                    { opacity: 1, transform: 'translateY(0) rotate(-6deg) scale(1.18)', offset: 0.12 },
                    { opacity: 1, transform: 'translateY(0) rotate(0) scale(.94)', offset: 0.2 },
                    { opacity: 1, transform: 'translateY(-34px) rotate(-20deg) scale(1)', offset: 0.32 },
                    { opacity: 1, transform: 'translateY(0) rotate(16deg) scale(1)', offset: 0.42 },
                    { opacity: 1, transform: 'translateY(-14px) rotate(-11deg) scale(1)', offset: 0.5 },
                    { opacity: 1, transform: 'translateY(0) rotate(7deg) scale(1)', offset: 0.58 },
                    { opacity: 1, transform: 'translateY(0) rotate(-3deg) scale(1)', offset: 0.66 },
                    { opacity: 1, transform: 'translateY(0) rotate(0) scale(1)', offset: 0.74 },
                    { opacity: 0, transform: 'translateY(0) rotate(0) scale(1)', offset: 1 },
                ],
                { duration: 1500, delay: 140, easing: 'ease-out', fill: 'both' },
            );
            // 3. O véu clareia: os textos voltam por cima do verde (o cartão já está verde quando o Livewire responde).
            cobrir.finished.then(() => {
                this.cobrindo = true;
                setTimeout(() => { this.cobrindo = false; this.celebrando = false; }, 700);

                return veu.animate([{ opacity: 1 }, { opacity: 0 }], { duration: 420, delay: 700, easing: 'ease-out', fill: 'forwards' });
            })
                .then((a) => a.finished)
                .then(() => {
                    veu.getAnimations().forEach((a) => a.cancel());
                })
                .catch(() => {});
        },

        recolher(evento) {
            if (reduzirMovimento()) {
                return;
            }
            const { x, y, largura, altura } = this.origem(evento);
            const raio = raioAteOsCantos(largura, altura, x, y);
            const veu = this.$refs.veu;
            veu.getAnimations().forEach((a) => a.cancel());
            veu.animate(
                [{ clipPath: `circle(${raio}px at ${x}px ${y}px)`, opacity: 0.85 }, { clipPath: `circle(0px at ${x}px ${y}px)`, opacity: 0.85 }],
                { duration: 420, easing: 'cubic-bezier(.4,0,.2,1)' },
            );
        },
    };
}

/**
 * @param {{ desde: string|null, horario: string|null, cheio: boolean }} opcoes
 */
function proximaDose({ desde, horario, cheio }) {
    return {
        faltam: '',
        enchimento: cheio ? 1 : 0,
        // O que está desenhado persegue o alvo devagar: ao abrir, a barra enche de 0 até o ponto certo (como no app).
        mostrado: 0,
        quadro: null,
        relogio: null,

        init() {
            this.calcular();
            this.relogio = setInterval(() => this.calcular(), 20_000);
            const inicio = performance.now();
            const desenhar = (agora) => {
                this.desenhar((agora - inicio) / 1000);
                this.quadro = requestAnimationFrame(desenhar);
            };
            this.quadro = requestAnimationFrame(desenhar);
        },

        destroy() {
            cancelAnimationFrame(this.quadro);
            clearInterval(this.relogio);
        },

        calcular() {
            const agora = Date.now();
            const alvo = horario ? Date.parse(horario) : NaN;
            const comeco = desde ? Date.parse(desde) : NaN;
            if (cheio) {
                this.enchimento = 1;
                this.faltam = '';

                return;
            }
            this.enchimento = Number.isNaN(alvo) || Number.isNaN(comeco) || alvo <= comeco ? 0 : Math.min(1, Math.max(0, (agora - comeco) / (alvo - comeco)));
            const minutos = Number.isNaN(alvo) ? 0 : Math.floor((alvo - agora) / 60_000);
            this.faltam = minutos <= 0 ? '' : minutos < 60 ? `Faltam ${minutos} min` : minutos % 60 === 0 ? `Faltam ${minutos / 60} h` : `Faltam ${Math.floor(minutos / 60)} h ${minutos % 60} min`;
        },

        /** Ponta de líquido balançando no lugar: ondas estacionárias de comprimentos e ritmos diferentes, somadas. */
        desenhar(t) {
            const svg = this.$refs.liquido;
            if (!svg) {
                return;
            }
            const largura = svg.clientWidth;
            const altura = svg.clientHeight;
            const caminho = this.$refs.caminho;
            const parado = reduzirMovimento();
            this.mostrado = parado ? this.enchimento : this.mostrado + (this.enchimento - this.mostrado) * 0.05;
            const borda = largura * this.mostrado;
            if (this.mostrado <= 0.001) {
                caminho.setAttribute('d', '');

                return;
            }
            if (this.mostrado >= 0.999) {
                caminho.setAttribute('d', `M0 0H${largura}V${altura}H0Z`);

                return;
            }
            const amplitude = 9;
            const comprimento = 70;
            const tempo = parado ? 0 : t;
            const deslocamento = (y) => {
                const k = (2 * Math.PI * y) / comprimento;
                return amplitude * (
                    0.55 * Math.sin(k * 0.9 + 0.6) * Math.sin(tempo * 1.7)
                    + 0.35 * Math.sin(k * 1.7 + 2.1) * Math.sin(tempo * 2.9 + 1.3)
                    + 0.25 * Math.sin(k * 2.6 + 4.0) * Math.cos(tempo * 4.3 + 0.4)
                    + 0.15 * Math.sin(k * 0.45) * Math.sin(tempo * 0.8 + 2.2)
                );
            };
            let d = 'M0 0';
            for (let y = 0; y <= altura; y += 3) {
                d += `L${(borda + deslocamento(y)).toFixed(1)} ${y}`;
            }
            d += `L${(borda + deslocamento(altura)).toFixed(1)} ${altura}L0 ${altura}Z`;
            caminho.setAttribute('d', d);
        },
    };
}

document.addEventListener('alpine:init', () => {
    window.Alpine?.data('doseAnimada', doseAnimada);
    window.Alpine?.data('proximaDose', proximaDose);
});
