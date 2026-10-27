"""Baixa/carrega os modelos do OCR com as MESMAS configurações do serviço (usado no build da imagem de produção)."""
from .config import Settings
from .engine import build_engine


def main() -> None:
    engine = build_engine(Settings.from_env())
    engine._engine()  # noqa: SLF001 - instancia o PaddleOCR, o que baixa os modelos para o cache


if __name__ == "__main__":
    main()
