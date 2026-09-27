<?php

declare(strict_types=1);

/** Courses — Spanish. Values only; keys mirror en/Courses.php. */

return [
    'title'          => 'Cursos',
    'course'         => 'curso',
    'courses'        => 'cursos',
    'empty'          => 'Aún no hay cursos.',
    'courseFallback' => 'Curso',
    'lesson'         => 'lección',
    'lessons'        => 'lecciones',

    'status' => [
        'draft'     => 'borrador',
        'published' => 'publicado',
        'archived'  => 'archivado',
    ],

    'lessonsLbl'      => 'Lecciones',
    'required'        => 'Obligatorias',
    'statusLbl'       => 'Estado',
    'description'     => 'Descripción',
    'lessonsHeading'  => 'Lecciones',
    'noLessons'       => 'Aún no se han añadido lecciones.',
    'lessonFallback'  => 'Lección',
    'requiredTag'     => 'obligatoria',
    'contentAttached' => '✓ contenido adjunto',
    'noContent'       => '⚠ sin contenido adjunto',
    'courseMeta'      => 'Curso {0}',
    'viewSyllabus'    => 'ver el temario del alumno →',

    'drip' => [
        'immediate' => 'inmediato',
    ],

    'syllabusTitle' => 'Temario del curso',
    'syllabusSub'   => 'las lecciones bloqueadas solo muestran su hora de desbloqueo',
    'nonePublished' => 'Aún no hay lecciones publicadas.',
    'unlocks'       => '🔒 se desbloquea {0}',
    'unlockLater'   => 'más tarde',
    'available'     => '✓ disponible',
    'contentLabel'  => 'Contenido: {0}',

    // Create-course FORM page (GET /courses/create).
    'createForm' => [
        'metaTitle'    => 'Crear curso',
        'heading'      => 'Crear un curso',
        'sub'          => 'Configure un nuevo curso. Comienza como borrador que puede publicar después.',
        'titleLabel'   => 'Título',
        'titlePh'      => 'p. ej. Fundamentos de la fe',
        'categoryLabel'=> 'Categoría (opcional)',
        'deliveryLabel'=> 'Modalidad de entrega',
        'descLabel'    => 'Descripción (opcional)',
        'submit'       => 'Crear curso',
        'required'     => 'Obligatorio',
        'delivery' => [
            'self_paced' => 'A tu ritmo',
            'cohort'     => 'Cohorte',
            'blended'    => 'Combinado',
        ],
    ],
    // Authoring card (overview.php): publish + add-lesson write controls.
    'authoring' => [
        'heading' => 'Autoría',
        'publishBtn' => 'Publicar curso',
        'publishHint' => 'Hacer este curso visible para los alumnos.',
        'publishConfirm' => '¿Publicar este curso ahora?',
        'publishedFlash' => 'Curso publicado.',
        'lessonHeading' => 'Agregar una lección',
        'lessonTitleLabel' => 'Título de la lección',
        'lessonTitlePh' => 'p. ej. Semana 1 — Bienvenida',
        'lessonPositionLabel' => 'Posición',
        'lessonRequiredLabel' => 'Lección obligatoria',
        'lessonContentLabel' => 'Referencia de contenido (opcional)',
        'lessonContentPh' => 'p. ej. video:abc123 o una URL de lección',
        'lessonAddBtn' => 'Agregar lección',
        'lessonAddedFlash' => 'Lección agregada.',
        'publishedNote' => 'Este curso ya está publicado.',
    ],
    'learner' => [
        'enrolPrompt' => 'Inscríbete para comenzar este curso y seguir tu progreso.',
        'enrolBtn' => 'Inscribirse ahora',
        'enrolledFlash' => 'Estás inscrito.',
        'signInToEnrol' => 'Inicia sesión para inscribirte en este curso.',
        'progressLabel' => '{0} de {1} lecciones obligatorias completadas ({2}%)',
        'markCompleteBtn' => 'Marcar como hecha',
        'lessonDone' => 'Completada',
        'lessonDoneFlash' => 'Lección marcada como completada.',
        'lessonLocked' => 'Se desbloquea más tarde',
        'courseCompleted' => 'Curso completado',
    ],

];
