<?php

declare(strict_types=1);

/** Courses — French. Values only; keys mirror en/Courses.php. */

return [
    'title'          => 'Cours',
    'course'         => 'cours',
    'courses'        => 'cours',
    'empty'          => 'Aucun cours pour le moment.',
    'courseFallback' => 'Cours',
    'lesson'         => 'leçon',
    'lessons'        => 'leçons',

    'status' => [
        'draft'     => 'brouillon',
        'published' => 'publié',
        'archived'  => 'archivé',
    ],

    'lessonsLbl'      => 'Leçons',
    'required'        => 'Obligatoires',
    'statusLbl'       => 'Statut',
    'description'     => 'Description',
    'lessonsHeading'  => 'Leçons',
    'noLessons'       => 'Aucune leçon ajoutée pour le moment.',
    'lessonFallback'  => 'Leçon',
    'requiredTag'     => 'obligatoire',
    'contentAttached' => '✓ contenu joint',
    'noContent'       => '⚠ aucun contenu joint',
    'courseMeta'      => 'Cours {0}',
    'viewSyllabus'    => 'voir le programme apprenant →',

    'drip' => [
        'immediate' => 'immédiat',
    ],

    'syllabusTitle' => 'Programme du cours',
    'syllabusSub'   => 'les leçons verrouillées n’affichent que leur heure de déverrouillage',
    'nonePublished' => 'Aucune leçon publiée pour le moment.',
    'unlocks'       => '🔒 déverrouillage {0}',
    'unlockLater'   => 'plus tard',
    'available'     => '✓ disponible',
    'contentLabel'  => 'Contenu : {0}',

    // Create-course FORM page (GET /courses/create).
    'createForm' => [
        'metaTitle'    => 'Créer un cours',
        'heading'      => 'Créer un cours',
        'sub'          => 'Configurez un nouveau cours. Il démarre en brouillon, publiable plus tard.',
        'titleLabel'   => 'Titre',
        'titlePh'      => 'ex. Fondements de la foi',
        'categoryLabel'=> 'Catégorie (facultatif)',
        'deliveryLabel'=> 'Mode de diffusion',
        'descLabel'    => 'Description (facultatif)',
        'submit'       => 'Créer le cours',
        'required'     => 'Obligatoire',
        'delivery' => [
            'self_paced' => 'À votre rythme',
            'cohort'     => 'Cohorte',
            'blended'    => 'Mixte',
        ],
    ],
    // Authoring card (overview.php): publish + add-lesson write controls.
    'authoring' => [
        'heading' => 'Création',
        'publishBtn' => 'Publier le cours',
        'publishHint' => 'Rendre ce cours visible pour les apprenants.',
        'publishConfirm' => 'Publier ce cours maintenant ?',
        'publishedFlash' => 'Cours publié.',
        'lessonHeading' => 'Ajouter une leçon',
        'lessonTitleLabel' => 'Titre de la leçon',
        'lessonTitlePh' => 'ex. Semaine 1 — Bienvenue',
        'lessonPositionLabel' => 'Position',
        'lessonRequiredLabel' => 'Leçon obligatoire',
        'lessonContentLabel' => 'Référence de contenu (facultatif)',
        'lessonContentPh' => 'ex. video:abc123 ou une URL de leçon',
        'lessonAddBtn' => 'Ajouter la leçon',
        'lessonAddedFlash' => 'Leçon ajoutée.',
        'publishedNote' => 'Ce cours est déjà publié.',
    ],
    'learner' => [
        'enrolPrompt' => 'Inscrivez-vous pour commencer ce cours et suivre votre progression.',
        'enrolBtn' => 'S\'inscrire',
        'enrolledFlash' => 'Vous êtes inscrit.',
        'signInToEnrol' => 'Connectez-vous pour vous inscrire à ce cours.',
        'progressLabel' => '{0} sur {1} leçons obligatoires terminées ({2} %)',
        'markCompleteBtn' => 'Marquer comme terminée',
        'lessonDone' => 'Terminée',
        'lessonDoneFlash' => 'Leçon marquée comme terminée.',
        'lessonLocked' => 'Débloquée plus tard',
        'courseCompleted' => 'Cours terminé',
    ],

];
