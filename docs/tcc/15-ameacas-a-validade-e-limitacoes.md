# Ameaças à validade e limitações das camadas de OCR e IA local

Mapeamento no docx: Cap. 3, seção 3.5.3 (Limitações Assumidas do Protótipo) e 3.5.1 (Avaliação Técnica). Complementa a seção "Validade dos resultados" de `05-qualidade-operacao-e-evolucao.md`.

## Objetivo do tópico

Declarar, de forma explícita, o que não foi medido e os riscos para a interpretação dos achados dos tópicos 07 a 14.

## 1. Limitações técnicas declaradas

| Limitação | Efeito |
| --- | --- |
| Acurácia do Qwen2.5-1.5B e do PaddleOCR não medida em corpus | não se pode afirmar desempenho |
| Sem métricas formais (precisão, recall, F1, CER/WER) | achados são qualitativos |
| Limiares 85, 40, 0,85 e penalidades de qualidade não calibrados | decisões dependem de valores iniciais |
| Sem jobs dedicados de OCR e de embedding | OCR roda dentro de jobs de processamento/classificação |
| "Reanalisar" é síncrono | requisição longa no Admin |
| Sem RAG, embeddings ou IA principal | objetivos específicos de recuperação e geração do docx ainda não atendidos |
| Engine PaddleOCR sem teste automatizado real | só engine falso é testado |
| Aceites humanos não revalidados no catálogo ANVISA | possível associação de valor não confirmado regulatoriamente |

## 2. Ameaças à validade

### 2.1 Validade interna

Os achados empíricos vieram de 6 PDFs pendentes, em uma única máquina CPU, sem repetição controlada nem registro de hardware. Latências de 8 a 14 s não são generalizáveis.

### 2.2 Validade externa

A amostra não representa o Bulário ANVISA; o MVP do docx prevê 30 medicamentos de uso contínuo. Layouts de bulas variam por fabricante e época.

### 2.3 Validade de construto

"Resposta aceita" ou "rejeitada" pelas regras não equivale a "correta". Em um caso a rejeição manteve um erro da heurística. É necessário gabarito humano para medir acerto.

### 2.4 Validade de conclusão

Com n pequeno, não há significância estatística; resultados devem ser descritos como observações exploratórias, coerente com o tratamento exploratório do SUS na seção 3.5.3 do docx.

### 2.5 Viés e sensibilidade clínica

O classificador de ingestão não decide conteúdo clínico, mas erro de identificação (medicamento errado associado a uma bula) contaminaria o futuro RAG. A mitigação atual é quarentena, confirmação no catálogo e revisão humana. Sucesso técnico não equivale a segurança de uso farmacêutico.

## 3. Procedimento sugerido de medição (planejado)

1. Montar um corpus rotulado e autorizado, com PDFs nativos e digitalizados.
2. Medir CER/WER (OCR) e acurácia de nome, princípio ativo e tipo (heurística, IA, combinação).
3. Reportar a taxa de cada decisão (`ACCEPTED_HEURISTIC`, `AI_ACCEPTED`, `AI_SUGGESTED`, `AI_REJECTED`, `MANUAL_REVIEW`).
4. Registrar versão do código, modelo, hash, hardware, threads e contexto.

## 4. Limitações específicas da supervisão humana

- Os valores aceitos pelo administrador não são revalidados no catálogo ANVISA; a confirmação regulatória só ocorre no fluxo automático.
- O achado do omeprazol (IA correta no princípio ativo, heurística errada, tipo sugerido sem respaldo) é um único caso; não permite estimar taxas.
- Viés de automação: o revisor pode aceitar sugestões da IA por conveniência. O quadro mostra a heurística e o grounding lado a lado para mitigar, mas o efeito não foi medido.
- Uma nova análise descarta a decisão vigente; a recuperação depende da trilha de auditoria.
- Sem métricas formais de concordância entre revisor, heurística e IA.

## Evidências no repositório

- `docs/technical-foundation.md` (seções "Known Limitations" e as três seções de OCR/IA)
- `docs/tcc/05-qualidade-operacao-e-evolucao.md`, `06-evidencias-e-checklist.md`

## Resultados observados

Não se aplica (tópico declaratório).

## Lacunas / a verificar

- Confirmar a data, o commit e o hardware das observações com os 6 PDFs; ainda não registrados no repositório.
