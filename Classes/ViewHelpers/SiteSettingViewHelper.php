<?php

declare(strict_types=1);

namespace Commune\SiteCommuneRgaa\ViewHelpers;

use TYPO3Fluid\Fluid\Core\ViewHelper\AbstractViewHelper;
use TYPO3\CMS\Core\Site\Entity\Site;

/**
 * ViewHelper pour récupérer proprement un paramètre de site TYPO3 v13 (SiteSettings).
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

    public function initializeArguments(): void
    {
        parent::initializeArguments();
        $this->registerArgument('key', 'string', 'Clé du paramètre de site (ex: commune.theme_css)', true);
        $this->registerArgument('default', 'mixed', 'Valeur par défaut si non définie', false, null);
    }

    public function render(): mixed
    {
        $key = (string)$this->arguments['key'];
        $default = $this->arguments['default'];

        $request = $this->renderingContext->getRequest();
        if (!$request || !method_exists($request, 'getAttribute')) {
            return $default;
        }

        $site = $request->getAttribute('site');
        if (!$site instanceof Site) {
            return $default;
        }

        $rawValue = null;

        // 1. Recherche via l'objet SiteSettings
        if (method_exists($site, 'getSettings')) {
            $settings = $site->getSettings();
            
            // a. Accès direct par la clé exacte
            if (method_exists($settings, 'get')) {
                $val = $settings->get($key);
                if ($val !== null && $val !== '') {
                    $rawValue = $val;
                }
            }

            // b. Accès par sous-tableau (ex: get('commune')['header_layout'])
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

            // c. Parcours de l'ensemble des réglages si getAll() disponible
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

        // 2. Recherche directe dans la configuration brute du site (Fallback)
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

        $normalized = $this->normalizeSettingValue($key, $rawValue);
        return $normalized !== null ? $normalized : $default;
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
