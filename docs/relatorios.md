# Relatórios administrativos

## Propósito
Gerar, sob demanda ou por comando, os relatórios que sustentam a operação e o TCC: analytics do período, inventário da base de conhecimento, consumo e custo de IA, auditoria de usuários e os registros de avaliação. Cada geração fica registrada com estado, parâmetros e arquivo, para download pela administração.

## Tipos (`app/Services/Reports/Types`)
| Chave | Rótulo | Formatos | Período | Conteúdo |
| --- | --- | --- | --- | --- |
| `analytics` | Analytics do período | JSON, CSV | sim | `AnalyticsService::overview` + linhas anonimizadas por par e por pergunta (mesmo conteúdo de `analytics:export`) |
| `knowledge_inventory` | Inventário da base de conhecimento | CSV | não | um documento por linha: título, chave, tipo, origem, idioma, situação de PDF/texto/índice, trechos, trechos com vetor, princípios ativos ligados, hash, datas |
| `ai_usage` | Consumo e custo de IA | CSV | sim | por dia × usuário × provedor × modelo × capacidade: chamadas, falhas, tokens (entrada, cache lido/gravado, saída, raciocínio), custo estimado, latência média |
| `user_audit` | Auditoria de usuários | CSV | sim | `user_audit_events` com e-mail do usuário e do ator, IP e detalhes (campos com `pass/senha/token/secret/hash` são removidos) |
| `evaluation` | Registros de avaliação | JSON | não | todos os arquivos de `storage/app/evaluation/runs` em um único JSON |

Anonimização: relatórios de consultas/perguntas não trazem nome nem e-mail de pacientes (apenas uuid e métricas). O de auditoria identifica contas (dado administrativo), nunca senhas ou tokens.

## Ciclo de vida
`ReportGenerator::start(tipo, formato, {from, to}, usuário)` cria `admin_reports` (PENDING) e dispara `App\Jobs\GenerateReport` conforme `BULAI_CONSULTATION_EXECUTION` (`after_response` padrão, `queue` ou `sync`). `generate()` grava o arquivo no disco `reports` (`storage/app/reports/<uuid>/<tipo>-<data>.<ext>`) e marca COMPLETED (tamanho e linhas) ou FAILED (`error_code`: `REPORT_UNKNOWN_TYPE`, `REPORT_INVALID_FORMAT`, `REPORT_GENERATION_FAILED`, `REPORT_JOB_FAILED`). `delete()` apaga o arquivo e o registro.

## Tabela `admin_reports`
`uuid`, `type`, `format`, `status` (PENDING/PROCESSING/COMPLETED/FAILED), `parameters` (jsonb: from, to), `path`, `size`, `rows`, `generated_by` (usuário, nulo quando por comando), `error_code`, `completed_at`. Migração `2026_11_05_090000_create_admin_reports_table.php`.

## Rotas e tela
- `GET /admin/relatorios` (`admin.reports.index`, `Livewire\Admin\Reports\Index`): formulário (tipo, formato, período quando aplicável), lista paginada com `wire:poll.3s` enquanto houver geração em andamento, download e exclusão com confirmação. Limite de 20 gerações por 10 minutos por administrador.
- `GET /admin/relatorios/{uuid}/baixar` (`admin.reports.download`, `ReportDownloadController`): só administradores; 404 se o arquivo não existir.

## Comando
```bash
php artisan reports:generate analytics --format=csv --from=2026-11-01 --to=2026-11-30
php artisan reports:generate knowledge_inventory
php artisan reports:generate evaluation
```
Roda de forma síncrona e imprime o caminho do arquivo.

## Retenção e operação
Os arquivos ficam em `storage/app/reports` (volume `shared/storage` em produção) até serem apagados pela tela. Não há expurgo automático: gere, baixe e apague. Em produção o disco `reports` precisa ser gravável pelo usuário do app/worker (mesmo volume de `storage/app`).

## Testes
`tests/Feature/Admin/ReportsAdminTest.php`: acesso só de admin, geração de cada tipo com armazenamento e download, remoção de campos sensíveis na auditoria, tela (gerar, validar período, apagar, formato inválido) e comando.

## Limitações
- Sem PDF: os formatos são CSV (planilha) e JSON (análise). 
- O relatório de avaliação depende dos arquivos locais de `storage/app/evaluation/runs` (não versionados).
- Sem agendamento: para relatórios periódicos, agende `reports:generate` em `routes/console.php`.

**Reformulação do admin (06/11/2026):** tipos e relatórios gerados em tabelas densas; a linha do tipo selecionado expande o formulário (formato e período); ações Baixar/Apagar na linha.
