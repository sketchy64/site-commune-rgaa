<?php

declare(strict_types=1);

namespace Commune\SiteCommuneRgaa\ViewHelpers;

use Psr\Http\Message\ServerRequestInterface;
use Symfony\Component\Yaml\Yaml;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3Fluid\Fluid\Core\ViewHelper\AbstractViewHelper;

/**
 * ViewHelper pour récupérer proprement un paramètre de site TYPO3 v12/v13/v14 (SiteSettings).
 * Synchronisé bidirectionnellement avec les constantes TypoScript sys_template et settings.yaml.
 *
 * Usage :
 * {rgaa:siteSetting(key: 'commune.theme_css', default: communeTheme)}
 */
class SiteSettingViewHelper extends AbstractViewHelper
{
    /**
     * @var bool
     */
    protected $escapeOutput = false;

    /**
     * Cache mémoire pour le timestamp de sys_template par page racine
     * @var array<int, int>
     */
    private static array $templateTstampCache = [];

    public function initializeArguments(): void
    {
        parent::initializeArguments();
        $this->registerArgument('key', 'string', 'Clé du paramètre de site (ex: commune.theme_css)', true);
        $this->registerArgument('default', 'mixed', 'Valeur par défaut si non définie (Constante TypoScript)', false, null);
    }

    public function render(): mixed
    {
        $key = (string)$this->arguments['key'];
        $default = $this->arguments['default'];

        $request = $this->resolveRequest();
        if (!$request instanceof ServerRequestInterface) {
            $normalized = $this->normalizeSettingValue($key, $default);
            return $normalized !== null ? $normalized : $default;
        }

        $site = $request->getAttribute('site');
        if (!$site instanceof Site) {
            $normalized = $this->normalizeSettingValue($key, $default);
            return $normalized !== null ? $normalized : $default;
        }

        // 1. Détermination du timestamp de sys_template pour le site courant
        $rootPageId = $site->getRootPageId();
        $templateTstamp = $this->getSysTemplateTimestamp($rootPageId);

        // 2. Recherche directe dans config/sites/<identifier>/settings.yaml avec son timestamp
        $yamlValue = null;
        $yamlMtime = 0;
        if (method_exists($site, 'getIdentifier')) {
            $settingsFile = $this->resolveSiteSettingsFilePath($site->getIdentifier());
            if ($settingsFile !== '' && file_exists($settingsFile)) {
                $yamlMtime = (int)filemtime($settingsFile);
                try {
                    $parsed = Yaml::parseFile($settingsFile);
                    if (is_array($parsed)) {
                        $val = $this->getValueByDotPath($parsed, $key);
                        if ($val !== null && $val !== '') {
                            $yamlValue = $val;
                        }
                    }
                } catch (\Throwable) {
                }
            }
        }

        // 3. Arbitrage temporel bidirectionnel :
        // Si TypoScript (sys_template) a été modifié plus récemment que settings.yaml,
        // la valeur transmise par les constantes TypoScript ($default) prévaut.
        $rawValue = null;
        if ($templateTstamp > $yamlMtime && $default !== null && $default !== '') {
            $rawValue = $default;
        } elseif ($yamlValue !== null && $yamlValue !== '') {
            $rawValue = $yamlValue;
        } elseif ($default !== null && $default !== '') {
            $rawValue = $default;
        }

        // 4. Fallback sur la configuration explicite du site si disponible
        if ($rawValue === null && method_exists($site, 'getConfiguration')) {
            $config = $site->getConfiguration();
            if (isset($config['settings']) && is_array($config['settings'])) {
                $val = $this->getValueByDotPath($config['settings'], $key);
                if ($val !== null && $val !== '') {
                    $rawValue = $val;
                }
            }
            if ($rawValue === null) {
                $val = $this->getValueByDotPath($config, $key);
                if ($val !== null && $val !== '') {
                    $rawValue = $val;
                }
            }
        }

        // 5. Fallback final sur les SiteSettings globaux du Core (définitions d'usine)
        if ($rawValue === null && method_exists($site, 'getSettings')) {
            $settings = $site->getSettings();
            if (method_exists($settings, 'get')) {
                $val = $settings->get($key);
                if ($val !== null && $val !== '') {
                    $rawValue = $val;
                }
            }
            if ($rawValue === null && str_contains($key, '.') && method_exists($settings, 'get')) {
                $parts = explode('.', $key, 2);
                $section = $settings->get($parts[0]);
                if (is_array($section)) {
                    $val = $this->getValueByDotPath($section, $parts[1]);
                    if ($val !== null && $val !== '') {
                        $rawValue = $val;
                    }
                }
            }
            if ($rawValue === null && method_exists($settings, 'getAll')) {
                $all = $settings->getAll();
                if (is_array($all)) {
                    $val = $this->getValueByDotPath($all, $key);
                    if ($val !== null && $val !== '') {
                        $rawValue = $val;
                    }
                }
            }
        }

        $normalized = $this->normalizeSettingValue($key, $rawValue);
        return $normalized !== null ? $normalized : $default;
    }

