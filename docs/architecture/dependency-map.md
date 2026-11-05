# Mapa de dependências

```
Agente I (Anthropic) ─────────────────────────────────────────┐
B (ingredientes) ─► D1 (lookup/resolver) ─► D2 (análise RAG) ─┼─► E (PWA integrado) ─► G (experimentos)
C (indexação/recuperação) ────────────────► D2 ───────────────┘          ▲
F (segurança/privacidade) ─ transversal; H (infra) ─ transversal ────────┘
```

| Dependência | Bloqueia | Estado |
| --- | --- | --- |
| Resolução ingrediente PT↔EN | Análise de interações reais | Em construção (B) |
| Contrato `RetrievalResult`/`EvidenceReference` | D2, E | Definido nesta fase |
| Provedor de geração | Texto explicativo | `fake` e `openai_compatible` prontos; Anthropic em implementação |
| Serviço de embeddings | Recuperação vetorial/híbrida (resultado real) | **Ausente — decisão do usuário** |
| Dataset de referência rotulado | Avaliação das interações | Parcial: DDInter fornece rótulos de gravidade (gabarito automático ≠ validação clínica) |
| Profissional de saúde | Validação clínica | Externo ao código |
