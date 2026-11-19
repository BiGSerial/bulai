import AVFoundation
import CoreImage.CIFilterBuiltins
import SwiftUI
import UIKit
import VisionKit

/// QR Code do convite de cuidador: sempre escuro sobre branco (leitura confiável nos dois temas).
enum GeradorDeQR {
    static func imagem(_ texto: String, lado: CGFloat = 640) -> UIImage? {
        let filtro = CIFilter.qrCodeGenerator()
        filtro.message = Data(texto.utf8)
        filtro.correctionLevel = "M"
        guard let saida = filtro.outputImage else {
            return nil
        }
        let escala = lado / saida.extent.width
        let ampliada = saida.transformed(by: CGAffineTransform(scaleX: escala, y: escala))
        guard let desenho = CIContext().createCGImage(ampliada, from: ampliada.extent) else {
            return nil
        }
        return UIImage(cgImage: desenho)
    }
}

/// Leitor de QR Code do convite (VisionKit). Precisa da câmera; sem ela (ou em aparelho sem suporte), a tela oferece
/// "Digitar o código", como no Android sem o Google Play Services.
struct LeitorDeQR: UIViewControllerRepresentable {
    var aoLer: (String) -> Void

    @MainActor static var disponivel: Bool {
        DataScannerViewController.isSupported && DataScannerViewController.isAvailable
    }

    func makeUIViewController(context: Context) -> DataScannerViewController {
        let leitor = DataScannerViewController(
            recognizedDataTypes: [.barcode(symbologies: [.qr])],
            qualityLevel: .balanced,
            recognizesMultipleItems: false,
            isHighFrameRateTrackingEnabled: false,
            isHighlightingEnabled: true
        )
        leitor.delegate = context.coordinator
        try? leitor.startScanning()
        return leitor
    }

    func updateUIViewController(_: DataScannerViewController, context _: Context) {}

    static func dismantleUIViewController(_ leitor: DataScannerViewController, coordinator _: Coordenador) {
        leitor.stopScanning()
    }

    func makeCoordinator() -> Coordenador {
        Coordenador(aoLer: aoLer)
    }

    final class Coordenador: NSObject, DataScannerViewControllerDelegate {
        let aoLer: (String) -> Void
        private var leu = false

        init(aoLer: @escaping (String) -> Void) {
            self.aoLer = aoLer
        }

        func dataScanner(_: DataScannerViewController, didAdd itens: [RecognizedItem], allItems _: [RecognizedItem]) {
            guard !leu else {
                return
            }
            for item in itens {
                if case let .barcode(codigo) = item, let texto = codigo.payloadStringValue, !texto.isEmpty {
                    leu = true
                    UINotificationFeedbackGenerator().notificationOccurred(.success)
                    aoLer(texto)
                    return
                }
            }
        }
    }
}

/// Situação da câmera para o leitor (pedido de permissão feito pelo próprio VisionKit ao abrir).
enum PermissaoDaCamera {
    static var negada: Bool {
        let situacao = AVCaptureDevice.authorizationStatus(for: .video)
        return situacao == .denied || situacao == .restricted
    }
}
