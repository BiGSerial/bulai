// swift-tools-version: 6.0
import PackageDescription

/// Núcleo do BULA-IA para iOS: modelos da API `/api/mobile/v1`, cliente HTTP, sessão, cache, regras dos lembretes,
/// navegação e ViewModels. Não depende de UIKit/SwiftUI, então compila e testa também fora do Xcode (`swift test`).
let package = Package(
    name: "BulaiCore",
    platforms: [.iOS(.v17), .macOS(.v14)],
    products: [
        .library(name: "BulaiCore", targets: ["BulaiCore"]),
    ],
    targets: [
        .target(name: "BulaiCore"),
        .testTarget(name: "BulaiCoreTests", dependencies: ["BulaiCore"], resources: [.copy("Contratos")]),
    ]
)
