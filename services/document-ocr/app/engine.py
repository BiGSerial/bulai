from abc import ABC, abstractmethod
from importlib import import_module

from .config import Settings
from .models import InputDocument, ParsedDocument


class EngineError(Exception):
    """Falha controlada do engine; a mensagem nunca é devolvida ao cliente."""


class DocumentOcrEngine(ABC):
    @abstractmethod
    def parse(self, document: InputDocument) -> ParsedDocument:
        """Converte um PDF em páginas numeradas (1..N) com texto e, se possível, blocos/posição/confiança.

        Engines que processam página a página devem chamar `document.on_page(página, total)` após cada página.
        """

    def warmup(self) -> None:
        """Carrega modelos antecipadamente (chamado uma vez no processo filho, antes do primeiro documento)."""


def build_engine(settings: Settings) -> DocumentOcrEngine:
    if settings.engine == "paddleocr":
        from .engines.paddle import PaddleOcrEngine

        return PaddleOcrEngine(
            language=settings.language, dpi=settings.render_dpi, det_model=settings.det_model, rec_model=settings.rec_model,
            enable_mkldnn=settings.enable_mkldnn, cpu_threads=settings.cpu_threads,
        )

    # "pacote.modulo:Classe" permite engines de teste que o processo filho consegue construir sozinho.
    if ":" in settings.engine:
        module_name, _, class_name = settings.engine.partition(":")
        engine_class = getattr(import_module(module_name), class_name)
        if not (isinstance(engine_class, type) and issubclass(engine_class, DocumentOcrEngine)):
            raise ValueError(f"Engine OCR inválido: {settings.engine}")
        return engine_class()

    raise ValueError(f"Engine OCR não suportado: {settings.engine}")
