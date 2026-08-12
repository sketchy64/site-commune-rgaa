<?php

declare(strict_types=1);

namespace Commune\SiteCommuneRgaa\ViewHelpers\A11y;

use TYPO3Fluid\Fluid\Core\ViewHelper\AbstractViewHelper;

/**
 * ViewHelper pour générer des intitulés de lien accessibles (RGAA Critère 6.1).
 * Évite les liens vagues ("En savoir plus", "Lire la suite", "Cliquez ici") en les contextualisant.
 *
 * Exemple :
 * <a href="{link}" aria-label="{rgaa:a11y.ariaLabel(action: 'Lire la suite', context: newsItem.title)}">Lire la suite</a>
 */
class AriaLabelViewHelper extends AbstractViewHelper
{
    /**
     * @var bool
     */
    protected $escapeOutput = true;

    public function initializeArguments(): void
    {
        parent::initializeArguments();
        $this->registerArgument('action', 'string', 'Action du lien (ex: En savoir plus, Télécharger)', true);
        $this->registerArgument('context', 'string', 'Contexte ou titre spécifique (ex: Arrêté municipal du 12 juin)', true);
        $this->registerArgument('extra', 'string', 'Information complémentaire (ex: format PDF, 2 Mo)', false, '');
    }

    public function render(): string
    {
        $action = trim((string)$this->arguments['action']);
        $context = trim((string)$this->arguments['context']);
        $extra = trim((string)$this->arguments['extra']);

        $label = $action . ' : ' . $context;
        if (!empty($extra)) {
            $label .= ' (' . $extra . ')';
        }

        return htmlspecialchars($label, ENT_QUOTES, 'UTF-8');
    }
}
