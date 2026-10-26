<?php

declare(strict_types=1);

namespace Commune\SiteCommuneRgaa\Service;

use Symfony\Component\Yaml\Yaml;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Configuration\SiteConfiguration;
use TYPO3\CMS\Core\Configuration\SiteWriter;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\DataHandling\SlugHelper;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Service métier de gestion des sites et des paramètres de communes (compatible TYPO3 v12, v13 et v14).
 */
class SiteManagementService
{
    private const SETTINGS_DEFINITIONS_PATH = 'EXT:site_commune_rgaa/Configuration/Sets/SiteCommuneRgaa/settings.definitions.yaml';

    private const DEFAULT_STATIC_INCLUDES = 'EXT:bootstrap_package/Configuration/TypoScript,EXT:form/Configuration/TypoScript,EXT:site_commune_rgaa/Configuration/TypoScript';

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
     * Retourne le catalogue complet de l'arborescence proposée à l'étape 4 du Wizard.
     *
     * @return array<string, mixed>
     */
    public function getDefaultPageTreeCatalog(): array
    {
        return [
            'folders' => [
                'main_menu' => [
                    'title' => 'Menu principal',
                    'slug' => '/dossier-menu-principal',
                    'setting_key' => 'commune.main_menu_folder_uid',
                    'pages' => [
                        [
                            'key' => 'main_accueil',
                            'title' => 'Accueil',
                            'doktype' => 4,
                            'shortcut_root' => true,
                            'nav_hide' => 0,
                            'badge' => 'Raccourci vers la racine',
                            'children' => [
                                [
                                    'key' => 'main_actualites',
                                    'title' => 'Actualités',
                                    'doktype' => 1,
                                    'nav_hide' => 1,
                                    'omit_parent_slug' => true,
                                    'badge' => 'Cachée du menu',
                                    'children' => [
                                        [
                                            'key' => 'main_actualites_detail',
                                            'title' => 'Détail',
                                            'doktype' => 1,
                                            'nav_hide' => 1,
                                            'badge' => 'Cachée du menu',
                                        ],
                                    ],
                                ],
                                [
                                    'key' => 'main_agenda',
                                    'title' => 'Agenda',
                                    'doktype' => 1,
                                    'nav_hide' => 1,
                                    'omit_parent_slug' => true,
                                    'badge' => 'Cachée du menu',
                                    'children' => [
                                        [
                                            'key' => 'main_agenda_detail',
                                            'title' => 'Détail',
                                            'doktype' => 1,
                                            'nav_hide' => 1,
                                            'badge' => 'Cachée du menu',
                                        ],
                                    ],
                                ],
                            ],
                        ],
                        [
                            'key' => 'main_mairie',
                            'title' => 'La Mairie',
                            'doktype' => 1,
                            'nav_hide' => 0,
                            'children' => [
                                [
                                    'key' => 'main_mairie_conseil',
                                    'title' => 'Le conseil municipal',
                                    'doktype' => 1,
                                    'nav_hide' => 0,
                                    'children' => [
                                        ['key' => 'main_mairie_conseil_elus', 'title' => 'Les élus', 'doktype' => 1, 'nav_hide' => 0],
                                        ['key' => 'main_mairie_conseil_commissions', 'title' => 'Les commissions municipales', 'doktype' => 1, 'nav_hide' => 0],
                                        ['key' => 'main_mairie_conseil_comptes_rendus', 'title' => 'Les comptes rendus', 'doktype' => 1, 'nav_hide' => 0],
                                    ],
                                ],
                                ['key' => 'main_mairie_services', 'title' => 'Les services municipaux', 'doktype' => 1, 'nav_hide' => 0],
                                ['key' => 'main_mairie_finances', 'title' => 'Les finances', 'doktype' => 1, 'nav_hide' => 0],
                                ['key' => 'main_mairie_bulletin', 'title' => 'Le bulletin municipal', 'doktype' => 1, 'nav_hide' => 0],
                                ['key' => 'main_mairie_projets', 'title' => 'Projets et réalisations', 'doktype' => 1, 'nav_hide' => 0],
                                ['key' => 'main_mairie_marches', 'title' => 'Marchés publics', 'doktype' => 1, 'nav_hide' => 0],
                                ['key' => 'main_mairie_actes', 'title' => 'Actes administratifs', 'doktype' => 1, 'nav_hide' => 0],
                            ],
                        ],
                        [
                            'key' => 'main_commune',
                            'title' => 'La commune',
                            'doktype' => 1,
                            'nav_hide' => 0,
                            'children' => [
                                ['key' => 'main_commune_presentation', 'title' => 'Présentation de la commune', 'doktype' => 1, 'nav_hide' => 0],
                                ['key' => 'main_commune_histoire', 'title' => 'L\'histoire', 'doktype' => 1, 'nav_hide' => 0],
                                ['key' => 'main_commune_patrimoine', 'title' => 'Le patrimoine', 'doktype' => 1, 'nav_hide' => 0],
                                [
                                    'key' => 'main_commune_medias',
                                    'title' => 'Médias',
                                    'doktype' => 1,
                                    'nav_hide' => 0,
                                    'children' => [
                                        ['key' => 'main_commune_medias_photos', 'title' => 'Photos', 'doktype' => 1, 'nav_hide' => 0],
                                        ['key' => 'main_commune_medias_videos', 'title' => 'Vidéos', 'doktype' => 1, 'nav_hide' => 0],
                                    ],
                                ],
                            ],
                        ],
                        [
                            'key' => 'main_services',
                            'title' => 'Services et vie pratique',
                            'doktype' => 1,
                            'nav_hide' => 0,
                            'children' => [
                                ['key' => 'main_services_guide', 'title' => 'Guide des démarches', 'doktype' => 1, 'nav_hide' => 0],
                                ['key' => 'main_services_demarches', 'title' => 'Démarches en ligne', 'doktype' => 1, 'nav_hide' => 0],
                                ['key' => 'main_services_salles', 'title' => 'Salles communales', 'doktype' => 1, 'nav_hide' => 0],
                                ['key' => 'main_services_mobilites', 'title' => 'Mobilités', 'doktype' => 1, 'nav_hide' => 0],
                                ['key' => 'main_services_tourisme', 'title' => 'Tourisme et loisirs', 'doktype' => 1, 'nav_hide' => 0],
                                ['key' => 'main_services_eau', 'title' => 'Eau et assainissement', 'doktype' => 1, 'nav_hide' => 0],
                                ['key' => 'main_services_dechets', 'title' => 'Gestion des déchets', 'doktype' => 1, 'nav_hide' => 0],
                                ['key' => 'main_services_urbanisme', 'title' => 'Urbanisme', 'doktype' => 1, 'nav_hide' => 0],
                                ['key' => 'main_services_cimetiere', 'title' => 'Cimetière', 'doktype' => 1, 'nav_hide' => 0],
                                ['key' => 'main_services_numeros', 'title' => 'Numéros utiles', 'doktype' => 1, 'nav_hide' => 0],
                                ['key' => 'main_services_emploi', 'title' => 'Emploi', 'doktype' => 1, 'nav_hide' => 0],
                                ['key' => 'main_services_securite', 'title' => 'Sécurité et civisme', 'doktype' => 1, 'nav_hide' => 0],
                                ['key' => 'main_services_signaler', 'title' => 'Signaler un problème', 'doktype' => 1, 'nav_hide' => 0],
                            ],
                        ],
                        [
                            'key' => 'main_solidarite',
                            'title' => 'Solidarité et santé',
                            'doktype' => 1,
                            'nav_hide' => 0,
                            'children' => [
                                ['key' => 'main_solidarite_ccas', 'title' => 'Le Centre Communal d\'Action Sociale (CCAS)', 'doktype' => 1, 'nav_hide' => 0],
                                [
                                    'key' => 'main_solidarite_seniors',
                                    'title' => 'Seniors',
                                    'doktype' => 1,
                                    'nav_hide' => 0,
                                    'children' => [
                                        ['key' => 'main_solidarite_seniors_aide', 'title' => 'Aide à domicile', 'doktype' => 1, 'nav_hide' => 0],
                                        ['key' => 'main_solidarite_seniors_repas', 'title' => 'Portage des repas', 'doktype' => 1, 'nav_hide' => 0],
                                        ['key' => 'main_solidarite_seniors_animations', 'title' => 'Animations seniors', 'doktype' => 1, 'nav_hide' => 0],
                                    ],
                                ],
                                [
                                    'key' => 'main_solidarite_sante',
                                    'title' => 'Santé et prévention',
                                    'doktype' => 1,
                                    'nav_hide' => 0,
                                    'children' => [
                                        ['key' => 'main_solidarite_sante_urgence', 'title' => 'Numéros d\'urgence', 'doktype' => 1, 'nav_hide' => 0],
                                        ['key' => 'main_solidarite_sante_canicule', 'title' => 'Plan canicule', 'doktype' => 1, 'nav_hide' => 0],
                                    ],
                                ],
                            ],
                        ],
                        [
                            'key' => 'main_enfance',
                            'title' => 'Enfance et jeunesse',
                            'doktype' => 1,
                            'nav_hide' => 0,
                            'children' => [
                                [
                                    'key' => 'main_enfance_petite',
                                    'title' => 'Petite enfance (0 à 3 ans)',
                                    'doktype' => 1,
                                    'nav_hide' => 0,
                                    'children' => [
                                        ['key' => 'main_enfance_petite_rpe', 'title' => 'Relais petite enfance', 'doktype' => 1, 'nav_hide' => 0],
                                        ['key' => 'main_enfance_petite_ram', 'title' => 'Relais assistance maternelle', 'doktype' => 1, 'nav_hide' => 0],
                                        ['key' => 'main_enfance_petite_creches', 'title' => 'Crèches et micro-crèches', 'doktype' => 1, 'nav_hide' => 0],
                                    ],
                                ],
                                [
                                    'key' => 'main_enfance_4_11',
                                    'title' => 'Enfance (4 à 11 ans)',
                                    'doktype' => 1,
                                    'nav_hide' => 0,
                                    'children' => [
                                        ['key' => 'main_enfance_4_11_maternelle', 'title' => 'École maternelle', 'doktype' => 1, 'nav_hide' => 0],
                                        ['key' => 'main_enfance_4_11_elementaire', 'title' => 'École élémentaire', 'doktype' => 1, 'nav_hide' => 0],
                                    ],
                                ],
                                [
                                    'key' => 'main_enfance_jeunesse',
                                    'title' => 'Jeunesse (12 à 18 ans)',
                                    'doktype' => 1,
                                    'nav_hide' => 0,
                                    'children' => [
                                        ['key' => 'main_enfance_jeunesse_college', 'title' => 'Collège', 'doktype' => 1, 'nav_hide' => 0],
                                        ['key' => 'main_enfance_jeunesse_lycee', 'title' => 'Lycée', 'doktype' => 1, 'nav_hide' => 0],
                                    ],
                                ],
                                ['key' => 'main_enfance_restauration', 'title' => 'Restauration', 'doktype' => 1, 'nav_hide' => 0],
                                ['key' => 'main_enfance_transports', 'title' => 'Transports', 'doktype' => 1, 'nav_hide' => 0],
                                ['key' => 'main_enfance_loisirs', 'title' => 'Centre de loisirs', 'doktype' => 1, 'nav_hide' => 0],
                                ['key' => 'main_enfance_portail', 'title' => 'Portail famille', 'doktype' => 1, 'nav_hide' => 0],
                            ],
                        ],
                    ],
                ],
                'footer_menu' => [
                    'title' => 'Menu en pied de page',
                    'slug' => '/dossier-menu-en-pied-de-page',
                    'setting_key' => 'commune.footer_folder_uid',
                    'pages' => [
                        [
                            'key' => 'footer_accueil',
                            'title' => 'Accueil',
                            'doktype' => 4,
                            'shortcut_root' => true,
                            'nav_hide' => 0,
                            'badge' => 'Raccourci vers la racine',
                        ],
                        [
                            'key' => 'footer_mentions',
                            'title' => 'Crédits & Mentions légales',
                            'doktype' => 1,
                            'nav_hide' => 0,
                        ],
                        [
                            'key' => 'footer_cookies',
                            'title' => 'Politique des cookies',
                            'doktype' => 1,
                            'nav_hide' => 0,
                        ],
                        [
                            'key' => 'footer_accessibilite',
                            'title' => 'Déclaration d\'accessibilité',
                            'doktype' => 1,
                            'nav_hide' => 0,
                            'setting_key' => 'commune.rgaa_page_uid',
                            'badge' => 'Liée à commune.rgaa_page_uid',
                        ],
                        [
                            'key' => 'footer_plan_site',
                            'title' => 'Plan du site',
                            'doktype' => 1,
                            'nav_hide' => 0,
                        ],
                        [
                            'key' => 'footer_plan_acces',
                            'title' => 'Plan d\'accès',
                            'doktype' => 1,
                            'nav_hide' => 0,
                            'setting_key' => 'commune.footer_map_page_uid',
                            'badge' => 'Liée à commune.footer_map_page_uid',
                        ],
                        [
                            'key' => 'footer_contact',
                            'title' => 'Nous contacter',
                            'doktype' => 1,
                            'nav_hide' => 0,
                            'setting_key' => 'commune.footer_contact_page_uid',
                            'badge' => 'Liée à commune.footer_contact_page_uid',
                        ],
                        [
                            'key' => 'footer_recherche',
                            'title' => 'Recherche',
                            'doktype' => 1,
                            'nav_hide' => 1,
                            'setting_key' => 'commune.search_page_uid',
                            'badge' => 'Cachée du menu · Liée à commune.search_page_uid',
                        ],
                    ],
                ],
            ],
            'empty_folders' => [
                ['title' => 'FORMATION', 'slug' => '/dossier-formation'],
                ['title' => 'Actualités', 'slug' => '/dossier-actualites'],
                ['title' => 'Agenda', 'slug' => '/dossier-agenda'],
                ['title' => 'Carte', 'slug' => '/dossier-carte'],
            ],
        ];
    }

