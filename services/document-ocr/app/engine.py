from abc import ABC, abstractmethod

from .config import Settings
from .models import InputDocument, ParsedDocument


class EngineError(Exception):
    """Falha controlada do engine; a mensagem nunca é devolvida ao cliente."""


class DocumentOcrEngine(ABC):
    @abstractmethod
    def parse(self, document: InputDocument) -> ParsedDocument:
        """Converte um PDF em páginas numeradas (1..N) com texto e, se possível, blocos/posição/confiança."""


def build_engine(settings: Settings) -> DocumentOcrEngine:
    if settings.engine == "paddleocr":
        from .engines.paddle import PaddleOcrEngine

        return PaddleOcrEngine(
            language=settings.language, dpi=settings.render_dpi, det_model=settings.det_model, rec_model=settings.rec_model,
            enable_mkldnn=settings.enable_mkldnn, cpu_threads=settings.cpu_threads,
        )

    raise ValueError(f"Engine OCR não suportado: {settings.engine}")
