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
        if ($request && method_exists($request, 'getAttribute')) {
            $site = $request->getAttribute('site');
            if ($site instanceof Site && method_exists($site, 'getSettings')) {
                $settings = $site->getSettings();
                if (method_exists($settings, 'get')) {
                    $val = $settings->get($key);
                    if ($val !== null && $val !== '') {
                        return $val;
                    }
                }
            }
        }

        return $default;
    }
}
