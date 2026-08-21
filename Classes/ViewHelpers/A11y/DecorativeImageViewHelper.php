<?php

declare(strict_types=1);

namespace Commune\SiteCommuneRgaa\ViewHelpers\A11y;

use TYPO3Fluid\Fluid\Core\ViewHelper\AbstractViewHelper;

/**
 * ViewHelper pour traiter les images selon le RGAA (Critères 1.1 à 1.9).
 * Si une image est purement décorative ou si son alternative est vide,
 * retourne un tableau d'attributs HTML compatible avec additionalAttributes.
 */
class DecorativeImageViewHelper extends AbstractViewHelper
{
    /**
     * @var bool
     */
    protected $escapeOutput = false;

    public function initializeArguments(): void
    {
        parent::initializeArguments();
        $this->registerArgument('alt', 'string', 'Texte alternatif fourni', false, '');
        $this->registerArgument('isDecorative', 'bool', 'Forcer le statut décoratif', false, false);
    }

    public function render(): array
    {
        $alt = trim((string)$this->arguments['alt']);
        $isDecorative = (bool)$this->arguments['isDecorative'];

        if ($isDecorative || $alt === '') {
            return [
                'alt' => '',
                'role' => 'presentation',
                'aria-hidden' => 'true',
            ];
        }

        return [
            'alt' => $alt,
        ];
    }
}
