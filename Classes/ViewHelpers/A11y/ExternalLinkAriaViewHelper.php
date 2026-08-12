<?php

declare(strict_types=1);

namespace Commune\SiteCommuneRgaa\ViewHelpers\A11y;

use TYPO3Fluid\Fluid\Core\ViewHelper\AbstractViewHelper;

/**
 * ViewHelper pour les liens externes ou s'ouvrant dans un nouvel onglet (RGAA Critère 13.2).
 * Ajoute un avertissement invisible pour les lecteurs d'écran (ex: "nouvelle fenêtre").
 */
class ExternalLinkAriaViewHelper extends AbstractViewHelper
{
    /**
     * @var bool
     */
    protected $escapeOutput = false;

    public function initializeArguments(): void
    {
        parent::initializeArguments();
        $this->registerArgument('target', 'string', 'Cible du lien (ex: _blank)', false, '');
        $this->registerArgument('message', 'string', 'Message d avertissement personnalisé', false, 'ouvre une nouvelle fenêtre');
    }

    public function render(): string
    {
        $target = trim((string)$this->arguments['target']);
        $message = trim((string)$this->arguments['message']);

        if ($target === '_blank') {
            return '<span class="visually-hidden"> (' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . ')</span><span aria-hidden="true" class="icon-external-link"> ↗</span>';
        }

        return '';
    }
}
