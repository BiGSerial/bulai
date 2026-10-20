"""Engine PaddleOCR (CPU). Importado sob demanda para que o resto do serviço e os testes não dependam do Paddle."""
from importlib import metadata

from ..engine import DocumentOcrEngine, EngineError
from ..layout import blocks_to_text
from ..models import Block, InputDocument, ParsedDocument, ParsedPage


class PaddleOcrEngine(DocumentOcrEngine):
    def __init__(self, language: str = "pt", dpi: int = 200) -> None:
        self._language = language
        self._dpi = dpi
        self._ocr = None

    def parse(self, document: InputDocument) -> ParsedDocument:
        try:
            import numpy as np
            import pypdfium2 as pdfium
        except ImportError as exc:  # pragma: no cover
            raise EngineError("dependências de renderização ausentes") from exc

        pdf = pdfium.PdfDocument(str(document.path))
        try:
            if len(pdf) > document.max_pages:
                raise EngineError("limite de páginas excedido")

            ocr = self._engine()
            pages: list[ParsedPage] = []
            for index in range(len(pdf)):
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

            self._ocr = PaddleOCR(
                lang=self._language,
                use_doc_orientation_classify=False,
                use_doc_unwarping=False,
                use_textline_orientation=False,
            )

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
