"""Engines de teste que o processo filho consegue construir sozinho (`OCR_ENGINE=tests.fake_engine:Classe`).

Configuração por variáveis de ambiente, porque o filho (`spawn`) só recebe o `Settings`.
"""
import os
import time

from app.engine import DocumentOcrEngine, EngineError
from app.models import Block, InputDocument, ParsedDocument, ParsedPage


class PagedFakeEngine(DocumentOcrEngine):
    """Simula N páginas (`FAKE_PAGES`) com uma pausa por página (`FAKE_PAGE_SECONDS`), reportando o progresso."""

    def parse(self, document: InputDocument) -> ParsedDocument:
        total = int(os.getenv("FAKE_PAGES", "3"))
        if document.page_limit:
            total = min(total, document.page_limit)
        delay = float(os.getenv("FAKE_PAGE_SECONDS", "0.2"))
        pages: list[ParsedPage] = []
        if document.on_page is not None:
            document.on_page(0, total)
        for number in range(1, total + 1):
            time.sleep(delay)
            pages.append(ParsedPage(page=number, blocks=[Block(text=f"Página {number}")]))
            if document.on_page is not None:
                document.on_page(number, total)
        return ParsedDocument(engine="paged-fake", engine_version=None, pages=pages)


class FailingFakeEngine(DocumentOcrEngine):
    def parse(self, document: InputDocument) -> ParsedDocument:
        raise EngineError("documento ilegível")


class CrashingFakeEngine(DocumentOcrEngine):
    """Derruba o processo filho no meio do documento, como um segfault do engine."""

    def parse(self, document: InputDocument) -> ParsedDocument:
        if document.on_page is not None:
            document.on_page(0, 5)
        time.sleep(0.1)
        os._exit(1)
