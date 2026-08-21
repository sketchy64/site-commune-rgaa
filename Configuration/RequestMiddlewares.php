<?php

return [
    'frontend' => [
        'site-commune-rgaa/pwa-handler' => [
            'target' => \Commune\SiteCommuneRgaa\Middleware\PwaMiddleware::class,
            'after' => [
                'typo3/cms-frontend/site',
            ],
            'before' => [
                'typo3/cms-frontend/base-redirect-resolver',
                'typo3/cms-frontend/page-resolver',
            ],
        ],
    ],
];
