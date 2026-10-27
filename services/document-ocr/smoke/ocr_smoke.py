"""Teste de fumaça do container de OCR: gera um PDF que contém apenas uma IMAGEM com texto (sem texto nativo) e confere se o
serviço realmente reconhece o texto. Usado pelo pipeline (.github/workflows/ocr-image.yml) e pode ser rodado à mão:

    python services/document-ocr/smoke/ocr_smoke.py http://127.0.0.1:8090 [TOKEN]

Requer Pillow no host. Sai com 0 se o OCR respondeu com o texto esperado; 1 caso contrário.
"""
import io
import json
import sys
import time
import urllib.error
import urllib.request
import uuid

from PIL import Image, ImageDraw, ImageFont

EXPECTED = ("glifage", "metformina", "merck")
LINES = ["GLIFAGE", "cloridrato de metformina", "Merck S/A", "Bula para o profissional de saude"]


def build_pdf() -> bytes:
    image = Image.new("RGB", (1240, 1000), "white")
    draw = ImageDraw.Draw(image)
    font = ImageFont.load_default(size=56)
    for index, line in enumerate(LINES):
        draw.text((80, 120 + index * 150), line, fill="black", font=font)
    buffer = io.BytesIO()
    image.save(buffer, "PDF", resolution=150)
    return buffer.getvalue()


def multipart(fields: dict[str, str], file_bytes: bytes) -> tuple[bytes, str]:
    boundary = uuid.uuid4().hex
    body = b""
    for name, value in fields.items():
        body += f'--{boundary}\r\nContent-Disposition: form-data; name="{name}"\r\n\r\n{value}\r\n'.encode()
    body += f'--{boundary}\r\nContent-Disposition: form-data; name="file"; filename="smoke.pdf"\r\nContent-Type: application/pdf\r\n\r\n'.encode()
    body += file_bytes + f"\r\n--{boundary}--\r\n".encode()
    return body, f"multipart/form-data; boundary={boundary}"


def main() -> int:
    base = sys.argv[1].rstrip("/")
    token = sys.argv[2] if len(sys.argv) > 2 else None
    body, content_type = multipart({"page_limit": "1"}, build_pdf())
    request = urllib.request.Request(f"{base}/v1/documents/parse", body, {"Content-Type": content_type})
    if token:
        request.add_header("Authorization", f"Bearer {token}")
    started = time.time()
    try:
        with urllib.request.urlopen(request, timeout=300) as response:
            payload = json.load(response)
    except urllib.error.HTTPError as error:
        print(f"FALHA: HTTP {error.code} do serviço de OCR")
        return 1
    elapsed = time.time() - started
    text = " ".join(page.get("text", "") for page in payload.get("pages", [])).lower()
    missing = [word for word in EXPECTED if word not in text]
    print(f"OCR em {elapsed:.1f}s, motor={payload.get('engine')}, páginas={len(payload.get('pages', []))}, texto: {text[:160]!r}")
    if missing:
        print(f"FALHA: o OCR não reconheceu {missing}")
        return 1
    print("OK: o OCR reconheceu o texto esperado")
    return 0


if __name__ == "__main__":
    sys.exit(main())
