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
    max_concurrency: int = 1
    # Modelos leves (mobile) e oneDNN desligado: o oneDNN do Paddle 3.x quebra na CPU (ConvertPirAttribute2RuntimeAttribute)
    # e os modelos "medium" levam ~1 min por página. Valores vazios deixam o Paddle escolher pelo idioma.
    det_model: str = "PP-OCRv5_mobile_det"
    rec_model: str = "latin_PP-OCRv5_mobile_rec"
    enable_mkldnn: bool = False
    cpu_threads: int = 2

    @staticmethod
    def from_env() -> "Settings":
        return Settings(
            engine=os.getenv("OCR_ENGINE", "paddleocr"),
            token=os.getenv("OCR_API_TOKEN") or None,
            max_pdf_bytes=int(os.getenv("OCR_MAX_PDF_BYTES", str(25 * 1024 * 1024))),
            max_pages=int(os.getenv("OCR_MAX_PAGES", "500")),
            render_dpi=int(os.getenv("OCR_RENDER_DPI", "150")),
            language=os.getenv("OCR_LANGUAGE", "pt"),
            max_concurrency=max(1, int(os.getenv("OCR_MAX_CONCURRENCY", "1"))),
            det_model=os.getenv("OCR_DET_MODEL", "PP-OCRv5_mobile_det"),
            rec_model=os.getenv("OCR_REC_MODEL", "latin_PP-OCRv5_mobile_rec"),
            enable_mkldnn=os.getenv("OCR_ENABLE_MKLDNN", "0").lower() in {"1", "true", "yes"},
            cpu_threads=max(1, int(os.getenv("OCR_CPU_THREADS", "2"))),
        )
