<?php

declare(strict_types=1);

namespace Commune\SiteCommuneRgaa\Service;

use Symfony\Component\Yaml\Yaml;
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

        // 1. Lecture des constantes TypoScript de la table sys_template (BDD)
        $sysTemplateConstants = $this->getSysTemplateConstants($rootPageId);

        // 2. Lecture de la configuration du site (YAML)
        try {
            $siteConfig = $this->siteConfiguration->load($identifier);
            $siteSettings = $siteConfig['settings'] ?? [];
            if (!is_array($siteSettings)) {
                $siteSettings = [];
            }
        } catch (\Throwable) {
            $siteSettings = [];
        }

        // Fusion en cascade : Defaults < sys_template < SiteSettings YAML
        $merged = $defaults;
        foreach ($defaults as $key => $defaultVal) {
            // a. Constantes sys_template si présentes
            if (isset($sysTemplateConstants[$key])) {
                $merged[$key] = $sysTemplateConstants[$key];
            }

            // b. Site Settings YAML
            $yamlVal = $this->extractValueFromConfig($siteSettings, $key);
            if ($yamlVal !== null && $yamlVal !== '') {
                $merged[$key] = $yamlVal;
            }
        }

        return $merged;
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

            // Traitement et nettoyage des types
            $cleanSettings = [];
            foreach ($definitions as $key => $def) {
                $type = $def['type'] ?? 'string';
                $isBool = ($type === 'bool' || $type === 'boolean');

                if ($isBool) {
                    $val = !empty($submittedSettings[$key]);
                } else {
                    $val = $submittedSettings[$key] ?? ($existingSettings[$key] ?? $def['default'] ?? '');
                }

                $cleanVal = match ($type) {
                    'bool', 'boolean' => (bool)$val,
                    'int', 'integer' => (int)$val,
                    default => (string)$val,
                };

                $cleanSettings[$key] = $cleanVal;
            }

            // Écriture dans siteConfig['settings']
            $newSettings = $existingSettings;
            foreach ($cleanSettings as $key => $cleanVal) {
                $newSettings[$key] = $cleanVal;
                if (str_contains($key, '.')) {
                    $parts = explode('.', $key, 2);
                    if (!isset($newSettings[$parts[0]]) || !is_array($newSettings[$parts[0]])) {
                        $newSettings[$parts[0]] = [];
                    }
                    $newSettings[$parts[0]][$parts[1]] = $cleanVal;
                }
            }

            $siteConfig['settings'] = $newSettings;
            $this->siteConfiguration->write($identifier, $siteConfig);

            // Mise à jour de sys_template si un enregistrement existe sur la page racine
            $site = $this->getSiteByIdentifier($identifier);
            if ($site && $site->getRootPageId() > 0) {
                $this->saveSysTemplateConstants($site->getRootPageId(), $cleanSettings);
            }

            return ['success' => true, 'error' => ''];
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Crée une nouvelle configuration de site et son arborescence de pages.
     *
     * @param array{
     *     name: string,
     *     identifier?: string,
     *     domain?: string,
     *     slogan?: string,
     *     theme?: string,
     *     color_scheme?: string,
     *     create_pages?: bool
     * } $data
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

        // 1. Création de la page racine en BDD si nécessaire
        $rootPageId = $this->createRootPage($name);

        // 2. Configuration du site
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
            'settings' => [
                'commune' => [
                    'nom' => $name,
                    'slogan' => $data['slogan'] ?? 'République Française - Liberté, Égalité, Fraternité',
                    'theme_css' => $data['theme'] ?? 'EXT:site_commune_rgaa/Resources/Public/Css/commune-theme-1.css',
                    'color_scheme_css' => $data['color_scheme'] ?? 'EXT:site_commune_rgaa/Resources/Public/Css/commune-couleurs-1.css',
                ],
            ],
        ];

        $this->siteConfiguration->write($identifier, $siteConfig);

        // 3. Arborescence automatique si cochée
        if (!empty($data['create_pages'])) {
            $this->generateDefaultPageTree($rootPageId);
        }

        return $identifier;
    }

    /**
     * Lit les constantes TypoScript enregistrées dans sys_template pour la page racine donnée.
     *
     * @return array<string, string>
     */
    private function getSysTemplateConstants(int $rootPageId): array
    {
        if ($rootPageId <= 0) {
            return [];
        }

        try {
            $queryBuilder = $this->connectionPool->getQueryBuilderForTable('sys_template');
            $row = $queryBuilder
                ->select('constants')
                ->from('sys_template')
                ->where($queryBuilder->expr()->eq('pid', $queryBuilder->createNamedParameter($rootPageId, \PDO::PARAM_INT)))
                ->orderBy('sorting', 'ASC')
                ->setMaxResults(1)
                ->executeQuery()
                ->fetchAssociative();

            if (!$row || empty($row['constants'])) {
                return [];
            }

            $parsed = [];
            $lines = explode("\n", (string)$row['constants']);
            $currentPrefix = '';
            foreach ($lines as $line) {
                $line = trim($line);
                if (empty($line) || str_starts_with($line, '#') || str_starts_with($line, '/')) {
                    continue;
                }
                if ($line === 'commune {') {
                    $currentPrefix = 'commune.';
                    continue;
                }
                if ($line === '}' && !empty($currentPrefix)) {
                    $currentPrefix = '';
                    continue;
                }
                if (str_contains($line, '=')) {
                    [$key, $val] = explode('=', $line, 2);
                    $key = trim($key);
                    $val = trim($val);
                    if (!empty($key)) {
                        $fullKey = str_starts_with($key, 'commune.') ? $key : ($currentPrefix . $key);
                        $parsed[$fullKey] = $val;
                    }
                }
            }

            return $parsed;
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Met à jour le bloc de constantes commune.* dans le sys_template de la page racine si un enregistrement existe.
     */
    private function saveSysTemplateConstants(int $rootPageId, array $settings): void
    {
        if ($rootPageId <= 0) {
            return;
        }

        try {
            $connection = $this->connectionPool->getConnectionForTable('sys_template');
            $queryBuilder = $connection->createQueryBuilder();

            $row = $queryBuilder
                ->select('uid', 'constants')
                ->from('sys_template')
                ->where($queryBuilder->expr()->eq('pid', $queryBuilder->createNamedParameter($rootPageId, \PDO::PARAM_INT)))
                ->orderBy('sorting', 'ASC')
                ->setMaxResults(1)
                ->executeQuery()
                ->fetchAssociative();

            if (!$row) {
                return;
            }

            $existingConstants = (string)($row['constants'] ?? '');
            $lines = explode("\n", $existingConstants);

            // Retirer l'ancien bloc commune.*
            $newLines = [];
            $inCommuneBlock = false;
            foreach ($lines as $line) {
                $trimmed = trim($line);
                if ($trimmed === 'commune {') {
                    $inCommuneBlock = true;
                    continue;
                }
                if ($inCommuneBlock) {
                    if ($trimmed === '}') {
                        $inCommuneBlock = false;
                    }
                    continue;
                }
                if (str_starts_with($trimmed, 'commune.')) {
                    continue;
                }
                $newLines[] = $line;
            }

            // Ajouter le nouveau bloc propre
            $newLines[] = '';
            $newLines[] = '# ==============================================================================';
            $newLines[] = '# Constantes Commune RGAA (Générées par le Module d\'Administration)';
            $newLines[] = '# ==============================================================================';
            $newLines[] = 'commune {';

            foreach ($settings as $key => $val) {
                if (str_starts_with($key, 'commune.')) {
                    $subKey = substr($key, 8);
                    if (is_bool($val)) {
                        $valStr = $val ? '1' : '0';
                    } else {
                        $valStr = (string)$val;
                    }
                    $newLines[] = '  ' . $subKey . ' = ' . $valStr;
                }
            }
            $newLines[] = '}';

            $updatedConstants = implode("\n", $newLines);

            $connection->update(
                'sys_template',
                ['constants' => $updatedConstants],
                ['uid' => (int)$row['uid']]
            );
        } catch (\Throwable) {
            // Ignorer silencieusement si pas de table ou pas de permission sys_template
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