    /**
     * Récupère l'objet ServerRequestInterface de manière compatible TYPO3 v12, v13 et v14.
     */
    private function resolveRequest(): ?ServerRequestInterface
    {
        if (
            method_exists($this->renderingContext, 'hasAttribute')
            && $this->renderingContext->hasAttribute(ServerRequestInterface::class)
        ) {
            $request = $this->renderingContext->getAttribute(ServerRequestInterface::class);
            if ($request instanceof ServerRequestInterface) {
                return $request;
            }
        }

        if (method_exists($this->renderingContext, 'getRequest')) {
            $request = $this->renderingContext->getRequest();
            if ($request instanceof ServerRequestInterface) {
                return $request;
            }
        }

        $globalRequest = $GLOBALS['TYPO3_REQUEST'] ?? null;
        return $globalRequest instanceof ServerRequestInterface ? $globalRequest : null;
    }

    /**
     * Résout le chemin du fichier settings.yaml du site (compatible mode Composer et Classic).
     */
    private function resolveSiteSettingsFilePath(string $identifier): string
    {
        $configPathFile = Environment::getConfigPath() . '/sites/' . $identifier . '/settings.yaml';
        if (file_exists($configPathFile)) {
            return $configPathFile;
        }

        $legacyPublicFile = GeneralUtility::getFileAbsFileName('config/sites/' . $identifier . '/settings.yaml');
        if ($legacyPublicFile !== '' && file_exists($legacyPublicFile)) {
            return $legacyPublicFile;
        }

        return $configPathFile;
    }

    /**
     * Récupère le timestamp de dernière modification de sys_template sur la page racine (avec cache).
     */
    private function getSysTemplateTimestamp(int $rootPageId): int
    {
        if ($rootPageId <= 0) {
            return 0;
        }

        if (isset(self::$templateTstampCache[$rootPageId])) {
            return self::$templateTstampCache[$rootPageId];
        }

        try {
            $connectionPool = GeneralUtility::makeInstance(ConnectionPool::class);
            $queryBuilder = $connectionPool->getQueryBuilderForTable('sys_template');
            $row = $queryBuilder
                ->select('tstamp')
                ->from('sys_template')
                ->where($queryBuilder->expr()->eq('pid', $queryBuilder->createNamedParameter($rootPageId, Connection::PARAM_INT)))
                ->orderBy('root', 'DESC')
                ->addOrderBy('sorting', 'ASC')
                ->setMaxResults(1)
                ->executeQuery()
                ->fetchAssociative();

            $tstamp = ($row && isset($row['tstamp'])) ? (int)$row['tstamp'] : 0;
            self::$templateTstampCache[$rootPageId] = $tstamp;
            return $tstamp;
        } catch (\Throwable) {
            self::$templateTstampCache[$rootPageId] = 0;
            return 0;
        }
    }

    /**
     * Normalise les valeurs d'énumération TYPO3 (0/1 -> standard/integrated)
     */
    private function normalizeSettingValue(string $key, mixed $value): mixed
    {
        if ($value === null || $value === '') {
            return null;
        }
        if ($key === 'commune.header_layout') {
            if ($value === '1' || $value === 1 || $value === 'integrated') {
                return 'integrated';
            }
            if ($value === '0' || $value === 0 || $value === 'standard') {
                return 'standard';
            }
        }
        return $value;
    }

    /**
     * Recherche récursive par chemin pointé (ex: 'header_layout' dans ['commune' => ['header_layout' => 'integrated']])
     */
    private function getValueByDotPath(array $array, string $path): mixed
    {
        if (array_key_exists($path, $array) && $array[$path] !== null && $array[$path] !== '') {
            return $array[$path];
        }

        $keys = explode('.', $path);
        $current = $array;
        foreach ($keys as $k) {
            if (is_array($current) && array_key_exists($k, $current)) {
                $current = $current[$k];
            } else {
                return null;
            }
        }

        if ($current !== null && $current !== '') {
            return $current;
        }

        return null;
    }
}
