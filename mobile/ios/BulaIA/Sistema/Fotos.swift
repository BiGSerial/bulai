import PhotosUI
import SwiftUI
import UIKit

/// Foto da receita: reduzida a 2400 px no lado maior, já "de pé" (orientação aplicada) e em JPEG 85%, como no Android.
enum FotoDaReceita {
    static let ladoMaximo: CGFloat = 2400

    static func jpeg(_ imagem: UIImage) -> Data? {
        let tamanho = imagem.size
        guard tamanho.width > 0, tamanho.height > 0 else {
            return nil
        }
        let fator = min(1, ladoMaximo / max(tamanho.width, tamanho.height))
        let destino = CGSize(width: (tamanho.width * fator).rounded(), height: (tamanho.height * fator).rounded())
        let formato = UIGraphicsImageRendererFormat.default()
        formato.scale = 1
        // Desenhar aplica a orientação gravada (EXIF), então a imagem sobe já endireitada.
        let desenhada = UIGraphicsImageRenderer(size: destino, format: formato).image { _ in
            imagem.draw(in: CGRect(origin: .zero, size: destino))
        }
        return desenhada.jpegData(compressionQuality: 0.85)
    }

    /// Imagem escolhida no seletor de fotos do iOS (não precisa de permissão da galeria).
    static func carregar(_ item: PhotosPickerItem) async -> UIImage? {
        guard let dados = try? await item.loadTransferable(type: Data.self) else {
            return nil
        }
        return UIImage(data: dados)
    }
}

/// Câmera do iPhone para fotografar a receita (pede a permissão de câmera na primeira vez).
struct CameraDaReceita: UIViewControllerRepresentable {
    var aoTirar: (UIImage?) -> Void

    static var disponivel: Bool { UIImagePickerController.isSourceTypeAvailable(.camera) }

    func makeUIViewController(context: Context) -> UIImagePickerController {
        let camera = UIImagePickerController()
        camera.sourceType = .camera
        camera.cameraCaptureMode = .photo
        camera.allowsEditing = false
        camera.delegate = context.coordinator
        return camera
    }

    func updateUIViewController(_: UIImagePickerController, context _: Context) {}

    func makeCoordinator() -> Coordenador {
        Coordenador(aoTirar: aoTirar)
    }

    final class Coordenador: NSObject, UIImagePickerControllerDelegate, UINavigationControllerDelegate {
        let aoTirar: (UIImage?) -> Void

        init(aoTirar: @escaping (UIImage?) -> Void) {
            self.aoTirar = aoTirar
        }

        func imagePickerController(_: UIImagePickerController, didFinishPickingMediaWithInfo info: [UIImagePickerController.InfoKey: Any]) {
            aoTirar(info[.originalImage] as? UIImage)
        }

        func imagePickerControllerDidCancel(_: UIImagePickerController) {
            aoTirar(nil)
        }
    }
}
