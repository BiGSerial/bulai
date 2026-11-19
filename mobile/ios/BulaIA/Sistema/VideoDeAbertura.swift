import AVFoundation
import SwiftUI
import UIKit

/// Vídeo de abertura (o mesmo do Android: 3 s do martelo na forja e 1 s na logo), só ao abrir o app do zero.
/// Não toca com "Reduzir movimento", não pede o áudio (sem som) e some sozinho se travar (limite de 6 s).
struct VideoDeAbertura: View {
    var aoTerminar: () -> Void
    @State private var reprodutor: AVPlayer?
    @State private var saindo = false

    static func deveTocar(reduzirMovimento: Bool) -> Bool {
        !reduzirMovimento && Bundle.main.url(forResource: "abertura", withExtension: "mp4") != nil
    }

    var body: some View {
        ZStack {
            Color(UIColor(hex: 0x5E5E5E)).ignoresSafeArea()
            if let reprodutor {
                CamadaDoVideo(reprodutor: reprodutor).ignoresSafeArea()
            }
        }
        .opacity(saindo ? 0 : 1)
        .accessibilityHidden(true)
        .task {
            guard let url = Bundle.main.url(forResource: "abertura", withExtension: "mp4") else {
                aoTerminar()
                return
            }
            try? AVAudioSession.sharedInstance().setCategory(.ambient, options: [.mixWithOthers])
            let novo = AVPlayer(url: url)
            novo.isMuted = true
            reprodutor = novo
            novo.play()
            // O que vier primeiro: o fim do vídeo ou o limite de 6 s (vídeo travado não segura o app).
            await withTaskGroup(of: Void.self) { grupo in
                grupo.addTask {
                    for await _ in NotificationCenter.default.notifications(named: .AVPlayerItemDidPlayToEndTime) {
                        return
                    }
                }
                grupo.addTask {
                    try? await Task.sleep(nanoseconds: 6_000_000_000)
                }
                await grupo.next()
                grupo.cancelAll()
            }
            withAnimation(.easeOut(duration: 0.35)) { saindo = true }
            try? await Task.sleep(nanoseconds: 350_000_000)
            aoTerminar()
        }
    }
}

private struct CamadaDoVideo: UIViewRepresentable {
    let reprodutor: AVPlayer

    func makeUIView(context _: Context) -> VistaDoVideo {
        let vista = VistaDoVideo()
        vista.camada.player = reprodutor
        vista.camada.videoGravity = .resizeAspect
        return vista
    }

    func updateUIView(_: VistaDoVideo, context _: Context) {}

    final class VistaDoVideo: UIView {
        override static var layerClass: AnyClass { AVPlayerLayer.self }
        var camada: AVPlayerLayer { layer as! AVPlayerLayer }
    }
}
