#!/usr/bin/env python3
"""Gera os recursos visuais do app iOS a partir da marca em public/brand (sem dependências além do Python 3).

- AppIcon 1024x1024 (PNG sem transparência; o iOS arredonda os cantos) a partir de bulai-icone-app.svg;
- Logo (claro e escuro) e Símbolo como SVG vetorial no catálogo de imagens;
- cores da marca (claro/escuro) para o app e o widget.

Uso: python3 mobile/ios/scripts/gerar-recursos.py   (rodar da raiz do repositório)
"""
import json
import os
import struct
import zlib

RAIZ = os.path.abspath(os.path.join(os.path.dirname(__file__), "..", "..", ".."))
MARCA = os.path.join(RAIZ, "public", "brand")
IOS = os.path.join(RAIZ, "mobile", "ios")
ASSETS = os.path.join(IOS, "BulaIA", "Recursos", "Assets.xcassets")
ASSETS_WIDGET = os.path.join(IOS, "Widget", "Assets.xcassets")
INFO = {"author": "xcode", "version": 1}


def hexa(cor):
    cor = cor.lstrip("#")
    return tuple(int(cor[i:i + 2], 16) for i in (0, 2, 4))


def png(caminho, largura, altura, pixels):
    """pixels: bytearray RGB, linha a linha."""
    linhas = b"".join(b"\x00" + bytes(pixels[y * largura * 3:(y + 1) * largura * 3]) for y in range(altura))

    def bloco(tipo, dados):
        return struct.pack(">I", len(dados)) + tipo + dados + struct.pack(">I", zlib.crc32(tipo + dados) & 0xFFFFFFFF)

    with open(caminho, "wb") as arquivo:
        arquivo.write(b"\x89PNG\r\n\x1a\n")
        arquivo.write(bloco(b"IHDR", struct.pack(">IIBBBBB", largura, altura, 8, 2, 0, 0, 0)))
        arquivo.write(bloco(b"IDAT", zlib.compress(linhas, 9)))
        arquivo.write(bloco(b"IEND", b""))


def icone(lado=1024, amostras=3):
    """Símbolo do bulai-icone-app.svg: balão em duas metades (creme e laranja), ponta e os três pontos."""
    fundo, creme, laranja = hexa("#12344D"), hexa("#F7F4EE"), hexa("#F0913F")
    escala = 6.944444444444445 * lado / 1024
    deslocamento_x, deslocamento_y = 137.0 * lado / 1024, 234.22222222222223 * lado / 1024

    def local(x, y):
        return (x - deslocamento_x) / escala + 6, (y - deslocamento_y) / escala + 24

    def no_triangulo(px, py):
        a, b, c = (22, 72), (14, 100), (46, 78)

        def lado_(p, q, r):
            return (p[0] - r[0]) * (q[1] - r[1]) - (q[0] - r[0]) * (p[1] - r[1])

        d1, d2, d3 = lado_((px, py), a, b), lado_((px, py), b, c), lado_((px, py), c, a)
        negativo = d1 < 0 or d2 < 0 or d3 < 0
        positivo = d1 > 0 or d2 > 0 or d3 > 0
        return not (negativo and positivo)

    def cor_local(x, y):
        for cx in (38, 60, 82):
            if (x - cx) ** 2 + (y - 56) ** 2 <= 36:
                return fundo
        if 36 <= x <= 58.5 and 30 <= y <= 82 or x < 36 and (x - 36) ** 2 + (y - 56) ** 2 <= 676:
            return creme
        if 61.5 <= x <= 84 and 30 <= y <= 82 or x > 84 and (x - 84) ** 2 + (y - 56) ** 2 <= 676:
            return laranja
        if no_triangulo(x, y):
            return creme
        return fundo

    pixels = bytearray(fundo * (lado * lado))
    x0, y0 = int(deslocamento_x + escala * 3), int(deslocamento_y + escala * 5)
    x1, y1 = int(deslocamento_x + escala * 106) + 2, int(deslocamento_y + escala * 78) + 2
    passo = 1 / amostras
    for y in range(max(0, y0), min(lado, y1)):
        for x in range(max(0, x0), min(lado, x1)):
            soma = [0, 0, 0]
            for i in range(amostras):
                for j in range(amostras):
                    cor = cor_local(*local(x + (i + 0.5) * passo, y + (j + 0.5) * passo))
                    soma[0] += cor[0]
                    soma[1] += cor[1]
                    soma[2] += cor[2]
            total = amostras * amostras
            indice = (y * lado + x) * 3
            pixels[indice:indice + 3] = bytes(round(c / total) for c in soma)
    return pixels


