"""Engine PaddleOCR (CPU). Importado sob demanda para que o resto do serviço e os testes não dependam do Paddle."""
from importlib import metadata

from ..engine import DocumentOcrEngine, EngineError
from ..layout import blocks_to_text
from ..models import Block, InputDocument, ParsedDocument, ParsedPage


class PaddleOcrEngine(DocumentOcrEngine):
    def __init__(
        self, language: str = "pt", dpi: int = 150, det_model: str = "", rec_model: str = "", enable_mkldnn: bool = False, cpu_threads: int = 2
    ) -> None:
        self._language = language
        self._dpi = dpi
        self._det_model = det_model
        self._rec_model = rec_model
        self._enable_mkldnn = enable_mkldnn
        self._cpu_threads = cpu_threads
        self._ocr = None

    def parse(self, document: InputDocument) -> ParsedDocument:
        try:
            import numpy as np
            import pypdfium2 as pdfium
        except ImportError as exc:  # pragma: no cover
            raise EngineError("dependências de renderização ausentes") from exc

        pdf = pdfium.PdfDocument(str(document.path))
        try:
            total = min(len(pdf), document.page_limit) if document.page_limit else len(pdf)
            if total > document.max_pages:
                raise EngineError("limite de páginas excedido")

            ocr = self._engine()
            pages: list[ParsedPage] = []
            for index in range(total):
                image = pdf[index].render(scale=self._dpi / 72).to_pil().convert("RGB")
                pages.append(self._parse_page(index + 1, ocr.predict(np.array(image))))

            return ParsedDocument("paddleocr", self._version(), pages)
        finally:
            pdf.close()

    def _engine(self):
        if self._ocr is None:
            try:
                from paddleocr import PaddleOCR
            except ImportError as exc:  # pragma: no cover
                raise EngineError("paddleocr não instalado") from exc

            options: dict = {
                "use_doc_orientation_classify": False,
                "use_doc_unwarping": False,
                "use_textline_orientation": False,
                "enable_mkldnn": self._enable_mkldnn,
                "cpu_threads": self._cpu_threads,
            }
            if self._det_model and self._rec_model:
                options.update(text_detection_model_name=self._det_model, text_recognition_model_name=self._rec_model)
            else:
                options["lang"] = self._language
            self._ocr = PaddleOCR(**options)

        return self._ocr

    @staticmethod
    def _parse_page(number: int, results) -> ParsedPage:
        blocks: list[Block] = []
        for result in results or []:
            texts = result.get("rec_texts") or []
            scores = result.get("rec_scores") or []
            boxes = result.get("rec_boxes")
            for i, text in enumerate(texts):
                if not str(text).strip():
                    continue
                bbox = [float(v) for v in boxes[i]] if boxes is not None and i < len(boxes) else None
                confidence = float(scores[i]) if i < len(scores) else None
                blocks.append(Block(text=str(text), confidence=confidence, bbox=bbox))

        confidences = [b.confidence for b in blocks if b.confidence is not None]
        return ParsedPage(
            page=number,
            blocks=blocks,
            text=blocks_to_text(blocks),
            confidence=round(sum(confidences) / len(confidences), 4) if confidences else None,
        )

    @staticmethod
    def _version() -> str | None:
        try:
            return metadata.version("paddleocr")
        except metadata.PackageNotFoundError:  # pragma: no cover
            return None
