<?php

declare(strict_types=1);

/**
 * Définition des routes Backend TYPO3 (v12 / v13 / v14).
 * En TYPO3 v12+, les routes AJAX sont définies directement dans Routes.php (AjaxRoutes.php est obsolète/supprimé).
 */
return [
    'site_commune_rgaa_innogam_search' => [
        'path' => '/site-commune-rgaa/innogam/search',
        'target' => \Commune\SiteCommuneRgaa\Controller\InnogamAjaxController::class . '::searchAction',
    ],
    'site_commune_rgaa_innogam_detail' => [
        'path' => '/site-commune-rgaa/innogam/detail',
        'target' => \Commune\SiteCommuneRgaa\Controller\InnogamAjaxController::class . '::detailAction',
    ],
];
