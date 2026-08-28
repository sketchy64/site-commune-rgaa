<?php

defined('TYPO3') || die('Access denied.');

// Enregistrement du Hook DataHandler TYPO3 (Compatibilité v12.4 & v13.4) pour l'envoi Push des actualités
$GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['t3lib/class.t3lib_tchempty.php']['processDatamapClass'][] =
    \Commune\SiteCommuneRgaa\Hook\DataHandlerNewsHook::class;
