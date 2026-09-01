<?php

defined('TYPO3') || die('Access denied.');

// Enregistrement des Hooks DataHandler TYPO3 (processDatamap et processCmdmap) pour l'envoi Push des actualités
$GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['t3lib/class.t3lib_tcemain.php']['processDatamapClass'][] =
    \Commune\SiteCommuneRgaa\Hook\DataHandlerNewsHook::class;

$GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['t3lib/class.t3lib_tcemain.php']['processCmdmapClass'][] =
    \Commune\SiteCommuneRgaa\Hook\DataHandlerNewsHook::class;
