<?php

declare(strict_types=1);

return [
    'site_communergaa' => [
        'parent' => 'site',
        'position' => ['after' => 'sites'],
        'access' => 'admin',
        'workspaces' => 'live',
        'iconIdentifier' => 'actions-system-extension-configure',
        'path' => '/module/site/commune-rgaa',
        'labels' => [
            'title' => 'Collectivités RGAA',
            'description' => 'Administration des sites de communes, charte graphique, accessibilité et assistant de déploiement pas à pas.',
        ],
        'extensionName' => 'SiteCommuneRgaa',
        'controllerActions' => [
            \Commune\SiteCommuneRgaa\Controller\BackendModuleController::class => [
                'index',
                'save',
                'wizard',
                'createSite',
            ],
        ],
    ],
];
