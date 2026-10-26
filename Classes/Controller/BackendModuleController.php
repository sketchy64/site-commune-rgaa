<?php

declare(strict_types=1);

namespace Commune\SiteCommuneRgaa\Controller;

use Commune\SiteCommuneRgaa\Service\CommuneFileStorageService;
use Commune\SiteCommuneRgaa\Service\SiteManagementService;
use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Page\PageRenderer;
use TYPO3\CMS\Core\Type\ContextualFeedbackSeverity;
use TYPO3\CMS\Extbase\Mvc\Controller\ActionController;

/**
 * Contrôleur du Module Backend Dédié : Administration des Sites Communes & Assistant de Déploiement.
 */
class BackendModuleController extends ActionController
{
    public function __construct(
        private readonly SiteManagementService $siteManagementService,
        private readonly ModuleTemplateFactory $moduleTemplateFactory,
        private readonly PageRenderer $pageRenderer
    ) {
    }

    /**
     * Vue d'ensemble Multi-Sites et Formulaire de paramétrage du site sélectionné.
     */
    public function indexAction(string $siteIdentifier = ''): ResponseInterface
    {
        $siteIdentifier = trim($siteIdentifier);

        if ($siteIdentifier === '') {
            if ($this->request->hasArgument('siteIdentifier')) {
                $siteIdentifier = trim((string)$this->request->getArgument('siteIdentifier'));
            } else {
                $queryParams = $this->request->getQueryParams();
                $parsedBody = is_array($this->request->getParsedBody()) ? $this->request->getParsedBody() : [];
                $extbaseParams = $queryParams['tx_sitecommunergaa_site_communergaa'] ?? $parsedBody['tx_sitecommunergaa_site_communergaa'] ?? [];

                $siteIdentifier = trim((string)(
                    $queryParams['siteIdentifier']
                    ?? $parsedBody['siteIdentifier']
                    ?? (is_array($extbaseParams) ? ($extbaseParams['siteIdentifier'] ?? '') : '')
                ));
            }
        }

        $sites = $this->siteManagementService->getAllSites();

        if ((empty($siteIdentifier) || !isset($sites[$siteIdentifier])) && !empty($sites)) {
            $firstSite = reset($sites);
            $siteIdentifier = $firstSite->getIdentifier();
        }

        $selectedSite = !empty($siteIdentifier) ? $this->siteManagementService->getSiteByIdentifier($siteIdentifier) : null;
        $definitions = $this->siteManagementService->getSettingsDefinitions();
        $currentSettings = !empty($siteIdentifier) ? $this->siteManagementService->getSiteSettings($siteIdentifier) : [];

        // Ajout propre des fichiers CSS et JS backend via le PageRenderer Core TYPO3 (Compatibilité CSP v12/v13/v14)
        $this->pageRenderer->addCssFile('EXT:site_commune_rgaa/Resources/Public/Css/backend-module.css');
        $this->pageRenderer->addJsFile('EXT:site_commune_rgaa/Resources/Public/JavaScript/backend-module.js');

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
            $this->addFlashMessage('Aucun site sélectionné pour la sauvegarde.', 'Erreur', ContextualFeedbackSeverity::ERROR);
            return $this->redirect('index');
        }

        $result = $this->siteManagementService->saveSiteSettings($siteIdentifier, $settings);

