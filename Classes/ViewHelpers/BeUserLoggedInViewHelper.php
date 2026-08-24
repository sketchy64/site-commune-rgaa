<?php

declare(strict_types=1);

namespace Commune\SiteCommuneRgaa\ViewHelpers;

use TYPO3Fluid\Fluid\Core\ViewHelper\AbstractViewHelper;

/**
 * ViewHelper pour vérifier si un utilisateur Backend (Admin / BE User) est actuellement connecté.
 *
 * Usage dans Fluid :
 * <f:if condition="{rgaa:beUserLoggedIn()}"> ... </f:if>
 */
class BeUserLoggedInViewHelper extends AbstractViewHelper
{
    /**
     * @var bool
     */
    protected $escapeOutput = false;

    public function render(): bool
    {
        $beUser = $GLOBALS['BE_USER'] ?? null;
        return is_object($beUser) && isset($beUser->user['uid']) && (int)$beUser->user['uid'] > 0;
    }
}
