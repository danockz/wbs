<?php

declare(strict_types=1);

/** Courses — Portuguese. Values only; keys mirror en/Courses.php. */

return [
    'title'          => 'Cursos',
    'course'         => 'curso',
    'courses'        => 'cursos',
    'empty'          => 'Ainda não há cursos.',
    'courseFallback' => 'Curso',
    'lesson'         => 'lição',
    'lessons'        => 'lições',

    'status' => [
        'draft'     => 'rascunho',
        'published' => 'publicado',
        'archived'  => 'arquivado',
    ],

    'lessonsLbl'      => 'Lições',
    'required'        => 'Obrigatórias',
    'statusLbl'       => 'Status',
    'description'     => 'Descrição',
    'lessonsHeading'  => 'Lições',
    'noLessons'       => 'Nenhuma lição adicionada ainda.',
    'lessonFallback'  => 'Lição',
    'requiredTag'     => 'obrigatória',
    'contentAttached' => '✓ conteúdo anexado',
    'noContent'       => '⚠ sem conteúdo anexado',
    'courseMeta'      => 'Curso {0}',
    'viewSyllabus'    => 'ver o programa do aluno →',

    'drip' => [
        'immediate' => 'imediato',
    ],

    'syllabusTitle' => 'Programa do curso',
    'syllabusSub'   => 'lições bloqueadas mostram apenas o horário de desbloqueio',
    'nonePublished' => 'Nenhuma lição publicada ainda.',
    'unlocks'       => '🔒 desbloqueia {0}',
    'unlockLater'   => 'mais tarde',
    'available'     => '✓ disponível',
    'contentLabel'  => 'Conteúdo: {0}',

    // Create-course FORM page (GET /courses/create).
    'createForm' => [
        'metaTitle'    => 'Criar curso',
        'heading'      => 'Criar um curso',
        'sub'          => 'Configure um novo curso. Ele começa como rascunho que você pode publicar depois.',
        'titleLabel'   => 'Título',
        'titlePh'      => 'ex. Fundamentos da fé',
        'categoryLabel'=> 'Categoria (opcional)',
        'deliveryLabel'=> 'Modo de entrega',
        'descLabel'    => 'Descrição (opcional)',
        'submit'       => 'Criar curso',
        'required'     => 'Obrigatório',
        'delivery' => [
            'self_paced' => 'No seu ritmo',
            'cohort'     => 'Turma',
            'blended'    => 'Híbrido',
        ],
    ],
    // Authoring card (overview.php): publish + add-lesson write controls.
    'authoring' => [
        'heading' => 'Autoria',
        'publishBtn' => 'Publicar curso',
        'publishHint' => 'Tornar este curso visível para os alunos.',
        'publishConfirm' => 'Publicar este curso agora?',
        'publishedFlash' => 'Curso publicado.',
        'lessonHeading' => 'Adicionar uma lição',
        'lessonTitleLabel' => 'Título da lição',
        'lessonTitlePh' => 'ex. Semana 1 — Bem-vindo',
        'lessonPositionLabel' => 'Posição',
        'lessonRequiredLabel' => 'Lição obrigatória',
        'lessonContentLabel' => 'Referência de conteúdo (opcional)',
        'lessonContentPh' => 'ex. video:abc123 ou uma URL de lição',
        'lessonAddBtn' => 'Adicionar lição',
        'lessonAddedFlash' => 'Lição adicionada.',
        'publishedNote' => 'Este curso já está publicado.',
    ],
    'learner' => [
        'enrolPrompt' => 'Inscreva-se para começar este curso e acompanhar o seu progresso.',
        'enrolBtn' => 'Inscrever-se',
        'enrolledFlash' => 'Está inscrito.',
        'signInToEnrol' => 'Inicie sessão para se inscrever neste curso.',
        'progressLabel' => '{0} de {1} lições obrigatórias concluídas ({2}%)',
        'markCompleteBtn' => 'Marcar como concluída',
        'lessonDone' => 'Concluída',
        'lessonDoneFlash' => 'Lição marcada como concluída.',
        'lessonLocked' => 'Desbloqueia mais tarde',
        'courseCompleted' => 'Curso concluído',
    ],

];