        if ($result['success']) {
            $this->addFlashMessage(
                sprintf('Les paramètres du site "%s" ont été sauvegardés avec succès !', $siteIdentifier),
                'Succès',
                ContextualFeedbackSeverity::OK
            );
        } else {
            $this->addFlashMessage(
                sprintf('Impossible de sauvegarder le site "%s" : %s', $siteIdentifier, $result['error'] ?? 'Erreur inconnue'),
                'Erreur de sauvegarde',
                ContextualFeedbackSeverity::ERROR
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
        $pageTreeCatalog = $this->siteManagementService->getDefaultPageTreeCatalog();
        $allPageKeys = $this->siteManagementService->getAllCatalogPageKeys();

        // Normalisation de la sélection de pages entre les étapes 4 et 5
        if (isset($wizardData['selected_pages']) && is_array($wizardData['selected_pages'])) {
            $selectedPagesList = array_values(array_filter(array_map('strval', $wizardData['selected_pages'])));
            $wizardData['selected_pages_csv'] = implode(',', $selectedPagesList);
            $wizardData['selected_pages_initialized'] = '1';
            unset($wizardData['selected_pages']);
        } elseif (!empty($wizardData['selected_pages_initialized'])) {
            $csv = (string)($wizardData['selected_pages_csv'] ?? '');
            $selectedPagesList = $csv !== '' ? array_values(array_filter(explode(',', $csv))) : [];
        } else {
            $selectedPagesList = $allPageKeys;
            $wizardData['selected_pages_csv'] = implode(',', $selectedPagesList);
        }

        $selectedPagesMap = array_fill_keys($selectedPagesList, true);

        $this->pageRenderer->addCssFile('EXT:site_commune_rgaa/Resources/Public/Css/backend-module.css');
        $this->pageRenderer->addJsFile('EXT:site_commune_rgaa/Resources/Public/JavaScript/backend-module.js');

        $moduleTemplate = $this->moduleTemplateFactory->create($this->request);
        $moduleTemplate->setTitle('Assistant de Déploiement', 'Nouveau site commune');

        $moduleTemplate->assignMultiple([
            'step' => $step,
            'wizardData' => $wizardData,
            'settingsDefinitions' => $definitions['settings'] ?? [],
            'pageTreeCatalog' => $pageTreeCatalog,
            'selectedPagesMap' => $selectedPagesMap,
            'selectedPagesCount' => count($selectedPagesList),
            'totalPagesCount' => count($allPageKeys),
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
            $this->addFlashMessage('Le nom de la commune est obligatoire.', 'Erreur', ContextualFeedbackSeverity::ERROR);
            return $this->redirect('wizard', null, null, ['step' => 1, 'wizardData' => $wizardData]);
        }

        try {
            $newIdentifier = $this->siteManagementService->createNewSite($wizardData);

            $this->addFlashMessage(
                sprintf('Félicitations ! Le site de la commune "%s" et son arborescence ont été déployés avec succès (Identifiant: %s).', $wizardData['name'], $newIdentifier),
                'Déploiement réussi',
                ContextualFeedbackSeverity::OK
            );

            $this->addFileStorageFlashMessage($this->siteManagementService->getLastFileStorageReport());

            return $this->redirect('index', null, null, ['siteIdentifier' => $newIdentifier]);
        } catch (\Throwable $e) {
            $this->addFlashMessage(
                'Erreur lors du déploiement : ' . $e->getMessage(),
                'Erreur de déploiement',
                ContextualFeedbackSeverity::ERROR
            );

            return $this->redirect('wizard', null, null, ['step' => 5, 'wizardData' => $wizardData]);
        }
    }

    /**
     * Restitue le résultat de la création des dossiers fileadmin et du stockage FAL de la commune.
     *
     * @param array{status: string, messages: array<int, string>, storageUid: int, fileMountUid: int, createdFolders: int, basePath: string}|null $report
     */
    private function addFileStorageFlashMessage(?array $report): void
    {
        if ($report === null) {
            return;
        }

        $storageLabel = !empty($report['customStorageCreated'])
            ? sprintf('stockage dédié uid %d', $report['storageUid'])
            : 'stockage principal fileadmin';

        $summary = sprintf(
            'Dossier « %s » (%s), %d sous-dossier(s) créé(s), point de montage uid %d.',
            $report['basePath'],
            $storageLabel,
            $report['createdFolders'],
            $report['fileMountUid']
        );[$title, $severity, $body] = match ($report['status']) {
            CommuneFileStorageService::STATUS_SUCCESS => ['Gestionnaire de fichiers prêt', ContextualFeedbackSeverity::OK, $summary],
            CommuneFileStorageService::STATUS_WARNING => ['Gestionnaire de fichiers : points d\'attention', ContextualFeedbackSeverity::WARNING, $summary . ' ' . implode(' ', $report['messages'])],
            default => ['Gestionnaire de fichiers non initialisé', ContextualFeedbackSeverity::ERROR, 'Le site a bien été créé, mais les dossiers et le stockage de fichiers doivent être créés manuellement. ' . implode(' ', $report['messages'])],
        };

        $this->addFlashMessage($body, $title, $severity);
    }
}
