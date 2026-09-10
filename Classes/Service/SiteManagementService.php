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
     * Récupère les paramètres d'un site fusionnés avec les valeurs par défaut de settings.definitions.yaml.
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

        try {
            $siteConfig = $this->siteConfiguration->load($identifier);
            $siteSettings = $siteConfig['settings'] ?? [];
            if (!is_array($siteSettings)) {
                $siteSettings = [];
            }
        } catch (\Throwable) {
            $siteSettings = [];
        }

        // Fusion des paramètres : plat et avec notation par points (commune.nom -> $siteSettings['commune']['nom'] ou $siteSettings['commune.nom'])
        $merged = $defaults;
        foreach ($defaults as $key => $defaultVal) {
            $value = $this->extractValueFromConfig($siteSettings, $key);
            if ($value !== null) {
                $merged[$key] = $value;
            }
        }

        return $merged;
    }

    /**
     * Sauvegarde les nouveaux paramètres pour un site donné.
     *
     * @param string $identifier Identifiant du site (ex: 'main-site' ou 'commune-pau')
     * @param array<string, mixed> $submittedSettings Clés-valeurs du formulaire
     */
    public function saveSiteSettings(string $identifier, array $submittedSettings): bool
    {
        try {
            $siteConfig = $this->siteConfiguration->load($identifier);
            $existingSettings = $siteConfig['settings'] ?? [];
            if (!is_array($existingSettings)) {
                $existingSettings = [];
            }

            $definitions = $this->getSettingsDefinitions()['settings'];

            // Préparation des réglages enregistrés en nettoyant et typant les données
            $newSettings = $existingSettings;
            foreach ($submittedSettings as $key => $value) {
                if (!isset($definitions[$key])) {
                    continue;
                }

                $type = $definitions[$key]['type'] ?? 'string';
                $cleanValue = match ($type) {
                    'bool', 'boolean' => (bool)$value,
                    'int', 'integer' => (int)$value,
                    default => (string)$value,
                };

                // Écriture à la fois en notation plate (commune.nom) et imbriquée ('commune' => ['nom'])
                $newSettings[$key] = $cleanValue;
                if (str_contains($key, '.')) {
                    $parts = explode('.', $key, 2);
                    if (!isset($newSettings[$parts[0]]) || !is_array($newSettings[$parts[0]])) {
                        $newSettings[$parts[0]] = [];
                    }
                    $newSettings[$parts[0]][$parts[1]] = $cleanValue;
                }
            }

            $siteConfig['settings'] = $newSettings;
            $this->siteConfiguration->write($identifier, $siteConfig);

            return true;
        } catch (\Throwable $e) {
            return false;
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
