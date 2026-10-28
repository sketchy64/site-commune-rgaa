<?php

defined('TYPO3') || die('Access denied.');

// Enregistrement des Hooks DataHandler TYPO3 (processDatamap et processCmdmap) pour l'envoi Push des actualités
$GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['t3lib/class.t3lib_tcemain.php']['processDatamapClass'][] =
    \Commune\SiteCommuneRgaa\Hook\DataHandlerNewsHook::class;

$GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['t3lib/class.t3lib_tcemain.php']['processCmdmapClass'][] =
    \Commune\SiteCommuneRgaa\Hook\DataHandlerNewsHook::class;

// Cache dédié aux données de l'API Innogam (token, listes des collectivités et détails)
if (!is_array($GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations']['site_commune_rgaa_innogam'] ?? null)) {
    $GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations']['site_commune_rgaa_innogam'] = [
        'frontend' => \TYPO3\CMS\Core\Cache\Frontend\VariableFrontend::class,
        'backend' => \TYPO3\CMS\Core\Cache\Backend\FileBackend::class,
        'options' => [
            'defaultLifetime' => 86400,
        ],
        'groups' => ['system'],
    ];
}
