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
        // Modo cuidador: quem cuida é avisado da dose atrasada até N minutos depois de ela virar "atrasada" (evita
        // avisar doses antigas de uma vez quando o vínculo é aprovado ou o agendador volta).
        'caregiver_alert_window_minutes' => (int) env('BULAI_CAREGIVER_ALERT_WINDOW_MINUTES', 120),
    ],
    'questions' => [
        // Perguntas livres: gravadas (pergunta, resposta, evidências e métricas) pelo mesmo prazo das consultas.
        'retention_days' => (int) env('BULAI_QUESTIONS_RETENTION_DAYS', env('BULAI_CONSULTATION_RETENTION_DAYS', 90)),
        'min_length' => 5,
        'max_length' => 400,
        // Segunda fonte: quando os trechos da busca não respondem (ou não vêm da seção adequada), a bula inteira é
        // enviada ao modelo, que devolve trecho literal + seção + página + confiança; o aceito fica gravado como
        // evidência auditável (knowledge_leaflet_facts) e é reaproveitado nas próximas perguntas.
        // Intenções compostas do Perguntar: uma pergunta como "posso tomar X?" pede várias partes da bula (para que serve,
        // quando não usar, cuidados). `expands` lista as seções de `leaflet.sections` consultadas; `keywords` são palavras da
        // pergunta (normalizadas) que ativam a intenção. Sem nenhuma intenção reconhecida a pergunta é tratada como geral.
        'intents' => [
            'seguranca_de_uso' => [
                'label' => 'Segurança de uso',
                'expands' => ['para_que_serve', 'nao_use_se', 'riscos'],
                'keywords' => ['posso tomar', 'posso usar', 'posso', 'pode tomar', 'pode usar', 'devo tomar', 'devo usar', 'faz mal', 'e seguro', 'tem problema', 'tem perigo', 'tenho que tomar'],
            ],
            'comparacao' => [
                'label' => 'Comparação entre medicamentos',
                'expands' => ['para_que_serve', 'riscos', 'efeitos_colaterais'],
                'keywords' => ['diferenca', 'melhor que', 'pior que', 'mais forte', 'mais fraco', 'qual e melhor', 'comparar', 'em vez de', 'no lugar de'],
            ],
        ],
        // Seções consultadas quando nenhuma intenção é reconhecida ("ibuprofeno?", "me fale do ibuprofeno").
        'general_sections' => ['para_que_serve', 'riscos'],
        'max_sections' => 4,
        'evidence_per_section' => (int) env('BULAI_QUESTION_EVIDENCE_PER_SECTION', 2),
        // Sintomas: "para dor de cabeça" é o que a pessoa quer tratar (indicação); "dá dor de cabeça" é efeito adverso. Por isso os
        // sintomas não ficam nas palavras-chave de efeitos colaterais: o papel deles é decidido pelo QuestionIntentDetector.
        'symptoms' => [
            'dor de cabeca', 'dor de dente', 'dor de garganta', 'dor nas costas', 'dor no corpo', 'dores no corpo', 'dor muscular', 'dor',
            'dores', 'enxaqueca', 'febre', 'colica', 'colicas', 'gripe', 'resfriado', 'tosse', 'sono', 'insonia', 'tontura', 'enjoo', 'nausea',
            'vomito', 'diarreia', 'azia', 'inflamacao', 'coceira', 'ansiedade', 'pressao alta', 'queda de cabelo', 'mal estar',
        ],
        // Guia de resposta por intenção canônica (QuestionIntentDetector::INTENTS), entregue ao modelo junto com a intenção
        // principal. É o que faz a resposta começar pela pergunta feita e só acrescentar o que é pertinente.
        'response_guides' => [
            'INDICATION' => 'Responda na primeira frase se o remédio serve para o que foi perguntado (sim, ou que essa não é uma indicação do remédio) e para que ele é indicado. Se a bula indica o remédio para dor em geral (analgésico) ou para febre (antitérmico), uma dor comum (de cabeça, de dente, muscular, de garganta) ou a febre estão dentro dessa indicação: diga que sim, explicando que ele é indicado para dor ou febre em geral, sem afirmar que a bula cita essa dor pelo nome. Não fale de contraindicações, efeitos colaterais, superdose nem cuidados, a menos que a pessoa tenha perguntado. Uma a três frases.',
            'PERSONAL_USE' => 'A primeira frase responde à dúvida concreta (por exemplo, para que o remédio serve, quando a pessoa disse a finalidade). Depois, uma única frase de cautela proporcional: se é adequado para ela depende de alergias, outros remédios e condições de saúde. Nunca afirme que é seguro para ela. Sem listar todas as contraindicações. No máximo uma pergunta útil (por exemplo, se já teve reação a esse remédio).',
            'DOSAGE' => 'Diga a dose que a bula indica para a apresentação e a população que aparecem nas evidências (adulto, criança, gotas, comprimido), com os números exatamente como estão. Não calcule dose individual. Se a dose depende de peso, idade ou apresentação que a pessoa não informou, diga isso e pergunte o que falta.',
            'CONTRAINDICATION' => 'Comece pelo que a bula diz sobre quem não deve usar, ligado ao que a pessoa contou (por exemplo, alergia). Se a pessoa relata alergia ao próprio remédio e a bula contraindica o uso nesse caso, diga isso com clareza e que o médico ou o farmacêutico pode indicar outra opção.',
            'ADVERSE_EFFECT' => 'Comece pelos efeitos mais comuns, em linguagem simples; cite sinais graves só se a bula os destacar. Sem sermão e sem perguntas.',
            'DRUG_INTERACTION' => 'Responda sobre a combinação perguntada, e só sobre ela: a primeira frase diz o que se sabe desse par. Use o fato da base de interações (T1) quando houver. Quando a pessoa citou os dois remédios, não liste interações com outros remédios que ela não citou; se nem T1 nem as evidências falam desse par, não escreva fact: diga em um limit que não há orientação sobre essa combinação. Não ter interação registrada não prova que a combinação é segura: diga isso em uma frase simples, sem alarmar. Se o outro remédio não foi dito, pergunte qual é.',
            'DRUG_INFORMATION' => 'Explique o essencial (para que serve e o cuidado mais importante) em poucas frases.',
            'EMERGENCY' => 'A orientação de urgência já foi mostrada. Use só o que a bula diz sobre superdose ou reação grave, sem minimizar e sem perguntas.',
            'FOLLOW_UP' => 'Continue o assunto da conversa sem repetir o que já foi dito.',
        ],
        // Resposta conversacional: até 3 perguntas de esclarecimento e frases de no máximo 40 palavras em média.
        'conversation' => [
            'max_asks' => 3,
            'max_facts' => 5,
            'max_fact_words' => 150,
            // Profundidade proporcional à pergunta, pela intenção PRINCIPAL (canônica): perguntas simples pedem resposta curta; o modelo
            // ordena os fatos por importância e os que passam do orçamento são descartados (padrão: max_fact_words).
            'fact_words_by_intent' => ['INDICATION' => 60, 'PERSONAL_USE' => 90, 'DOSAGE' => 110, 'CONTRAINDICATION' => 110, 'ADVERSE_EFFECT' => 110, 'DRUG_INTERACTION' => 130, 'DRUG_INFORMATION' => 90, 'EMERGENCY' => 90],
            'fact_words_general' => 110,
            // Intenções principais puramente informativas: perguntas de esclarecimento do modelo são descartadas e, com cobertura
            // completa, também os limites (não há o que ressalvar quando a bula respondeu ao que foi perguntado).
            'no_ask_intents' => ['INDICATION', 'ADVERSE_EFFECT', 'DRUG_INFORMATION'],
            'no_limit_intents' => ['INDICATION', 'ADVERSE_EFFECT', 'DRUG_INFORMATION'],
            'max_avg_sentence_words' => 40,
            'max_retries' => 1,
        ],
        'leaflet_fallback' => [
            'enabled' => (bool) env('BULAI_LEAFLET_FALLBACK_ENABLED', true),
            'max_characters' => (int) env('BULAI_LEAFLET_FALLBACK_MAX_CHARACTERS', 150_000),
            'max_output_tokens' => (int) env('BULAI_LEAFLET_FALLBACK_MAX_OUTPUT_TOKENS', 4096),
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
        'max_output_tokens' => (int) env('BULAI_LEAFLET_MAX_OUTPUT_TOKENS', 4096),
        'evidence_per_section' => (int) env('BULAI_LEAFLET_EVIDENCE_PER_SECTION', 3),
        // `keywords`: palavras da pergunta do paciente que pedem essa seção (QuestionIntentDetector; comparação normalizada).
        'sections' => [
            'para_que_serve' => ['label' => 'Para que serve', 'query' => 'indicado para tratamento de indicações', 'patterns' => ['indica', 'para que', 'como este medicamento funciona', '!contra', '!quantidade maior'],
                'keywords' => ['para que serve', 'serve para', 'serve pra', 'indicado', 'indicacao', 'trata', 'funciona', 'para que e', 'pra que', 'antitermico', 'analgesico', 'alivia', 'aliviar', 'bom para', 'bom pra', 'boa para', 'boa pra']],
            'como_usar' => ['label' => 'Como usar', 'query' => 'modo de usar posologia dose administração horário', 'patterns' => ['como devo usar', 'posologia', 'modo de usar', 'como usar'],
                'keywords' => ['como tomar', 'como devo tomar', 'como usar', 'como uso', 'como tomo', 'como se usa', 'como se toma', 'como e o uso', 'modo de uso', 'jeito de usar', 'jeito de tomar', 'dose', 'doses', 'dosagem', 'quantas vezes', 'quantos comprimidos', 'quantas gotas', 'gotas', 'quantos ml', 'quantas colheres', 'quanto posso', 'quanto devo', 'quanto tomar', 'intervalo', 'de quantas em quantas', 'quantas horas', 'por dia', 'dose maxima', 'horario', 'horarios', 'jejum', 'comida', 'alimento', 'alimentos', 'manha', 'noite', 'esqueci', 'esquecer', 'posologia', 'quanto tempo', 'antes de dormir', 'de estomago vazio', 'mastigar', 'partir']],
            'beneficios' => ['label' => 'Benefícios esperados (prós)', 'query' => 'eficácia benefício alívio melhora resultado', 'patterns' => ['indica', 'resultados de efic', 'caracteristicas farmacol', 'como este medicamento funciona', '!contra', '!quantidade maior'],
                'keywords' => ['beneficio', 'beneficios', 'melhora', 'resultado', 'faz efeito', 'fazer efeito', 'demora']],
            'riscos' => ['label' => 'Riscos e cuidados (contras)', 'query' => 'advertências precauções riscos cuidados gravidez idosos', 'patterns' => ['advert', 'precau', 'o que devo saber'],
                'keywords' => ['cuidado', 'cuidados', 'risco', 'riscos', 'perigoso', 'perigosa', 'seguro', 'segura', 'dirigir', 'advertencia', 'precaucao', 'figado', 'rins', 'rim', 'pressao', 'coracao', 'diabetes', 'diabetico', 'idoso', 'idosa', 'idosos', 'gravida', 'gravidez', 'amamentando', 'amamentacao']],
            'efeitos_colaterais' => ['label' => 'Efeitos colaterais mais comuns', 'query' => 'reações adversas efeitos colaterais comuns muito comuns', 'patterns' => ['rea', 'males que este medicamento', 'efeitos'],
                'keywords' => ['efeito', 'efeitos', 'colateral', 'colaterais', 'colatereais', 'reacao', 'reacoes', 'adversa', 'adversas', 'engorda', 'emagrece', 'males', 'sintoma', 'sintomas', 'faz mal']],
            'nao_use_se' => ['label' => 'Não use se', 'query' => 'contraindicações não deve ser usado hipersensibilidade alergia', 'patterns' => ['contraindica', 'quando nao devo usar', 'não devo usar'],
                'keywords' => ['nao posso tomar', 'contraindicado', 'contraindicacao', 'contraindicacoes', 'nao pode tomar', 'quem nao pode', 'alergia', 'alergico', 'alergica', 'gravida', 'gravidez', 'amamentando', 'amamentacao', 'crianca', 'criancas']],
            'interacoes' => ['label' => 'Interações com outros remédios, álcool e alimentos', 'query' => 'interações medicamentosas álcool alimentos outros medicamentos', 'patterns' => ['interac'],
                'keywords' => ['junto', 'juntos', 'juntas', 'cerveja', 'alcool', 'vinho', 'bebida', 'cafe', 'leite', 'interage', 'interacao', 'interacoes', 'misturar', 'mesmo tempo', 'combinar', 'outro remedio', 'outros remedios', 'tomar com', 'com o', 'com a']],
            'procure_ajuda' => ['label' => 'Procure ajuda se', 'query' => 'superdose quantidade maior do que a indicada procure socorro médico', 'patterns' => ['superdose', 'quantidade maior', 'o que fazer'],
                'keywords' => ['superdose', 'superdosagem', 'overdose', 'tomei demais', 'tomei muito', 'quantidade excessiva', 'em excesso', 'excesso de', 'mais do que devia', 'mais do que deveria', 'dose maior', 'a mais', 'dobrada', 'dobrei', 'exagerei', 'intoxicacao', 'socorro', 'emergencia', 'o que fazer se', 'tomei dois', 'tomei duas']],
        ],
    ],
    'retrieval' => [
        // lexical | vector | hybrid. Vetorial/híbrido exigem embeddings indexados com o modelo configurado.
        'mode' => env('BULAI_RETRIEVAL_MODE', 'lexical'),
        'evidence_per_pair' => (int) env('BULAI_RETRIEVAL_EVIDENCE_PER_PAIR', 4),
        'excerpt_max_characters' => (int) env('BULAI_RETRIEVAL_EXCERPT_MAX', 1200),
        'sections' => ['interac', 'advertencias', 'precaucoes', 'contraindica'],
    ],
    // Interpretação da pergunta pela IA antes da busca (App\Services\Patient\QuestionInterpreter): remédios citados ou
    // herdados da conversa, pergunta reescrita, intenção e apresentação. Só orienta a busca; nunca é fonte de fatos.
    'interpretation' => [
        'enabled' => (bool) env('BULAI_INTERPRETATION_ENABLED', true),
        // Vazio = o mesmo modelo de texto configurado (AI_TEXT_DRIVER). Um modelo menor reduz a latência desta etapa.
        'model' => env('BULAI_INTERPRETATION_MODEL'),
        'max_output_tokens' => (int) env('BULAI_INTERPRETATION_MAX_OUTPUT_TOKENS', 600),
        'effort' => env('BULAI_INTERPRETATION_EFFORT', 'low'),
    ],

    'generation' => [
        'enabled' => (bool) env('BULAI_GENERATION_ENABLED', true),
        // v2 (07/11/2026): tom de conversa para pessoas idosas. v3: contrato por segmentos (fato citado, limite, pergunta) e cobertura.
        'prompt_version' => 'interaction-explanation-v3',
        // Teto, não meta: o raciocínio do modelo conta dentro dele. Com 1.400 as respostas eram cortadas e geradas de novo (08/11/2026).
        'max_output_tokens' => (int) env('BULAI_GENERATION_MAX_OUTPUT_TOKENS', 4096),
        // Esforço de raciocínio nas respostas ao paciente: a tarefa é explicar trechos já recuperados, não deduzir.
        'effort' => env('BULAI_GENERATION_EFFORT', 'low'),
    ],
    'code_version' => env('BULAI_CODE_VERSION'),
];
