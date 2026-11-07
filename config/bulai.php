<?php

return [
    /*
    | Diretriz do produto. Vai para a interface (rodapé/onboarding), para os prompts de sistema e para a documentação.
    | O BULA-IA informa com base em evidências cadastradas e NUNCA substitui médico ou farmacêutico.
    */
    'guidelines' => [
        'disclaimer' => 'O BULA-IA é uma ferramenta informativa de apoio à leitura de bulas. Ele não substitui a orientação de médicos ou farmacêuticos, não diagnostica e não indica suspender, trocar ou ajustar doses. A ausência de uma interação nas fontes consultadas não significa que a combinação seja segura.',
        'evidence_notice' => 'Resposta baseada em evidências cadastradas (bulas, base de interações e material científico), conferida automaticamente.',
        'prompt_rule' => 'Este sistema nunca substitui médico ou farmacêutico: não diagnostique, não prescreva, não recomende suspender, substituir, interromper, iniciar ou ajustar dose de medicamento; quando a pergunta pedir conduta, oriente a falar com médico ou farmacêutico.',
    ],
    'consultation' => [
        'max_medicines' => (int) env('BULAI_CONSULTATION_MAX_MEDICINES', 6),
        'retention_days' => (int) env('BULAI_CONSULTATION_RETENTION_DAYS', 90),
        'search_limit_per_minute' => (int) env('BULAI_SEARCH_LIMIT_PER_MINUTE', 120),
        'rate_limit_per_hour' => (int) env('BULAI_CONSULTATION_RATE_LIMIT_PER_HOUR', 30),
        'queue' => env('BULAI_CONSULTATION_QUEUE', 'default'),
        // after_response: roda após responder ao usuário, sem worker; queue: fila (exige `queue:work`); sync: bloqueante.
        'execution' => env('BULAI_CONSULTATION_EXECUTION', 'after_response'),
        'stale_after_seconds' => (int) env('BULAI_CONSULTATION_STALE_AFTER_SECONDS', 300),
    ],
    'guard' => [
        // Triagem determinística de entradas livres (App\Services\Safety\InputGuard) antes de qualquer prompt.
        'min_length' => 5,
        'max_length' => (int) env('BULAI_GUARD_MAX_LENGTH', 500),
    ],
    'prescription' => [
        // Receita por foto: a imagem é analisada pelo provedor de visão, transcrita para JSON e descartada; só a extração fica.
        'max_image_kilobytes' => (int) env('BULAI_PRESCRIPTION_MAX_IMAGE_KB', 8192),
        'max_items' => (int) env('BULAI_PRESCRIPTION_MAX_ITEMS', 15),
        'prompt_version' => 'prescription-extraction-v1',
        'max_output_tokens' => (int) env('BULAI_PRESCRIPTION_MAX_OUTPUT_TOKENS', 1500),
        'rate_limit_per_hour' => (int) env('BULAI_PRESCRIPTION_RATE_LIMIT_PER_HOUR', 10),
    ],
    'treatment' => [
        // Programação do tratamento: horários sugeridos por número de doses ao dia (o paciente pode ajustar).
        'default_timezone' => env('BULAI_TREATMENT_TIMEZONE', 'America/Sao_Paulo'),
        'default_times' => [
            1 => ['08:00'],
            2 => ['08:00', '20:00'],
            3 => ['06:00', '14:00', '22:00'],
            4 => ['06:00', '12:00', '18:00', '00:00'],
            6 => ['06:00', '10:00', '14:00', '18:00', '22:00', '02:00'],
        ],
        // Janela do lembrete: avisa até N minutos antes do horário; depois de "late_after_minutes" a dose conta como atrasada.
        'reminder_lead_minutes' => (int) env('BULAI_REMINDER_LEAD_MINUTES', 10),
        'late_after_minutes' => (int) env('BULAI_DOSE_LATE_AFTER_MINUTES', 60),
        'reminder_queue' => env('BULAI_REMINDER_QUEUE', 'default'),
    ],
    'questions' => [
        // Perguntas livres: gravadas (pergunta, resposta, evidências e métricas) pelo mesmo prazo das consultas.
        'retention_days' => (int) env('BULAI_QUESTIONS_RETENTION_DAYS', env('BULAI_CONSULTATION_RETENTION_DAYS', 90)),
        'min_length' => 5,
        'max_length' => 400,
        // Segunda fonte: quando os trechos da busca não respondem (ou não vêm da seção adequada), a bula inteira é
        // enviada ao modelo, que devolve trecho literal + seção + página + confiança; o aceito fica gravado como
        // evidência auditável (knowledge_leaflet_facts) e é reaproveitado nas próximas perguntas.
        'leaflet_fallback' => [
            'enabled' => (bool) env('BULAI_LEAFLET_FALLBACK_ENABLED', true),
            'max_characters' => (int) env('BULAI_LEAFLET_FALLBACK_MAX_CHARACTERS', 150_000),
            'max_output_tokens' => (int) env('BULAI_LEAFLET_FALLBACK_MAX_OUTPUT_TOKENS', 1500),
            'min_confidence' => (float) env('BULAI_LEAFLET_FALLBACK_MIN_CONFIDENCE', 0.5),
        ],
    ],
    'ingredients' => [
        // Tradução por IA dos princípios ativos em português que aliases curados e regras ortográficas não ligaram ao
        // DDInter (inglês). A IA só propõe: o nome devolvido precisa existir no catálogo; o alias nasce `pending_review`.
        'ai_translation' => [
            'enabled' => (bool) env('BULAI_INGREDIENT_AI_TRANSLATION_ENABLED', true),
            'batch_size' => (int) env('BULAI_INGREDIENT_AI_TRANSLATION_BATCH', 25),
            'max_output_tokens' => (int) env('BULAI_INGREDIENT_AI_TRANSLATION_MAX_OUTPUT_TOKENS', 4000),
            // Limite por execução do construtor (nomes distintos); o restante fica para a próxima execução.
            'max_names_per_run' => (int) env('BULAI_INGREDIENT_AI_TRANSLATION_MAX_NAMES', 1200),
        ],
    ],
    'leaflet' => [
        // Bula humanizada: seções pedidas ao modelo e padrões de seção das bulas usados na recuperação de cada uma.
        'prompt_version' => 'humanized-leaflet-v1',
        'max_output_tokens' => (int) env('BULAI_LEAFLET_MAX_OUTPUT_TOKENS', 1400),
        'evidence_per_section' => (int) env('BULAI_LEAFLET_EVIDENCE_PER_SECTION', 3),
        // `keywords`: palavras da pergunta do paciente que pedem essa seção (QuestionIntentDetector; comparação normalizada).
        'sections' => [
            'para_que_serve' => ['label' => 'Para que serve', 'query' => 'indicado para tratamento de indicações', 'patterns' => ['indica', 'para que', 'como este medicamento funciona'],
                'keywords' => ['para que serve', 'serve para', 'serve pra', 'indicado', 'indicacao', 'trata', 'funciona', 'para que e', 'pra que']],
            'como_usar' => ['label' => 'Como usar', 'query' => 'modo de usar posologia dose administração horário', 'patterns' => ['como devo usar', 'posologia', 'modo de usar', 'como usar'],
                'keywords' => ['como tomar', 'como devo tomar', 'como usar', 'como uso', 'como tomo', 'dose', 'doses', 'dosagem', 'quantas vezes', 'quantos comprimidos', 'horario', 'horarios', 'jejum', 'comida', 'alimento', 'alimentos', 'manha', 'noite', 'esqueci', 'esquecer', 'posologia', 'quanto tempo', 'antes de dormir', 'de estomago vazio', 'mastigar', 'partir']],
            'beneficios' => ['label' => 'Benefícios esperados (prós)', 'query' => 'eficácia benefício alívio melhora resultado', 'patterns' => ['indica', 'resultados de efic', 'caracteristicas farmacol', 'como este medicamento funciona'],
                'keywords' => ['beneficio', 'beneficios', 'melhora', 'resultado', 'faz efeito', 'fazer efeito', 'demora']],
            'riscos' => ['label' => 'Riscos e cuidados (contras)', 'query' => 'advertências precauções riscos cuidados gravidez idosos', 'patterns' => ['advert', 'precau', 'o que devo saber'],
                'keywords' => ['cuidado', 'cuidados', 'risco', 'riscos', 'perigoso', 'perigosa', 'seguro', 'segura', 'dirigir', 'advertencia', 'precaucao', 'figado', 'rins', 'rim', 'pressao', 'coracao', 'diabetes', 'diabetico', 'idoso', 'idosa', 'idosos', 'gravida', 'gravidez', 'amamentando', 'amamentacao']],
            'efeitos_colaterais' => ['label' => 'Efeitos colaterais mais comuns', 'query' => 'reações adversas efeitos colaterais comuns muito comuns', 'patterns' => ['rea', 'males que este medicamento', 'efeitos'],
                'keywords' => ['efeito', 'efeitos', 'colateral', 'colaterais', 'colatereais', 'reacao', 'reacoes', 'adversa', 'adversas', 'sono', 'tontura', 'enjoo', 'nausea', 'dor de cabeca', 'diarreia', 'engorda', 'emagrece', 'queda de cabelo', 'males', 'mal estar', 'sintoma', 'sintomas', 'da sono', 'faz mal']],
            'nao_use_se' => ['label' => 'Não use se', 'query' => 'contraindicações não deve ser usado hipersensibilidade alergia', 'patterns' => ['contraindica', 'quando nao devo usar', 'não devo usar'],
                'keywords' => ['nao posso tomar', 'contraindicado', 'contraindicacao', 'contraindicacoes', 'nao pode tomar', 'quem nao pode', 'alergia', 'alergico', 'alergica', 'gravida', 'gravidez', 'amamentando', 'amamentacao', 'crianca', 'criancas']],
            'interacoes' => ['label' => 'Interações com outros remédios, álcool e alimentos', 'query' => 'interações medicamentosas álcool alimentos outros medicamentos', 'patterns' => ['interac'],
                'keywords' => ['junto', 'juntos', 'juntas', 'cerveja', 'alcool', 'vinho', 'bebida', 'cafe', 'leite', 'interage', 'interacao', 'interacoes', 'misturar', 'mesmo tempo', 'combinar', 'outro remedio', 'outros remedios', 'tomar com', 'com o', 'com a']],
            'procure_ajuda' => ['label' => 'Procure ajuda se', 'query' => 'superdose quantidade maior do que a indicada procure socorro médico', 'patterns' => ['superdose', 'quantidade maior', 'o que fazer'],
                'keywords' => ['superdose', 'tomei demais', 'dose maior', 'a mais', 'dobrada', 'dobrei', 'exagerei', 'intoxicacao', 'socorro', 'emergencia', 'o que fazer se', 'tomei dois', 'tomei duas']],
        ],
    ],
    'retrieval' => [
        // lexical | vector | hybrid. Vetorial/híbrido exigem embeddings indexados com o modelo configurado.
        'mode' => env('BULAI_RETRIEVAL_MODE', 'lexical'),
        'evidence_per_pair' => (int) env('BULAI_RETRIEVAL_EVIDENCE_PER_PAIR', 4),
        'excerpt_max_characters' => (int) env('BULAI_RETRIEVAL_EXCERPT_MAX', 1200),
        'sections' => ['interac', 'advertencias', 'precaucoes', 'contraindica'],
    ],
    'generation' => [
        'enabled' => (bool) env('BULAI_GENERATION_ENABLED', true),
        // v2 (07/11/2026): tom de conversa para pessoas idosas (resposta direta primeiro, "você", termos difíceis explicados entre parênteses).
        'prompt_version' => 'interaction-explanation-v2',
        'max_output_tokens' => (int) env('BULAI_GENERATION_MAX_OUTPUT_TOKENS', 700),
    ],
    'code_version' => env('BULAI_CODE_VERSION'),
];
