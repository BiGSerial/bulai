"""Contrato HTTP/interno do serviço. Nada aqui depende de um engine específico."""
from dataclasses import dataclass, field
from pathlib import Path

from pydantic import BaseModel


@dataclass(frozen=True)
class InputDocument:
    path: Path
    max_pages: int


@dataclass
class Block:
    text: str
    type: str = "text"
    confidence: float | None = None
    bbox: list[float] | None = None  # [x_min, y_min, x_max, y_max] em pixels da página renderizada


@dataclass
class ParsedPage:
    page: int
    blocks: list[Block] = field(default_factory=list)
    text: str | None = None
    confidence: float | None = None


@dataclass
class ParsedDocument:
    engine: str
    engine_version: str | None
    pages: list[ParsedPage]


class BlockModel(BaseModel):
    type: str
    text: str
    confidence: float | None = None
    bbox: list[float] | None = None


class PageModel(BaseModel):
    page: int
    text: str
    confidence: float | None = None
    blocks: list[BlockModel]


class ProcessingModel(BaseModel):
    duration_ms: int


class ParseResponse(BaseModel):
    engine: str
    engine_version: str | None = None
    pages: list[PageModel]
    processing: ProcessingModel
