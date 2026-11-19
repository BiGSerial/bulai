import SwiftUI
import UIKit

/// Paleta do site e do Android (resources/css/app.css, ui/tema/Tema.kt): marca (azul), destaque (laranja), superfícies
/// creme no claro e azul-noite no escuro. Cada cor tem as duas versões e segue o tema escolhido na Conta.
enum Cores {
    static let marca = dinamica(0x12344D, 0x9DBCD4)
    static let marcaSuave = dinamica(0xD8E3EC, 0x22394F)
    static let naMarcaSuave = dinamica(0x091D2C, 0xDDE8F1)
    static let destaque = dinamica(0xD9661F, 0xEF9E6A)
    static let naDestaque = dinamica(0xFFFFFF, 0x4D220A)
    static let destaqueSuave = dinamica(0xFBE3D2, 0x70310F)
    static let naDestaqueSuave = dinamica(0x944114, 0xFBE3D2)
    static let fundo = dinamica(0xF7F4EE, 0x0E1821)
    static let superficie = dinamica(0xFFFFFF, 0x172431)
    static let superficieElevada = dinamica(0xFBFAF7, 0x1C2B38)
    static let superficieVariante = dinamica(0xECE6DA, 0x22303C)
    static let texto = dinamica(0x0F172A, 0xE8EEF4)
    static let textoSuave = dinamica(0x334155, 0xC2CCD6)
    static let contorno = dinamica(0xE2E8F0, 0x2C3B48)
    static let sucesso = dinamica(0x1D7A46, 0x7FD3A1)
    static let sucessoFundo = dinamica(0xDDF2E5, 0x173B28)
    static let erro = dinamica(0xB42318, 0xFFB4AB)
    static let erroFundo = dinamica(0xFDE7E5, 0x5C1A14)
    static let naMarca = dinamica(0xFFFFFF, 0x091D2C)
    static let esqueleto = dinamica(0xECE6DA, 0x22303C)
    static let esqueletoBrilho = dinamica(0xFBFAF7, 0x2C3B48)

    /// Tom do servidor (`danger`, `warning`, `success`, `brand`, `info`…) → cor de destaque e fundo.
    static func doTom(_ tom: String) -> (cor: Color, fundo: Color) {
        switch tom {
        case "danger", "alergia", "erro", "major", "urgencia": (erro, erroFundo)
        case "warning", "moderate", "atencao", "cuidado": (naDestaqueSuave, destaqueSuave)
        case "success", "none", "ok": (sucesso, sucessoFundo)
        case "acolhimento", "info", "minor": (marca, marcaSuave)
        default: (marca, marcaSuave)
        }
    }

    private static func dinamica(_ claro: UInt32, _ escuro: UInt32) -> Color {
        Color(UIColor { tracos in
            UIColor(hex: tracos.userInterfaceStyle == .dark ? escuro : claro)
        })
    }
}

extension UIColor {
    convenience init(hex: UInt32) {
        self.init(red: CGFloat((hex >> 16) & 0xFF) / 255, green: CGFloat((hex >> 8) & 0xFF) / 255, blue: CGFloat(hex & 0xFF) / 255, alpha: 1)
    }
}

/// Letra grande e sempre escalando com o Dynamic Type do sistema (estilos de texto, nunca tamanho fixo).
enum Fonte {
    static let titulo = Font.system(.title2, design: .rounded).weight(.bold)
    static let tituloGrande = Font.system(.largeTitle, design: .rounded).weight(.bold)
    static let subtitulo = Font.system(.title3, design: .rounded).weight(.semibold)
    static let corpo = Font.body
    static let corpoForte = Font.body.weight(.semibold)
    static let rotulo = Font.headline
    static let pequena = Font.subheadline
}
