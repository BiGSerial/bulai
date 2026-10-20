from .models import Block


def blocks_to_text(blocks: list[Block]) -> str:
    """Reconstrói o texto da página em ordem de leitura (linhas de cima para baixo, esquerda para direita).

    Blocos sem posição preservam a ordem original de chegada.
    """
    positioned = [b for b in blocks if b.bbox is not None]
    unpositioned = [b for b in blocks if b.bbox is None]
    lines: list[list[Block]] = []

    for block in sorted(positioned, key=lambda b: ((b.bbox[1] + b.bbox[3]) / 2, b.bbox[0])):
        center = (block.bbox[1] + block.bbox[3]) / 2
        if lines:
            last = lines[-1][-1]
            height = max(last.bbox[3] - last.bbox[1], 1)
            if abs(center - (last.bbox[1] + last.bbox[3]) / 2) <= height * 0.5:
                lines[-1].append(block)
                continue
        lines.append([block])

    rendered = [" ".join(b.text for b in sorted(line, key=lambda b: b.bbox[0])) for line in lines]
    rendered.extend(b.text for b in unpositioned)

    return "\n".join(line for line in rendered if line.strip())
