<?php

declare(strict_types=1);

namespace Commune\SiteCommuneRgaa\Controller;

use Commune\SiteCommuneRgaa\Service\SiteManagementService;
use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Messaging\AbstractMessage;
use TYPO3\CMS\Core\Page\PageRenderer;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Mvc\Controller\ActionController;

/**
 * Contrôleur du Module Backend Dédié : Administration des Sites Communes & Assistant de Déploiement.
 */
class BackendModuleController extends ActionController
{
    public function __construct(
        private readonly SiteManagementService $siteManagementService,
        private readonly ModuleTemplateFactory $moduleTemplateFactory
    ) {
    }

    /**
     * Vue d'ensemble Multi-Sites et Formulaire de paramétrage du site sélectionné.
     */
    public function indexAction(string $siteIdentifier = ''): ResponseInterface
    {
        $sites = $this->siteManagementService->getAllSites();

        if (empty($siteIdentifier) && !empty($sites)) {
            $firstSite = reset($sites);
            $siteIdentifier = $firstSite->getIdentifier();
        }

        $selectedSite = !empty($siteIdentifier) ? $this->siteManagementService->getSiteByIdentifier($siteIdentifier) : null;
        $definitions = $this->siteManagementService->getSettingsDefinitions();
        $currentSettings = !empty($siteIdentifier) ? $this->siteManagementService->getSiteSettings($siteIdentifier) : [];

        // Ajout propre du fichier CSS backend via le PageRenderer Core TYPO3
        $pageRenderer = GeneralUtility::makeInstance(PageRenderer::class);
        $pageRenderer->addCssFile('EXT:site_commune_rgaa/Resources/Public/Css/backend-module.css');

        $moduleTemplate = $this->moduleTemplateFactory->create($this->request);
        $moduleTemplate->setTitle('Collectivités RGAA', 'Administration & Multi-Sites');

        $moduleTemplate->assignMultiple([
            'sites' => $sites,
            'selectedSiteIdentifier' => $siteIdentifier,
            'selectedSite' => $selectedSite,
            'categories' => $definitions['categories'] ?? [],
            'settingsDefinitions' => $definitions['settings'] ?? [],
            'currentSettings' => $currentSettings,
        ]);

        return $moduleTemplate->renderResponse('BackendModule/Index');
    }

    /**
     * Sauvegarde les modifications de paramètres pour un site existant.
     *
     * @param string $siteIdentifier
     * @param array<string, mixed> $settings
     */
    public function saveAction(string $siteIdentifier, array $settings = []): ResponseInterface
    {
        if (empty($siteIdentifier)) {
            $this->addFlashMessage('Aucun site sélectionné pour la sauvegarde.', 'Erreur', AbstractMessage::ERROR);
            return $this->redirect('index');
        }

        $success = $this->siteManagementService->saveSiteSettings($siteIdentifier, $settings);

        if ($success) {
            $this->addFlashMessage(
                sprintf('Les paramètres du site "%s" ont été sauvegardés avec succès !', $siteIdentifier),
                'Succès',
                AbstractMessage::OK
            );
        } else {
            $this->addFlashMessage(
                sprintf('Une erreur est survenue lors de la sauvegarde du site "%s".', $siteIdentifier),
                'Erreur',
                AbstractMessage::ERROR
            );
        }

        return $this->redirect('index', null, null, ['siteIdentifier' => $siteIdentifier]);
    }

    /**
     * Assistant de Déploiement Pas à Pas (Wizard).
     *
     * @param int $step Étape courante (1 à 5)
     * @param array<string, mixed> $wizardData Données accumulées
     */
    public function wizardAction(int $step = 1, array $wizardData = []): ResponseInterface
    {
        $definitions = $this->siteManagementService->getSettingsDefinitions();

        $pageRenderer = GeneralUtility::makeInstance(PageRenderer::class);
        $pageRenderer->addCssFile('EXT:site_commune_rgaa/Resources/Public/Css/backend-module.css');

        $moduleTemplate = $this->moduleTemplateFactory->create($this->request);
        $moduleTemplate->setTitle('Assistant de Déploiement', 'Nouveau site commune');

        $moduleTemplate->assignMultiple([
            'step' => $step,
            'wizardData' => $wizardData,
            'settingsDefinitions' => $definitions['settings'] ?? [],
        ]);

        return $moduleTemplate->renderResponse('BackendModule/Wizard');
    }

    /**
     * Traitement final du Wizard pour la création du nouveau site commune.
     *
     * @param array<string, mixed> $wizardData
     */
    public function createSiteAction(array $wizardData = []): ResponseInterface
    {
        if (empty($wizardData['name'])) {
            $this->addFlashMessage('Le nom de la commune est obligatoire.', 'Erreur', AbstractMessage::ERROR);
            return $this->redirect('wizard', null, null, ['step' => 1, 'wizardData' => $wizardData]);
        }

        try {
            $newIdentifier = $this->siteManagementService->createNewSite($wizardData);

            $this->addFlashMessage(
                sprintf('Félicitations ! Le site de la commune "%s" a été déployé avec succès (Identifiant: %s).', $wizardData['name'], $newIdentifier),
                'Déploiement réussi',
                AbstractMessage::OK
            );

            return $this->redirect('index', null, null, ['siteIdentifier' => $newIdentifier]);
        } catch (\Throwable $e) {
            $this->addFlashMessage(
                'Erreur lors du déploiement : ' . $e->getMessage(),
                'Erreur de déploiement',
                AbstractMessage::ERROR
            );

            return $this->redirect('wizard', null, null, ['step' => 5, 'wizardData' => $wizardData]);
        }
    }
}