def escrever_json(pasta, conteudo):
    os.makedirs(pasta, exist_ok=True)
    with open(os.path.join(pasta, "Contents.json"), "w") as arquivo:
        json.dump(conteudo, arquivo, indent=2, ensure_ascii=False)
        arquivo.write("\n")


def cor(nome_da_pasta, claro, escuro):
    def componentes(valor):
        r, g, b = hexa(valor)
        return {"color-space": "srgb", "components": {"alpha": "1.000", "red": f"0x{r:02X}", "green": f"0x{g:02X}", "blue": f"0x{b:02X}"}}

    escrever_json(nome_da_pasta, {
        "colors": [
            {"color": componentes(claro), "idiom": "universal"},
            {"appearances": [{"appearance": "luminosity", "value": "dark"}], "color": componentes(escuro), "idiom": "universal"},
        ],
        "info": INFO,
    })


def imagem_vetorial(pasta, claro, escuro=None):
    imagens = [{"filename": os.path.basename(claro), "idiom": "universal"}]
    if escuro:
        imagens.append({"appearances": [{"appearance": "luminosity", "value": "dark"}], "filename": os.path.basename(escuro), "idiom": "universal"})
    escrever_json(pasta, {"images": imagens, "info": INFO, "properties": {"preserves-vector-representation": True}})


def main():
    escrever_json(ASSETS, {"info": INFO})
    escrever_json(ASSETS_WIDGET, {"info": INFO})

    pasta_icone = os.path.join(ASSETS, "AppIcon.appiconset")
    escrever_json(pasta_icone, {"images": [{"filename": "AppIcon.png", "idiom": "universal", "platform": "ios", "size": "1024x1024"}], "info": INFO})
    png(os.path.join(pasta_icone, "AppIcon.png"), 1024, 1024, icone())

    logo = open(os.path.join(MARCA, "bulai-logo-horizontal-transparente.svg")).read()
    pasta_logo = os.path.join(ASSETS, "Logo.imageset")
    os.makedirs(pasta_logo, exist_ok=True)
    open(os.path.join(pasta_logo, "logo.svg"), "w").write(logo)
    # Modo escuro: texto e metade azul claros; os três pontos no azul da marca (como o logo noturno do Android).
    open(os.path.join(pasta_logo, "logo-escuro.svg"), "w").write(logo.replace("#12344D", "#E8EEF4").replace("#FFFFFF", "#12344D"))
    imagem_vetorial(pasta_logo, "logo.svg", "logo-escuro.svg")

    pasta_simbolo = os.path.join(ASSETS, "Simbolo.imageset")
    os.makedirs(pasta_simbolo, exist_ok=True)
    open(os.path.join(pasta_simbolo, "simbolo.svg"), "w").write(open(os.path.join(MARCA, "bulai-simbolo.svg")).read())
    imagem_vetorial(pasta_simbolo, "simbolo.svg")

    cor(os.path.join(ASSETS, "AccentColor.colorset"), "#12344D", "#9DBCD4")
    cor(os.path.join(ASSETS, "FundoAbertura.colorset"), "#F7F4EE", "#0E1821")
    cor(os.path.join(ASSETS_WIDGET, "AccentColor.colorset"), "#12344D", "#9DBCD4")
    cor(os.path.join(ASSETS_WIDGET, "FundoDoWidget.colorset"), "#FFFFFF", "#172431")
    cor(os.path.join(ASSETS_WIDGET, "MarcaDoWidget.colorset"), "#12344D", "#9DBCD4")
    print("Recursos gerados em", os.path.relpath(ASSETS, RAIZ), "e", os.path.relpath(ASSETS_WIDGET, RAIZ))


if __name__ == "__main__":
    main()
