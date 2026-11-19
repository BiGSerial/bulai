import AVFoundation
import Observation
import Speech

/// "Ouvir": lê a resposta ou a bula com a voz do iOS em português do Brasil.
@MainActor
@Observable
final class LeitorDeVoz: NSObject, AVSpeechSynthesizerDelegate {
    /// Identificador do texto sendo lido (para o botão virar "Parar de ouvir").
    private(set) var lendo: String?
    @ObservationIgnored private let sintetizador = AVSpeechSynthesizer()

    override init() {
        super.init()
        sintetizador.delegate = self
    }

    func alternar(_ texto: String, id: String) {
        if lendo == id {
            parar()
            return
        }
        parar()
        let sessao = AVAudioSession.sharedInstance()
        try? sessao.setCategory(.playback, mode: .spokenAudio, options: [.duckOthers])
        try? sessao.setActive(true)
        let fala = AVSpeechUtterance(string: texto)
        fala.voice = AVSpeechSynthesisVoice(language: "pt-BR")
        fala.rate = AVSpeechUtteranceDefaultSpeechRate * 0.95
        lendo = id
        sintetizador.speak(fala)
    }

    func parar() {
        if sintetizador.isSpeaking {
            sintetizador.stopSpeaking(at: .immediate)
        }
        lendo = nil
        try? AVAudioSession.sharedInstance().setActive(false, options: .notifyOthersOnDeactivation)
    }

    nonisolated func speechSynthesizer(_: AVSpeechSynthesizer, didFinish _: AVSpeechUtterance) {
        Task { @MainActor in self.lendo = nil }
    }

    nonisolated func speechSynthesizer(_: AVSpeechSynthesizer, didCancel _: AVSpeechUtterance) {
        Task { @MainActor in self.lendo = nil }
    }
}

/// Ditado da pergunta pelo reconhecimento de voz do iOS (pt-BR, no aparelho quando o modelo existe).
/// Pede microfone e reconhecimento de fala na primeira vez; sem permissão, explica e a pessoa digita
/// (o teclado do iOS também tem o ditado do sistema).
@MainActor
@Observable
final class DitadoDaPergunta {
    private(set) var ouvindo = false
    var erro: String?
    @ObservationIgnored private let motor = AVAudioEngine()
    @ObservationIgnored private var pedido: SFSpeechAudioBufferRecognitionRequest?
    @ObservationIgnored private var tarefa: SFSpeechRecognitionTask?
    @ObservationIgnored private let reconhecedor = SFSpeechRecognizer(locale: Locale(identifier: "pt-BR"))

    var disponivel: Bool { reconhecedor?.isAvailable ?? false }

    /// Começa a ouvir; `aoOuvir` recebe o texto parcial e o final.
    func comecar(aoOuvir: @escaping (String) -> Void) async {
        guard !ouvindo else {
            parar()
            return
        }
        erro = nil
        guard await DitadoDaPergunta.autorizar() else {
            erro = "Para falar a pergunta, permita o microfone e o reconhecimento de fala nos Ajustes do iPhone. Você também pode digitar."
            return
        }
        guard let reconhecedor, reconhecedor.isAvailable else {
            erro = "O reconhecimento de voz não está disponível agora. Digite a pergunta."
            return
        }
        do {
            let sessao = AVAudioSession.sharedInstance()
            try sessao.setCategory(.record, mode: .measurement, options: .duckOthers)
            try sessao.setActive(true, options: .notifyOthersOnDeactivation)
            let pedido = SFSpeechAudioBufferRecognitionRequest()
            pedido.shouldReportPartialResults = true
            if reconhecedor.supportsOnDeviceRecognition {
                pedido.requiresOnDeviceRecognition = true
            }
            self.pedido = pedido
            let entrada = motor.inputNode
            let formato = entrada.outputFormat(forBus: 0)
            entrada.removeTap(onBus: 0)
            entrada.installTap(onBus: 0, bufferSize: 1024, format: formato) { buffer, _ in
                pedido.append(buffer)
            }
            motor.prepare()
            try motor.start()
            ouvindo = true
            tarefa = reconhecedor.recognitionTask(with: pedido) { [weak self] resultado, falha in
                let texto = resultado?.bestTranscription.formattedString
                let terminou = resultado?.isFinal ?? false
                Task { @MainActor in
                    if let texto {
                        aoOuvir(texto)
                    }
                    if terminou || falha != nil {
                        self?.parar()
                    }
                }
            }
        } catch {
            erro = "Não deu para usar o microfone agora. Digite a pergunta."
            parar()
        }
    }

    func parar() {
        if motor.isRunning {
            motor.stop()
            motor.inputNode.removeTap(onBus: 0)
        }
        pedido?.endAudio()
        tarefa?.cancel()
        pedido = nil
        tarefa = nil
        ouvindo = false
        try? AVAudioSession.sharedInstance().setActive(false, options: .notifyOthersOnDeactivation)
    }

    private static func autorizar() async -> Bool {
        let fala = await withCheckedContinuation { continuacao in
            SFSpeechRecognizer.requestAuthorization { situacao in
                continuacao.resume(returning: situacao == .authorized)
            }
        }
        guard fala else {
            return false
        }
        return await AVAudioApplication.requestRecordPermission()
    }
}
