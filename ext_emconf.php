<?php

$EM_CONF[$_EXTKEY] = [
    'title' => 'Site Commune RGAA - Surcouche Bootstrap Package Accessible',
    'description' => 'Sitepackage accessible et conforme RGAA 4.1.2 / WCAG 2.1 AA pour communes et collectivités territoriales basé sur bootstrap_package.',
    'category' => 'templates',
    'author' => 'Jonathan Auribault',
    'author_email' => 'contact@jonathanauribault.fr',
    'state' => 'stable',
    'version' => '1.0.0',
    'constraints' => [
        'depends' => [
            'typo3' => '12.4.0-13.4.99',
            'bootstrap_package' => '14.0.0-16.9.99',
        ],
        'conflicts' => [],
        'suggests' => [
            'hcaptcha' => '',
        ],
    ],
];
