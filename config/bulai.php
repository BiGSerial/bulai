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
    ],
    'leaflet' => [
        // Bula humanizada: seções pedidas ao modelo e padrões de seção das bulas usados na recuperação de cada uma.
        'prompt_version' => 'humanized-leaflet-v1',
        'max_output_tokens' => (int) env('BULAI_LEAFLET_MAX_OUTPUT_TOKENS', 1400),
        'evidence_per_section' => (int) env('BULAI_LEAFLET_EVIDENCE_PER_SECTION', 3),
        'sections' => [
            'para_que_serve' => ['label' => 'Para que serve', 'query' => 'indicado para tratamento de indicações', 'patterns' => ['indica', 'para que', 'como este medicamento funciona']],
            'como_usar' => ['label' => 'Como usar', 'query' => 'modo de usar posologia dose administração horário', 'patterns' => ['como devo usar', 'posologia', 'modo de usar', 'como usar']],
            'beneficios' => ['label' => 'Benefícios esperados (prós)', 'query' => 'eficácia benefício alívio melhora resultado', 'patterns' => ['indica', 'resultados de efic', 'caracteristicas farmacol', 'como este medicamento funciona']],
            'riscos' => ['label' => 'Riscos e cuidados (contras)', 'query' => 'advertências precauções riscos cuidados gravidez idosos', 'patterns' => ['advert', 'precau', 'o que devo saber']],
            'efeitos_colaterais' => ['label' => 'Efeitos colaterais mais comuns', 'query' => 'reações adversas efeitos colaterais comuns muito comuns', 'patterns' => ['rea', 'males que este medicamento', 'efeitos']],
            'nao_use_se' => ['label' => 'Não use se', 'query' => 'contraindicações não deve ser usado hipersensibilidade alergia', 'patterns' => ['contraindica', 'quando nao devo usar', 'não devo usar']],
            'interacoes' => ['label' => 'Interações com outros remédios, álcool e alimentos', 'query' => 'interações medicamentosas álcool alimentos outros medicamentos', 'patterns' => ['interac']],
            'procure_ajuda' => ['label' => 'Procure ajuda se', 'query' => 'superdose quantidade maior do que a indicada procure socorro médico', 'patterns' => ['superdose', 'quantidade maior', 'o que fazer']],
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
        'prompt_version' => 'interaction-explanation-v1',
        'max_output_tokens' => (int) env('BULAI_GENERATION_MAX_OUTPUT_TOKENS', 700),
    ],
    'code_version' => env('BULAI_CODE_VERSION'),
];
