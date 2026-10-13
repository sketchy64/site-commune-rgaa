<?php

declare(strict_types=1);

namespace Commune\SiteCommuneRgaa\Service;

use Symfony\Component\Yaml\Yaml;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Configuration\SiteConfiguration;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Service métier de gestion des sites et des paramètres de communes.
 */
class SiteManagementService
{
    private const SETTINGS_DEFINITIONS_PATH = 'EXT:site_commune_rgaa/Configuration/Sets/SiteCommuneRgaa/settings.definitions.yaml';

    public function __construct(
        private readonly SiteFinder $siteFinder,
        private readonly SiteConfiguration $siteConfiguration,
        private readonly ConnectionPool $connectionPool
    ) {
    }

    /**
     * Retourne tous les sites configurés dans TYPO3.
     *
     * @return array<string, Site>
     */
    public function getAllSites(): array
    {
        return $this->siteFinder->getAllSites();
    }

    /**
     * Récupère un site par son identifiant.
     */
    public function getSiteByIdentifier(string $identifier): ?Site
    {
        try {
            return $this->siteFinder->getSiteByIdentifier($identifier);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Lit le fichier settings.definitions.yaml pour extraire les catégories et les définitions des paramètres.
     *
     * @return array{categories: array, settings: array}
     */
    public function getSettingsDefinitions(): array
    {
        $absPath = GeneralUtility::getFileAbsFileName(self::SETTINGS_DEFINITIONS_PATH);
        if (!file_exists($absPath)) {
            return ['categories' => [], 'settings' => []];
        }

        $parsed = Yaml::parseFile($absPath);
        return [
            'categories' => $parsed['categories'] ?? [],
            'settings' => $parsed['settings'] ?? [],
        ];
    }

    /**
     * Récupère les paramètres d'un site fusionnés (Définitions < SysTemplate DB Constants < SiteSettings YAML).
     * Structure de retour multi-format pour garantir la compatibilité absolue avec Fluid et PHP.
     *
     * @return array<string, mixed>
     */
    public function getSiteSettings(string $identifier): array
    {
        $definitions = $this->getSettingsDefinitions();
        $defaults = [];
        foreach ($definitions['settings'] as $key => $def) {
            $defaults[$key] = $def['default'] ?? null;
        }

        $site = $this->getSiteByIdentifier($identifier);
        $rootPageId = $site ? $site->getRootPageId() : 0;

        // 1. Lecture universelle des constantes TypoScript de la BDD (sys_template) avec son timestamp
        $templateData = $this->getSysTemplateConstantsData($rootPageId);
        $sysTemplateConstants = $templateData['constants'];
        $templateTstamp = $templateData['tstamp'];

        // 2. Lecture de la configuration du site (YAML settings.yaml) avec son timestamp
        $settingsFilePath = GeneralUtility::getFileAbsFileName('config/sites/' . $identifier . '/settings.yaml');
        $siteSettings = [];
        $yamlMtime = 0;
        if (file_exists($settingsFilePath)) {
            $yamlMtime = (int)filemtime($settingsFilePath);
            try {
                $parsedYamlSettings = Yaml::parseFile($settingsFilePath);
                if (is_array($parsedYamlSettings)) {
                    $siteSettings = $parsedYamlSettings;
                }
            } catch (\Throwable) {
            }
        }

        // Lecture d'appoint dans config.yaml si présent
        try {
            $siteConfig = $this->siteConfiguration->load($identifier);
            if (isset($siteConfig['settings']) && is_array($siteConfig['settings'])) {
                $siteSettings = array_replace_recursive($siteConfig['settings'], $siteSettings);
            }
        } catch (\Throwable) {
        }

        // Arbitrage temporel bidirectionnel :
        // Si sys_template a été modifié plus récemment (ex: Constant Editor), les constantes ont la priorité.
        // Sinon (ex: sauvegarde via Module ou Site Settings), settings.yaml a la priorité.
        $preferTemplate = ($templateTstamp > $yamlMtime);

        $rawMerged = $defaults;
        foreach ($defaults as $key => $defaultVal) {
            $yamlVal = $this->extractValueFromConfig($siteSettings, $key);
            $templateVal = $sysTemplateConstants[$key] ?? null;

            if ($preferTemplate) {
                if ($templateVal !== null && $templateVal !== '') {
                    $rawMerged[$key] = $templateVal;
                } elseif ($yamlVal !== null && $yamlVal !== '') {
                    $rawMerged[$key] = $yamlVal;
                }
            } else {
                if ($yamlVal !== null && $yamlVal !== '') {
                    $rawMerged[$key] = $yamlVal;
                } elseif ($templateVal !== null && $templateVal !== '') {
                    $rawMerged[$key] = $templateVal;
                }
            }
        }

        // Normalisation spécifique pour les options de thème ('' ou 'none' => 'none')
        if (isset($rawMerged['commune.theme_css']) && $rawMerged['commune.theme_css'] === '') {
            $rawMerged['commune.theme_css'] = 'none';
        }

        // Duplication tri-forme de la structure pour Fluid ({currentSettings.commune.nom}, {currentSettings.commune_nom}, etc.)
        $result = [];
        foreach ($rawMerged as $key => $value) {
            $result[$key] = $value;
            $underscoreKey = str_replace('.', '_', $key);
            $result[$underscoreKey] = $value;

            if (str_contains($key, '.')) {
                $parts = explode('.', $key, 2);
                if (!isset($result[$parts[0]]) || !is_array($result[$parts[0]])) {
                    $result[$parts[0]] = [];
                }
                $result[$parts[0]][$parts[1]] = $value;
            }
        }

        return $result;
    }

    /**
     * Sauvegarde les nouveaux paramètres pour un site donné dans settings.yaml ET sys_template.
     *
     * @param string $identifier Identifiant du site (ex: 'base-rgaa')
     * @param array<string, mixed> $submittedSettings Clés-valeurs du formulaire
     * @return array{success: bool, error: string}
     */
    public function saveSiteSettings(string $identifier, array $submittedSettings): array
    {
        try {
            $siteConfig = $this->siteConfiguration->load($identifier);
            $existingSettings = $siteConfig['settings'] ?? [];
            if (!is_array($existingSettings)) {
                $existingSettings = [];
            }

            $definitions = $this->getSettingsDefinitions()['settings'];

            // Traitement et nettoyage des types avec extraction multi-format
            $cleanSettings = [];
            foreach ($definitions as $key => $def) {
                $type = $def['type'] ?? 'string';
                $isBool = ($type === 'bool' || $type === 'boolean');

                $underscoreKey = str_replace('.', '_', $key);
                $dotParts = str_contains($key, '.') ? explode('.', $key, 2) : [];

                $rawSubmitted = null;
                if (array_key_exists($key, $submittedSettings)) {
                    $rawSubmitted = $submittedSettings[$key];
                } elseif (array_key_exists($underscoreKey, $submittedSettings)) {
                    $rawSubmitted = $submittedSettings[$underscoreKey];
                } elseif (!empty($dotParts) && isset($submittedSettings[$dotParts[0]][$dotParts[1]])) {
                    $rawSubmitted = $submittedSettings[$dotParts[0]][$dotParts[1]];
                }

                if ($isBool) {
                    $val = ($rawSubmitted !== null) ? !empty($rawSubmitted) : false;
                } else {
                    $val = $rawSubmitted ?? ($existingSettings[$key] ?? $def['default'] ?? '');
                }

                $cleanVal = match ($type) {
                    'bool', 'boolean' => (bool)$val,
                    'int', 'integer' => (int)$val,
                    default => (string)$val,
                };

                $cleanSettings[$key] = $cleanVal;
            }

            // 1. Sauvegarde propre dans config/sites/<identifier>/settings.yaml (Standard TYPO3 v13 Site Sets)
            $nestedSettings = [];
            foreach ($cleanSettings as $key => $cleanVal) {
                if (str_contains($key, '.')) {
                    $parts = explode('.', $key, 2);
                    if (!isset($nestedSettings[$parts[0]]) || !is_array($nestedSettings[$parts[0]])) {
                        $nestedSettings[$parts[0]] = [];
                    }
                    $nestedSettings[$parts[0]][$parts[1]] = $cleanVal;
                } else {
                    $nestedSettings[$key] = $cleanVal;
                }
            }

            $siteDir = GeneralUtility::getFileAbsFileName('config/sites/' . $identifier . '/');
            if (!is_dir($siteDir)) {
                GeneralUtility::mkdir_deep($siteDir);
            }
            $settingsFile = $siteDir . 'settings.yaml';
            $yamlContent = Yaml::dump($nestedSettings, 99, 2);
            GeneralUtility::writeFile($settingsFile, $yamlContent);

            // 2. Nettoyage de config.yaml si un bloc settings obsolète s'y trouvait
            try {
                $siteConfig = $this->siteConfiguration->load($identifier);
                if (isset($siteConfig['settings'])) {
                    unset($siteConfig['settings']);
                    if (method_exists($this->siteConfiguration, 'write')) {
                        $this->siteConfiguration->write($identifier, $siteConfig);
                    }
                }
            } catch (\Throwable) {
            }

            // 3. Mise à jour synchrone de sys_template (BDD) pour le site
            $site = $this->getSiteByIdentifier($identifier);
            $rootPageId = $site ? $site->getRootPageId() : 0;
            $this->saveSysTemplateConstants($rootPageId, $cleanSettings);

            // 4. Vidage complet des caches pour prise en compte immédiate en Frontend
            $this->flushAllSiteCaches();

            return ['success' => true, 'error' => ''];
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Écrit la configuration du site de façon robuste et compatible avec toutes les versions TYPO3 Core.
     */
    private function writeSiteConfigurationData(string $identifier, array $siteConfig): void
    {
        if (method_exists($this->siteConfiguration, 'write')) {
            try {
                $this->siteConfiguration->write($identifier, $siteConfig);
            } catch (\Throwable) {
            }
        } elseif (method_exists($this->siteConfiguration, 'writeSiteConfiguration')) {
            try {
                $this->siteConfiguration->writeSiteConfiguration($identifier, $siteConfig);
            } catch (\Throwable) {
            }
        }

        // Sauvegarde directe YAML dans config/sites/<identifier>/
        $siteDir = GeneralUtility::getFileAbsFileName('config/sites/' . $identifier . '/');
        if (!is_dir($siteDir)) {
            GeneralUtility::mkdir_deep($siteDir);
        }

        $configFile = $siteDir . 'config.yaml';
        $yamlContent = Yaml::dump($siteConfig, 99, 2);
        GeneralUtility::writeFile($configFile, $yamlContent);

        if (isset($siteConfig['settings']) && is_array($siteConfig['settings'])) {
            $settingsFile = $siteDir . 'settings.yaml';
            $settingsContent = Yaml::dump($siteConfig['settings'], 99, 2);
            GeneralUtility::writeFile($settingsFile, $settingsContent);
        }

        $this->flushAllSiteCaches();
    }

    /**
     * Vide intégralement les caches Core, System et Frontend (pages/hash) pour mise à jour immédiate.
     */
    private function flushAllSiteCaches(): void
    {
        try {
            $cacheManager = GeneralUtility::makeInstance(CacheManager::class);
            if (method_exists($cacheManager, 'flushCachesInGroup')) {
                $cacheManager->flushCachesInGroup('pages');
                $cacheManager->flushCachesInGroup('system');
            }
            if ($cacheManager->hasCache('core')) {
                $cacheManager->getCache('core')->flush();
            }
            if ($cacheManager->hasCache('pages')) {
                $cacheManager->getCache('pages')->flush();
            }
            if ($cacheManager->hasCache('hash')) {
                $cacheManager->getCache('hash')->flush();
            }
        } catch (\Throwable) {
        }
    }

    /**
     * Crée une nouvelle configuration de site et son arborescence de pages.
     */
    public function createNewSite(array $data): string
    {
        $name = trim($data['name'] ?? 'Nouvelle Commune');
        $identifier = $data['identifier'] ?? '';
        if (empty($identifier)) {
            $identifier = 'commune-' . GeneralUtility::underscoredToLowercase(
                preg_replace('/[^a-zA-Z0-9_-]/', '', str_replace(' ', '-', $name))
            );
            $identifier = trim($identifier, '-');
        }

        $rootPageId = $this->createRootPage($name);

        $domain = $data['domain'] ?? 'http://localhost/';
        if (!str_starts_with($domain, 'http://') && !str_starts_with($domain, 'https://')) {
            $domain = 'https://' . $domain;
        }
        $domain = rtrim($domain, '/') . '/';

        $siteConfig = [
            'rootPageId' => $rootPageId,
            'base' => $domain,
            'title' => $name,
            'sets' => [
                'commune/site-commune-rgaa',
            ],
            'languages' => [
                [
                    'title' => 'Français',
                    'enabled' => true,
                    'languageId' => 0,
                    'base' => '/',
                    'typo3Language' => 'fr',
                    'locale' => 'fr_FR.UTF-8',
                    'iso-639-1' => 'fr',
                    'navigationTitle' => 'Français',
                    'flag' => 'fr',
                ],
            ],
        ];

        // Écriture de config.yaml
        $siteDir = GeneralUtility::getFileAbsFileName('config/sites/' . $identifier . '/');
        if (!is_dir($siteDir)) {
            GeneralUtility::mkdir_deep($siteDir);
        }
        $configFile = $siteDir . 'config.yaml';
        GeneralUtility::writeFile($configFile, Yaml::dump($siteConfig, 99, 2));

        // Initialisation synchrone de settings.yaml et des constantes sys_template
        $initialSettings = [
            'commune.nom' => $name,
            'commune.slogan' => $data['slogan'] ?? 'République Française - Liberté, Égalité, Fraternité',
            'commune.theme_css' => $data['theme'] ?? 'EXT:site_commune_rgaa/Resources/Public/Css/commune-theme-1.css',
            'commune.color_scheme_css' => $data['color_scheme'] ?? 'EXT:site_commune_rgaa/Resources/Public/Css/commune-couleurs-1.css',
        ];
        $this->saveSiteSettings($identifier, $initialSettings);

        if (!empty($data['create_pages'])) {
            $this->generateDefaultPageTree($rootPageId);
        }

        return $identifier;
    }

    /**
     * Parseur universel des constantes TypoScript enregistrées dans sys_template (BDD) avec relevé de timestamp.
     *
     * @return array{constants: array<string, string>, tstamp: int}
     */
    private function getSysTemplateConstantsData(int $rootPageId): array
    {
        try {
            $queryBuilder = $this->connectionPool->getQueryBuilderForTable('sys_template');
            $expr = $queryBuilder->expr();

            $rows = [];
            if ($rootPageId > 0) {
                $rows = $queryBuilder
                    ->select('uid', 'pid', 'tstamp', 'constants')
                    ->from('sys_template')
                    ->where($expr->eq('pid', $queryBuilder->createNamedParameter($rootPageId, \PDO::PARAM_INT)))
                    ->orderBy('root', 'DESC')
                    ->addOrderBy('sorting', 'ASC')
                    ->executeQuery()
                    ->fetchAllAssociative();
            }

            if (empty($rows)) {
                $qbRoot = $this->connectionPool->getQueryBuilderForTable('sys_template');
                $rows = $qbRoot
                    ->select('uid', 'pid', 'tstamp', 'constants')
                    ->from('sys_template')
                    ->where($qbRoot->expr()->eq('root', 1))
                    ->orderBy('sorting', 'ASC')
                    ->executeQuery()
                    ->fetchAllAssociative();
            }

            if (empty($rows)) {
                return ['constants' => [], 'tstamp' => 0];
            }

            $maxTstamp = 0;
            $parsed = [];
            $definitions = $this->getSettingsDefinitions()['settings'];
            $knownKeys = array_keys($definitions);

            foreach ($rows as $row) {
                $rowTstamp = (int)($row['tstamp'] ?? 0);
                if ($rowTstamp > $maxTstamp) {
                    $maxTstamp = $rowTstamp;
                }

                $text = (string)($row['constants'] ?? '');
                if (empty($text)) {
                    continue;
                }

                $lines = explode("\n", $text);
                $blockStack = [];

                foreach ($lines as $line) {
                    $line = trim($line);
                    if (empty($line) || str_starts_with($line, '#') || str_starts_with($line, '//') || str_starts_with($line, ';')) {
                        continue;
                    }

                    if (str_contains($line, '{')) {
                        $bName = trim(str_replace('{', '', $line));
                        if (!empty($bName)) {
                            $blockStack[] = $bName;
                        }
                        continue;
                    }

                    if ($line === '}') {
                        array_pop($blockStack);
                        continue;
                    }

                    if (str_contains($line, '=')) {
                        [$rawKey, $val] = explode('=', $line, 2);
                        $rawKey = trim(rtrim($rawKey, ':'));
                        $val = trim($val);

                        if ((str_starts_with($val, '"') && str_ends_with($val, '"')) || (str_starts_with($val, "'") && str_ends_with($val, "'"))) {
                            $val = substr($val, 1, -1);
                        }

                        if (empty($rawKey)) {
                            continue;
                        }

                        $fullPath = !empty($blockStack) ? implode('.', $blockStack) . '.' . $rawKey : $rawKey;

                        foreach ($knownKeys as $targetKey) {
                            $shortName = str_replace('commune.', '', $targetKey);

                            if (
                                $fullPath === $targetKey ||
                                $fullPath === 'plugin.tx_sitecommunergaa.settings.' . $targetKey ||
                                $fullPath === 'plugin.tx_sitecommunergaa.settings.' . $shortName ||
                                $fullPath === 'plugin.tx_sitecommunergaa_sitecommunergaa.settings.' . $shortName ||
                                $fullPath === 'commune.' . $shortName ||
                                $rawKey === $shortName
                            ) {
                                $parsed[$targetKey] = $val;
                            }
                        }
                    }
                }
            }

            return ['constants' => $parsed, 'tstamp' => $maxTstamp];
        } catch (\Throwable) {
            return ['constants' => [], 'tstamp' => 0];
        }
    }

    /**
     * Met à jour ou crée le bloc de constantes commune.* dans le sys_template de la page racine.
     */
    private function saveSysTemplateConstants(int $rootPageId, array $settings): void
    {
        try {
            $connection = $this->connectionPool->getConnectionForTable('sys_template');
            $queryBuilder = $connection->createQueryBuilder();

            $row = null;
            if ($rootPageId > 0) {
                $row = $queryBuilder
                    ->select('uid', 'pid', 'constants')
                    ->from('sys_template')
                    ->where($queryBuilder->expr()->eq('pid', $queryBuilder->createNamedParameter($rootPageId, \PDO::PARAM_INT)))
                    ->orderBy('root', 'DESC')
                    ->addOrderBy('sorting', 'ASC')
                    ->setMaxResults(1)
                    ->executeQuery()
                    ->fetchAssociative();
            }

            if (!$row) {
                $qbRoot = $connection->createQueryBuilder();
                $row = $qbRoot
                    ->select('uid', 'pid', 'constants')
                    ->from('sys_template')
                    ->where($qbRoot->expr()->eq('root', 1))
                    ->orderBy('sorting', 'ASC')
                    ->setMaxResults(1)
                    ->executeQuery()
                    ->fetchAssociative();
            }

            // Construction du bloc de constantes propre (Format TypoScript plat standard reconnu par l'éditeur de constantes)
            $constantLines = [];
            $constantLines[] = '# ==============================================================================';
            $constantLines[] = '# Constantes Commune RGAA (Synchronisées par le Module d\'Administration)';
            $constantLines[] = '# ==============================================================================';

            foreach ($settings as $key => $val) {
                if (str_starts_with($key, 'commune.')) {
                    $valStr = is_bool($val) ? ($val ? '1' : '0') : (string)$val;
                    $constantLines[] = $key . ' = ' . $valStr;
                }
            }

            $generatedBlock = implode("\n", $constantLines);
            $now = time();

            if ($row) {
                $existingConstants = (string)($row['constants'] ?? '');
                $lines = explode("\n", $existingConstants);

                // Retirer tout ancien bloc commune ou lignes commune.*
                $newLines = [];
                $inCommuneBlock = false;
                foreach ($lines as $line) {
                    $trimmed = trim($line);
                    if ($trimmed === 'commune {' || $trimmed === 'commune{') {
                        $inCommuneBlock = true;
                        continue;
                    }
                    if ($inCommuneBlock) {
                        if ($trimmed === '}') {
                            $inCommuneBlock = false;
                        }
                        continue;
                    }
                    if (str_starts_with($trimmed, 'commune.') || str_starts_with($trimmed, 'plugin.tx_sitecommunergaa.')) {
                        continue;
                    }
                    $newLines[] = $line;
                }

                $cleaned = rtrim(implode("\n", $newLines));
                $updatedConstants = ($cleaned !== '' ? $cleaned . "\n\n" : '') . $generatedBlock;

                $connection->update(
                    'sys_template',
                    [
                        'constants' => $updatedConstants,
                        'tstamp' => $now,
                    ],
                    ['uid' => (int)$row['uid']]
                );
            } elseif ($rootPageId > 0) {
                // Créer un enregistrement sys_template sur la racine
                $connection->insert('sys_template', [
                    'pid' => $rootPageId,
                    'title' => 'Configuration Commune RGAA',
                    'root' => 1,
                    'clear' => 3,
                    'constants' => $generatedBlock,
                    'crdate' => $now,
                    'tstamp' => $now,
                ]);
            }
        } catch (\Throwable) {
        }
    }

    /**
     * Crée la page racine de la commune dans la table pages.
     */
    private function createRootPage(string $siteName): int
    {
        $connection = $this->connectionPool->getConnectionForTable('pages');
        $now = time();

        $connection->insert('pages', [
            'pid' => 0,
            'title' => $siteName,
            'doktype' => 1,
            'is_siteroot' => 1,
            'backend_layout' => 'pagets__commune_home',
            'backend_layout_next_level' => 'pagets__commune_subpage',
            'crdate' => $now,
            'tstamp' => $now,
        ]);

        return (int)$connection->lastInsertId();
    }

    /**
     * Génère une arborescence complète et conforme RGAA sous la page racine.
     */
    private function generateDefaultPageTree(int $rootPageId): void
    {
        $connection = $this->connectionPool->getConnectionForTable('pages');
        $now = time();

        $defaultPages = [
            ['title' => 'Actualités & Agenda', 'doktype' => 1, 'layout' => 'pagets__commune_subpage'],
            ['title' => 'Démarches & Services', 'doktype' => 1, 'layout' => 'pagets__commune_subpage'],
            ['title' => 'Ma Mairie & Conseil Municipal', 'doktype' => 1, 'layout' => 'pagets__commune_subpage'],
            ['title' => 'Déclaration d\'accessibilité', 'doktype' => 1, 'layout' => 'pagets__commune_subpage'],
            ['title' => 'Contact & Horaires', 'doktype' => 1, 'layout' => 'pagets__commune_subpage'],
            ['title' => 'Mentions légales', 'doktype' => 1, 'layout' => 'pagets__commune_subpage'],
            ['title' => 'Plan du site', 'doktype' => 1, 'layout' => 'pagets__commune_subpage'],
        ];

        foreach ($defaultPages as $sorting => $page) {
            $connection->insert('pages', [
                'pid' => $rootPageId,
                'title' => $page['title'],
                'doktype' => $page['doktype'],
                'sorting' => ($sorting + 1) * 128,
                'backend_layout' => $page['layout'],
                'crdate' => $now,
                'tstamp' => $now,
            ]);
        }
    }

    /**
     * Extrait une valeur d'un tableau de configuration (supporte la notation pointée 'commune.nom' et imbriquée).
     */
    private function extractValueFromConfig(array $config, string $key): mixed
    {
        if (array_key_exists($key, $config) && $config[$key] !== null) {
            return $config[$key];
        }

        if (str_contains($key, '.')) {
            $parts = explode('.', $key);
            $curr = $config;
            foreach ($parts as $part) {
                if (is_array($curr) && array_key_exists($part, $curr)) {
                    $curr = $curr[$part];
                } else {
                    return null;
                }
            }
            return $curr;
        }

        return null;
    }
}
