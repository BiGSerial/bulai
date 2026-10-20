import os
from dataclasses import dataclass


@dataclass(frozen=True)
class Settings:
    engine: str
    token: str | None
    max_pdf_bytes: int
    max_pages: int
    render_dpi: int
    language: str

    @staticmethod
    def from_env() -> "Settings":
        return Settings(
            engine=os.getenv("OCR_ENGINE", "paddleocr"),
            token=os.getenv("OCR_API_TOKEN") or None,
            max_pdf_bytes=int(os.getenv("OCR_MAX_PDF_BYTES", str(25 * 1024 * 1024))),
            max_pages=int(os.getenv("OCR_MAX_PAGES", "500")),
            render_dpi=int(os.getenv("OCR_RENDER_DPI", "200")),
            language=os.getenv("OCR_LANGUAGE", "pt"),
        )