    /**
     * Retourne la liste à plat de toutes les clés de pages du catalogue par défaut.
     *
     * @return array<int, string>
     */
    public function getAllCatalogPageKeys(): array
    {
        $catalog = $this->getDefaultPageTreeCatalog();
        $keys = [];

        foreach ($catalog['folders'] as $folder) {
            $this->collectNodeKeys($folder['pages'] ?? [], $keys);
        }

        return $keys;
    }

    /**
     * @param array<int, array<string, mixed>> $nodes
     * @param array<int, string> $keys
     */
    private function collectNodeKeys(array $nodes, array &$keys): void
    {
        foreach ($nodes as $node) {
            if (!empty($node['key'])) {
                $keys[] = (string)$node['key'];
            }
            if (!empty($node['children']) && is_array($node['children'])) {
                $this->collectNodeKeys($node['children'], $keys);
            }
        }
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
        $settingsFilePath = $this->resolveSiteSettingsFilePath($identifier);
        $siteSettings = [];
        $yamlMtime = 0;
        if ($settingsFilePath !== '' && file_exists($settingsFilePath)) {
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

            // 1. Sauvegarde propre dans config/sites/<identifier>/settings.yaml (Standard TYPO3 v13/v14 Site Sets & v12)
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

            $siteDir = $this->getSiteConfigDirectory($identifier);
            if (!is_dir($siteDir)) {
                GeneralUtility::mkdir_deep($siteDir);
            }

            $wroteViaSiteWriter = false;
            if (class_exists(SiteWriter::class)) {
                try {
                    $siteWriter = GeneralUtility::makeInstance(SiteWriter::class);
                    if (method_exists($siteWriter, 'writeSettings')) {
                        $siteWriter->writeSettings($identifier, $nestedSettings);
                        $wroteViaSiteWriter = true;
                    }
                } catch (\Throwable) {
                }
            }

            if (!$wroteViaSiteWriter) {
                $settingsFile = $siteDir . 'settings.yaml';
                $yamlContent = Yaml::dump($nestedSettings, 99, 2);
                GeneralUtility::writeFile($settingsFile, $yamlContent);
            }

            // 2. Nettoyage de config.yaml si un bloc settings obsolète s'y trouvait
            try {
                $siteConfig = $this->siteConfiguration->load($identifier);
                if (isset($siteConfig['settings'])) {
                    unset($siteConfig['settings']);
                    $this->writeSiteConfigurationData($identifier, $siteConfig);
                }
            } catch (\Throwable) {
            }

            // 3. Mise à jour synchrone de sys_template (BDD) pour le site
            $site = $this->getSiteByIdentifier($identifier);
            $rootPageId = $site ? $site->getRootPageId() : 0;
            $this->saveSysTemplateConstants($rootPageId, $cleanSettings, $site);

            // 4. Vidage complet des caches pour prise en compte immédiate en Frontend
            $this->flushAllSiteCaches();

            return ['success' => true, 'error' => ''];
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Écrit la configuration du site de façon robuste et compatible avec toutes les versions TYPO3 Core (v12, v13, v14).
     */
    private function writeSiteConfigurationData(string $identifier, array $siteConfig): void
    {
        $wroteConfig = false;

        if (class_exists(SiteWriter::class)) {
            try {
                $siteWriter = GeneralUtility::makeInstance(SiteWriter::class);
                if (method_exists($siteWriter, 'write')) {
                    $siteWriter->write($identifier, $siteConfig);
                    $wroteConfig = true;
                }
            } catch (\Throwable) {
            }
        }

        if (!$wroteConfig && method_exists($this->siteConfiguration, 'write')) {
            try {
                $this->siteConfiguration->write($identifier, $siteConfig);
                $wroteConfig = true;
            } catch (\Throwable) {
            }
        }

        if (!$wroteConfig) {
            $siteDir = $this->getSiteConfigDirectory($identifier);
            if (!is_dir($siteDir)) {
                GeneralUtility::mkdir_deep($siteDir);
            }

            $configFile = $siteDir . 'config.yaml';
            $yamlContent = Yaml::dump($siteConfig, 99, 2);
            GeneralUtility::writeFile($configFile, $yamlContent);
        }

        $this->flushAllSiteCaches();
    }

    /**
     * Retourne le chemin absolu du dossier config/sites/<identifier>/ (compatible Composer et Classic).
     */
    private function getSiteConfigDirectory(string $identifier): string
    {
        return rtrim(Environment::getConfigPath(), '/') . '/sites/' . $identifier . '/';
    }

    /**
     * Résout le chemin du fichier settings.yaml d'un site avec repli de compatibilité.
     */
    private function resolveSiteSettingsFilePath(string $identifier): string
    {
        $primary = $this->getSiteConfigDirectory($identifier) . 'settings.yaml';
        if (file_exists($primary)) {
            return $primary;
        }

        $legacyPublicFile = GeneralUtility::getFileAbsFileName('config/sites/' . $identifier . '/settings.yaml');
        if ($legacyPublicFile !== '' && file_exists($legacyPublicFile)) {
            return $legacyPublicFile;
        }

        return $primary;
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
     * Crée une nouvelle configuration de site et son arborescence complète de dossiers et de pages.
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

        $rawDomain = trim((string)($data['domain'] ?? ''));
        if ($rawDomain === '' || $rawDomain === '/') {
            $domain = '/';
        } elseif (str_starts_with($rawDomain, '/')) {
            $domain = '/' . trim($rawDomain, '/') . '/';
        } else {
            if (!str_starts_with($rawDomain, 'http://') && !str_starts_with($rawDomain, 'https://')) {
                $hostOnly = explode(':', explode('/', $rawDomain)[0])[0];
                $isLocal = (
                    $hostOnly === 'localhost'
                    || $hostOnly === '127.0.0.1'
                    || str_ends_with($hostOnly, '.local')
                    || str_ends_with($hostOnly, '.test')
                    || str_ends_with($hostOnly, '.ddev.site')
                );
                $domain = ($isLocal ? 'http://' : 'https://') . $rawDomain;
            } else {
                $domain = $rawDomain;
            }
            $domain = rtrim($domain, '/') . '/';
        }

        $siteConfig = [
            'rootPageId' => $rootPageId,
            'base' => $domain,
            'websiteTitle' => $name,
            'title' => $name,
            'dependencies' => [
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

        // 1. Écriture de config.yaml via SiteWriter (v13/v14) ou SiteConfiguration (v12)
        $this->writeSiteConfigurationData($identifier, $siteConfig);

        // 2. Détermination des pages sélectionnées à l'étape 4 (systématiquement créée)
        $selectedPageKeys = null;
        if (isset($data['selected_pages']) && is_array($data['selected_pages'])) {
            $selectedPageKeys = array_values(array_filter(array_map('strval', $data['selected_pages'])));
        } elseif (isset($data['selected_pages_csv']) && is_string($data['selected_pages_csv']) && $data['selected_pages_csv'] !== '') {
            $selectedPageKeys = array_values(array_filter(explode(',', $data['selected_pages_csv'])));
        } elseif (!empty($data['selected_pages_initialized'])) {
            $selectedPageKeys = [];
        }

        // 3. Génération systématique des 6 dossiers et des pages sélectionnées avec récupération des UID réels
        $generatedUids = $this->generateDefaultPageTree($rootPageId, $selectedPageKeys);

        // 4. Initialisation synchrone de settings.yaml et des constantes sys_template avec les UID correspondants
        $initialSettings = [
            'commune.nom' => $name,
            'commune.slogan' => !empty($data['slogan']) ? $data['slogan'] : 'République Française - Liberté, Égalité, Fraternité',
            'commune.theme_css' => !empty($data['theme']) ? $data['theme'] : 'EXT:site_commune_rgaa/Resources/Public/Css/commune-theme-1.css',
            'commune.color_scheme_css' => !empty($data['color_scheme']) ? $data['color_scheme'] : 'EXT:site_commune_rgaa/Resources/Public/Css/commune-couleurs-1.css',
            'commune.rgaa_statut' => !empty($data['rgaa_statut']) ? $data['rgaa_statut'] : 'partiellement conforme',
            'commune.rgaa_taux' => !empty($data['rgaa_taux']) ? $data['rgaa_taux'] : '84%',
        ];

        foreach ($generatedUids as $settingKey => $uid) {
            if ($uid > 0) {
                $initialSettings[$settingKey] = $uid;
            }
        }

        $this->saveSiteSettings($identifier, $initialSettings);
        $this->flushAllSiteCaches();

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
                    ->where($expr->eq('pid', $queryBuilder->createNamedParameter($rootPageId, Connection::PARAM_INT)))
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
     * Préserve l'arbre TypoScript des Site Sets en TYPO3 v13/v14 et inclut les statiques requis en TYPO3 v12.
     */
    private function saveSysTemplateConstants(int $rootPageId, array $settings, ?Site $site = null): void
    {
        try {
            $connection = $this->connectionPool->getConnectionForTable('sys_template');
            $queryBuilder = $connection->createQueryBuilder();

            $row = null;
            if ($rootPageId > 0) {
                $row = $queryBuilder
                    ->select('uid', 'pid', 'root', 'clear', 'constants', 'config', 'include_static_file')
                    ->from('sys_template')
                    ->where($queryBuilder->expr()->eq('pid', $queryBuilder->createNamedParameter($rootPageId, Connection::PARAM_INT)))
                    ->orderBy('root', 'DESC')
                    ->addOrderBy('sorting', 'ASC')
                    ->setMaxResults(1)
                    ->executeQuery()
                    ->fetchAssociative();
            }

            if (!$row) {
                $qbRoot = $connection->createQueryBuilder();
                $row = $qbRoot
                    ->select('uid', 'pid', 'root', 'clear', 'constants', 'config', 'include_static_file')
                    ->from('sys_template')
                    ->where($qbRoot->expr()->eq('root', 1))
                    ->orderBy('sorting', 'ASC')
                    ->setMaxResults(1)
                    ->executeQuery()
                    ->fetchAssociative();
            }

            // Détermine si le site utilise les Site Sets (TYPO3 v13 / v14)
            $siteIsTypoScriptRoot = ($site !== null && method_exists($site, 'isTypoScriptRoot') && $site->isTypoScriptRoot());

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

                $updateFields = [
                    'constants' => $updatedConstants,
                    'tstamp' => $now,
                ];

                // Auto-réparation si un sys_template vide avec clear=3 écrasait le Site Set (v13/v14) ou manquait d'include_static_file (v12)
                $hasNoSetupAndNoStatic = empty(trim((string)($row['config'] ?? ''))) && empty(trim((string)($row['include_static_file'] ?? '')));
                if ($hasNoSetupAndNoStatic && ((int)($row['clear'] ?? 0) & 2)) {
                    if ($siteIsTypoScriptRoot) {
                        $updateFields['root'] = 0;
                        $updateFields['clear'] = 0;
                    } else {
                        $updateFields['include_static_file'] = self::DEFAULT_STATIC_INCLUDES;
                    }
                }

                $connection->update(
                    'sys_template',
                    $updateFields,
                    ['uid' => (int)$row['uid']]
                );
            } elseif ($rootPageId > 0) {
                // Créer un enregistrement sys_template adapté à la version TYPO3 / mode du site
                if ($siteIsTypoScriptRoot) {
                    // En TYPO3 v13/v14 avec Site Set : ne pas vider le Setup (clear = 0, root = 0)
                    $connection->insert('sys_template', [
                        'pid' => $rootPageId,
                        'title' => 'Configuration Commune RGAA',
                        'root' => 0,
                        'clear' => 0,
                        'constants' => $generatedBlock,
                        'crdate' => $now,
                        'tstamp' => $now,
                    ]);
                } else {
                    // En TYPO3 v12 (ou sans Site Set) : template racine complet avec inclusion des statiques
                    $connection->insert('sys_template', [
                        'pid' => $rootPageId,
                        'title' => 'Configuration Commune RGAA',
                        'root' => 1,
                        'clear' => 3,
                        'include_static_file' => self::DEFAULT_STATIC_INCLUDES,
                        'constants' => $generatedBlock,
                        'crdate' => $now,
                        'tstamp' => $now,
                    ]);
                }
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
            'slug' => '/',
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
     * Génère l'arborescence complète organisée en 6 dossiers sous la page racine
     * ainsi que les pages sélectionnées par l'utilisateur.
     *
     * @param int $rootPageId UID de la page racine du site
     * @param array<int, string>|null $selectedPageKeys Liste des clés de pages cochées (null = toutes)
     * @return array<string, int> Tableau associatif [setting_key => page_uid]
     */
    private function generateDefaultPageTree(int $rootPageId, ?array $selectedPageKeys = null): array
    {
        $connection = $this->connectionPool->getConnectionForTable('pages');
        $now = time();
        $catalog = $this->getDefaultPageTreeCatalog();

        if ($selectedPageKeys === null) {
            $selectedPageKeys = $this->getAllCatalogPageKeys();
        }
        $selectedSet = array_fill_keys($selectedPageKeys, true);

        // Suivi des slugs déjà attribués dans ce site pour garantir l'unicité (ex: /accueil puis /accueil-1)
        $usedSlugs = [
            '/' => true,
        ];

        $generatedSettingsUids = [];
        $folderSorting = 1;

        // 1. Création des 2 dossiers de menus ("Menu principal" et "Menu en pied de page")
        $folderUids = [];
        foreach ($catalog['folders'] as $folderKey => $folderDef) {
            $folderSlug = $this->resolveUniqueSlug((string)$folderDef['slug'], $usedSlugs);
            $connection->insert('pages', [
                'pid' => $rootPageId,
                'title' => (string)$folderDef['title'],
                'slug' => $folderSlug,
                'doktype' => 254,
                'sorting' => $folderSorting * 128,
                'backend_layout_next_level' => 'pagets__commune_subpage',
                'crdate' => $now,
                'tstamp' => $now,
            ]);
            $folderUid = (int)$connection->lastInsertId();
            $folderUids[$folderKey] = $folderUid;
            $folderSorting++;

            if (!empty($folderDef['setting_key'])) {
                $generatedSettingsUids[(string)$folderDef['setting_key']] = $folderUid;
            }
        }

        // 2. Création des 4 dossiers vides ("FORMATION", "Actualités", "Agenda", "Carte")
        foreach ($catalog['empty_folders'] as $emptyFolder) {
            $folderSlug = $this->resolveUniqueSlug((string)$emptyFolder['slug'], $usedSlugs);
            $connection->insert('pages', [
                'pid' => $rootPageId,
                'title' => (string)$emptyFolder['title'],
                'slug' => $folderSlug,
                'doktype' => 254,
                'sorting' => $folderSorting * 128,
                'backend_layout_next_level' => 'pagets__commune_subpage',
                'crdate' => $now,
                'tstamp' => $now,
            ]);
            $folderSorting++;
        }

        // 3. Création récursive des pages sélectionnées dans chaque dossier de menu
        foreach ($catalog['folders'] as $folderKey => $folderDef) {
            $parentFolderUid = $folderUids[$folderKey] ?? 0;
            if ($parentFolderUid <= 0) {
                continue;
            }

            $this->insertSelectedPageNodes(
                $connection,
                $folderDef['pages'] ?? [],
                $parentFolderUid,
                $rootPageId,
                '',
                $selectedSet,
                $usedSlugs,
                $generatedSettingsUids,
                $now
            );
        }

        return $generatedSettingsUids;
    }

    /**
     * Insère récursivement les pages et sous-pages cochées.
     *
     * @param Connection $connection
     * @param array<int, array<string, mixed>> $nodes
     * @param int $parentPid
     * @param int $rootPageId
     * @param string $parentSlugPrefix
     * @param array<string, bool> $selectedSet
     * @param array<string, bool> $usedSlugs
     * @param array<string, int> $generatedSettingsUids
     * @param int $now
     */
    private function insertSelectedPageNodes(
        Connection $connection,
        array $nodes,
        int $parentPid,
        int $rootPageId,
        string $parentSlugPrefix,
        array $selectedSet,
        array &$usedSlugs,
        array &$generatedSettingsUids,
        int $now
    ): void {
        $sortingIndex = 1;

        foreach ($nodes as $node) {
            if (!$this->isNodeOrDescendantSelected($node, $selectedSet)) {
                continue;
            }

            $title = (string)($node['title'] ?? 'Page');
            $doktype = (int)($node['doktype'] ?? 1);
            $navHide = (int)($node['nav_hide'] ?? 0);
            $isShortcutToRoot = !empty($node['shortcut_root']);
            $omitParentSlug = !empty($node['omit_parent_slug']);

            $segment = $this->sanitizeSlugSegment($title, $parentPid);
            $effectivePrefix = $omitParentSlug ? '' : $parentSlugPrefix;
            $candidateSlug = ($effectivePrefix !== '' ? rtrim($effectivePrefix, '/') : '') . '/' . ltrim($segment, '/');
            $slug = $this->resolveUniqueSlug($candidateSlug, $usedSlugs);

            $row = [
                'pid' => $parentPid,
                'title' => $title,
                'slug' => $slug,
                'doktype' => $doktype,
                'nav_hide' => $navHide,
                'hidden' => 0,
                'sorting' => $sortingIndex * 128,
                'backend_layout' => 'pagets__commune_subpage',
                'backend_layout_next_level' => 'pagets__commune_subpage',
                'crdate' => $now,
                'tstamp' => $now,
            ];

            if ($doktype === 4 && $isShortcutToRoot) {
                $row['shortcut'] = $rootPageId;
                $row['shortcut_mode'] = 0;
            }

            $connection->insert('pages', $row);
            $newPageUid = (int)$connection->lastInsertId();
            $sortingIndex++;

            if (!empty($node['setting_key'])) {
                $generatedSettingsUids[(string)$node['setting_key']] = $newPageUid;
            }

            if (!empty($node['children']) && is_array($node['children'])) {
                $this->insertSelectedPageNodes(
                    $connection,
                    $node['children'],
                    $newPageUid,
                    $rootPageId,
                    $slug,
                    $selectedSet,
                    $usedSlugs,
                    $generatedSettingsUids,
                    $now
                );
            }
        }
    }

    /**
     * Vérifie si un nœud ou l'un de ses descendants est sélectionné (évite toute sous-page orpheline).
     *
     * @param array<string, mixed> $node
     * @param array<string, bool> $selectedSet
     */
    private function isNodeOrDescendantSelected(array $node, array $selectedSet): bool
    {
        $key = (string)($node['key'] ?? '');
        if ($key !== '' && isset($selectedSet[$key])) {
            return true;
        }

        if (!empty($node['children']) && is_array($node['children'])) {
            foreach ($node['children'] as $child) {
                if ($this->isNodeOrDescendantSelected($child, $selectedSet)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Garantit l'unicité d'un slug dans le site (ex: /accueil -> /accueil-1 si déjà utilisé).
     *
     * @param string $candidateSlug
     * @param array<string, bool> $usedSlugs
     */
    private function resolveUniqueSlug(string $candidateSlug, array &$usedSlugs): string
    {
        $normalized = '/' . trim($candidateSlug, '/');
        if ($normalized === '/') {
            $normalized = '/page';
        }

        $finalSlug = $normalized;
        $counter = 1;
        while (isset($usedSlugs[$finalSlug])) {
            $finalSlug = $normalized . '-' . $counter;
            $counter++;
        }

        $usedSlugs[$finalSlug] = true;
        return $finalSlug;
    }

    /**
     * Convertit un titre de page en segment d'URL propre (sans slash initial ni préfixe parent).
     */
    private function sanitizeSlugSegment(string $title, int $pid): string
    {
        try {
            $fieldConfig = [
                'generatorOptions' => [
                    'fields' => ['title'],
                    'fieldSeparator' => '/',
                    'prefixParentPageSlug' => false,
                    'replacements' => [
                        '/' => '-',
                    ],
                ],
                'fallbackCharacter' => '-',
                'eval' => 'uniqueInSite',
            ];

            $slugHelper = GeneralUtility::makeInstance(
                SlugHelper::class,
                'pages',
                'slug',
                $fieldConfig
            );

            $slug = trim($slugHelper->generate(['title' => $title, 'pid' => $pid], $pid), '/');
            if ($slug !== '') {
                return $slug;
            }
        } catch (\Throwable) {
        }

        $transliterated = strtr($title, [
            'à' => 'a', 'â' => 'a', 'ä' => 'a', 'á' => 'a', 'ã' => 'a', 'å' => 'a',
            'À' => 'a', 'Â' => 'a', 'Ä' => 'a', 'Á' => 'a', 'Ã' => 'a', 'Å' => 'a',
            'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
            'É' => 'e', 'È' => 'e', 'Ê' => 'e', 'Ë' => 'e',
            'î' => 'i', 'ï' => 'i', 'ì' => 'i', 'í' => 'i',
            'Î' => 'i', 'Ï' => 'i', 'Ì' => 'i', 'Í' => 'i',
            'ô' => 'o', 'ö' => 'o', 'ò' => 'o', 'ó' => 'o', 'õ' => 'o',
            'Ô' => 'o', 'Ö' => 'o', 'Ò' => 'o', 'Ó' => 'o', 'Õ' => 'o',
            'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ú' => 'u',
            'Ù' => 'u', 'Û' => 'u', 'Ü' => 'u', 'Ú' => 'u',
            'ç' => 'c', 'Ç' => 'c', 'ñ' => 'n', 'Ñ' => 'n',
            'œ' => 'oe', 'Œ' => 'oe', 'æ' => 'ae', 'Æ' => 'ae',
        ]);
        $clean = strtolower($transliterated);
        $clean = (string)preg_replace('/[^a-z0-9]+/', '-', $clean);
        $clean = trim($clean, '-');

        return $clean !== '' ? $clean : 'page';
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
