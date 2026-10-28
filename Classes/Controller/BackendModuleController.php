<?php

declare(strict_types=1);

namespace Commune\SiteCommuneRgaa\Controller;

use Commune\SiteCommuneRgaa\Service\CommuneBackendUserService;
use Commune\SiteCommuneRgaa\Service\CommuneFileStorageService;
use Commune\SiteCommuneRgaa\Service\SiteManagementService;
use Commune\SiteCommuneRgaa\Utility\FolderNameSanitizer;
use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Backend\Routing\UriBuilder as BackendUriBuilder;
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
        private readonly PageRenderer $pageRenderer,
        private readonly BackendUriBuilder $backendUriBuilder,
        private readonly InnogamAjaxController $innogamAjaxController
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
        $this->pageRenderer->addJsFile('EXT:site_commune_rgaa/Resources/Public/JavaScript/innogam-search.js');

        $innogamUrls = $this->resolveInnogamUrls();

        $lastUserReport = null;
        if (!empty($GLOBALS['BE_USER']) && method_exists($GLOBALS['BE_USER'], 'getSessionData')) {
            $lastUserReport = $GLOBALS['BE_USER']->getSessionData('tx_sitecommunergaa_last_user_report');
            if ($lastUserReport) {
                $GLOBALS['BE_USER']->setAndSaveSessionData('tx_sitecommunergaa_last_user_report', null);
            }
        }

        $moduleTemplate = $this->moduleTemplateFactory->create($this->request);
        $moduleTemplate->setTitle('Collectivités RGAA', 'Administration & Multi-Sites');

        $moduleTemplate->assignMultiple([
            'sites' => $sites,
            'selectedSiteIdentifier' => $siteIdentifier,
            'selectedSite' => $selectedSite,
            'categories' => $definitions['categories'] ?? [],
            'settingsDefinitions' => $definitions['settings'] ?? [],
            'currentSettings' => $currentSettings,
            'lastUserReport' => $lastUserReport,
            'innogamListUrl' => $innogamUrls['listUrl'],
            'innogamSearchUrl' => $innogamUrls['searchUrl'],
            'innogamDetailUrl' => $innogamUrls['detailUrl'],
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
     * @param int $step Étape courante (1 à 6)
     * @param array<string, mixed> $wizardData Données accumulées
     */
    public function wizardAction(int $step = 1, array $wizardData = []): ResponseInterface
    {
        $definitions = $this->siteManagementService->getSettingsDefinitions();
        $pageTreeCatalog = $this->siteManagementService->getDefaultPageTreeCatalog();
        $allPageKeys = $this->siteManagementService->getAllCatalogPageKeys();

        // Normalisation de la sélection de pages entre les étapes 4 et suivantes
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

        // Groupes backend existants dans TYPO3 & détection automatique de _RÉDACTEUR
        $availableBackendGroups = $this->siteManagementService->getAvailableBackendUserGroups();
        $defaultRedacteurGroupUid = $this->siteManagementService->findDefaultRedacteurGroupUid($availableBackendGroups);

        // Normalisation des groupes backend sélectionnés
        if (isset($wizardData['backend_groups']) && is_array($wizardData['backend_groups'])) {
            $selectedGroupsList = array_values(array_filter(array_map('intval', $wizardData['backend_groups'])));
            $wizardData['backend_groups_csv'] = implode(',', $selectedGroupsList);
            $wizardData['backend_groups_initialized'] = '1';
            unset($wizardData['backend_groups']);
        } elseif (!empty($wizardData['backend_groups_initialized'])) {
            $csv = (string)($wizardData['backend_groups_csv'] ?? '');
            $selectedGroupsList = $csv !== '' ? array_values(array_filter(array_map('intval', explode(',', $csv)))) : [];
        } else {
            $selectedGroupsList = $defaultRedacteurGroupUid > 0 ? [$defaultRedacteurGroupUid] : [];
            $wizardData['backend_groups_csv'] = implode(',', $selectedGroupsList);
        }

        $selectedGroupsMap = array_fill_keys($selectedGroupsList, true);

        // Pré-calcul dynamique des données des comptes utilisateurs et du groupe de la commune
        $communeName = trim((string)($wizardData['name'] ?? ''));
        $rawIdentifier = trim((string)($wizardData['identifier'] ?? ''));
        $targetIdentifier = $rawIdentifier !== '' ? FolderNameSanitizer::slugify($rawIdentifier) : FolderNameSanitizer::slugify($communeName);
        $upperCommune = CommuneBackendUserService::formatCommuneUpperName($communeName);
        $communeSlug = CommuneBackendUserService::buildCommuneSlug($communeName, $targetIdentifier);

        // Liste de tous les identifiants de sites existants dans TYPO3
        $allSites = $this->siteManagementService->getAllSites();
        $existingSiteIdentifiers = array_values(array_unique(array_keys($allSites)));
        $isDuplicateIdentifier = ($targetIdentifier !== '' && $this->siteManagementService->siteExists($targetIdentifier));

        // Blocage strict dès l'étape 1 si l'identifiant est déjà pris par une autre commune
        if ($step > 1 && $isDuplicateIdentifier) {
            $this->addFlashMessage(
                sprintf('Impossible de poursuivre : l\'identifiant « %s » est déjà attribué à une collectivité existante dans TYPO3. Veuillez modifier l\'identifiant à l\'étape 1.', $targetIdentifier),
                'Collectivité déjà existante',
                ContextualFeedbackSeverity::ERROR
            );
            return $this->redirect('wizard', null, null, ['step' => 1, 'wizardData' => $wizardData]);
        }

        if (!isset($wizardData['user_create'])) {
            $wizardData['user_create'] = '1';
        }

        // Si le nom de la commune est renseigné, préremplir le titre du groupe et les comptes avec le nom en majuscules
        if ($communeName !== '') {
            if (empty($wizardData['commune_group_title']) || $wizardData['commune_group_title'] === 'COMMUNE') {
                $wizardData['commune_group_title'] = $upperCommune;
            }
            if (empty($wizardData['user_secretariat_username']) || $wizardData['user_secretariat_username'] === 'secretariat.commune') {
                $wizardData['user_secretariat_username'] = 'secretariat.' . $communeSlug;
            }
            if (empty($wizardData['user_secretariat_realname']) || $wizardData['user_secretariat_realname'] === 'Secrétariat COMMUNE') {
                $wizardData['user_secretariat_realname'] = 'Secrétariat ' . $upperCommune;
            }
            if (empty($wizardData['user_elu_username']) || $wizardData['user_elu_username'] === 'elu.commune') {
                $wizardData['user_elu_username'] = 'elu.' . $communeSlug;
            }
            if (empty($wizardData['user_elu_realname']) || $wizardData['user_elu_realname'] === 'Élu COMMUNE') {
                $wizardData['user_elu_realname'] = 'Élu ' . $upperCommune;
            }
        }

        // Pré-remplissage de l'email du secrétariat avec l'adresse courriel de contact de l'annuaire/étape 1
        $contactEmail = trim((string)($wizardData['email'] ?? ''));
        if (empty($wizardData['user_secretariat_email']) && $contactEmail !== '') {
            $wizardData['user_secretariat_email'] = $contactEmail;
        }
        if (!isset($wizardData['user_elu_email'])) {
            $wizardData['user_elu_email'] = '';
        }

        $this->pageRenderer->addCssFile('EXT:site_commune_rgaa/Resources/Public/Css/backend-module.css');
        $this->pageRenderer->addJsFile('EXT:site_commune_rgaa/Resources/Public/JavaScript/backend-module.js');
        $this->pageRenderer->addJsFile('EXT:site_commune_rgaa/Resources/Public/JavaScript/innogam-search.js');

        $innogamUrls = $this->resolveInnogamUrls();

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
            'availableBackendGroups' => $availableBackendGroups,
            'defaultRedacteurGroupUid' => $defaultRedacteurGroupUid,
            'selectedGroupsMap' => $selectedGroupsMap,
            'selectedGroupsCount' => count($selectedGroupsList),
            'upperCommune' => $upperCommune,
            'communeSlug' => $communeSlug,
            'targetIdentifier' => $targetIdentifier,
            'isDuplicateIdentifier' => $isDuplicateIdentifier,
            'existingSiteIdentifiers' => $existingSiteIdentifiers,
            'existingSiteIdentifiersJson' => (string)json_encode($existingSiteIdentifiers),
            'innogamListUrl' => $innogamUrls['listUrl'],
            'innogamSearchUrl' => $innogamUrls['searchUrl'],
            'innogamDetailUrl' => $innogamUrls['detailUrl'],
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

        $rawIdent = trim((string)($wizardData['identifier'] ?? ''));
        $targetIdent = $rawIdent !== '' ? FolderNameSanitizer::slugify($rawIdent) : FolderNameSanitizer::slugify((string)($wizardData['name'] ?? ''));
        if ($targetIdent !== '' && $this->siteManagementService->siteExists($targetIdent)) {
            $this->addFlashMessage(
                sprintf('Impossible de déployer : une collectivité avec l\'identifiant « %s » existe déjà dans TYPO3. Aucune modification n\'a été apportée.', $targetIdent),
                'Collectivité déjà existante',
                ContextualFeedbackSeverity::ERROR
            );
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

            $userReport = $this->siteManagementService->getLastUserProvisioningReport();
            $this->addUserProvisioningFlashMessage($userReport);
            if ($userReport !== null && !empty($GLOBALS['BE_USER']) && method_exists($GLOBALS['BE_USER'], 'setAndSaveSessionData')) {
                $GLOBALS['BE_USER']->setAndSaveSessionData('tx_sitecommunergaa_last_user_report', $userReport);
            }

            return $this->redirect('index', null, null, ['siteIdentifier' => $newIdentifier]);
        } catch (\Throwable $e) {
            $this->addFlashMessage(
                'Erreur lors du déploiement : ' . $e->getMessage(),
                'Erreur de déploiement',
                ContextualFeedbackSeverity::ERROR
            );

            return $this->redirect('wizard', null, null, ['step' => 6, 'wizardData' => $wizardData]);
        }
    }

    /**
     * Action AJAX : Liste complète des collectivités pour l'autocomplétion instantanée.
     */
    public function innogamListAction(): ResponseInterface
    {
        return $this->innogamAjaxController->listAction($this->request);
    }

    /**
     * Action AJAX : Recherche de collectivités dans l'annuaire Innogam.
     */
    public function innogamSearchAction(): ResponseInterface
    {
        return $this->innogamAjaxController->searchAction($this->request);
    }

    /**
     * Action AJAX : Détail d'une collectivité et comparaison.
     */
    public function innogamDetailAction(): ResponseInterface
    {
        return $this->innogamAjaxController->detailAction($this->request);
    }

    /**
     * Résout les URLs des endpoints AJAX Innogam.
     * Tente la route Backend dédiée (Routes.php), et bascule de manière transparente sur
     * les actions du module Extbase en cas d'absence dans le cache de routage TYPO3.
     *
     * @return array{listUrl: string, searchUrl: string, detailUrl: string}
     */
    private function resolveInnogamUrls(): array
    {
        try {
            return [
                'listUrl' => (string)$this->backendUriBuilder->buildUriFromRoute('site_commune_rgaa_innogam_list'),
                'searchUrl' => (string)$this->backendUriBuilder->buildUriFromRoute('site_commune_rgaa_innogam_search'),
                'detailUrl' => (string)$this->backendUriBuilder->buildUriFromRoute('site_commune_rgaa_innogam_detail'),
            ];
        } catch (\Throwable) {
            return [
                'listUrl' => (string)$this->uriBuilder->reset()->uriFor('innogamList'),
                'searchUrl' => (string)$this->uriBuilder->reset()->uriFor('innogamSearch'),
                'detailUrl' => (string)$this->uriBuilder->reset()->uriFor('innogamDetail'),
            ];
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
        );

        [$title, $severity, $body] = match ($report['status']) {
            CommuneFileStorageService::STATUS_SUCCESS => ['Gestionnaire de fichiers prêt', ContextualFeedbackSeverity::OK, $summary],
            CommuneFileStorageService::STATUS_WARNING => ['Gestionnaire de fichiers : points d\'attention', ContextualFeedbackSeverity::WARNING, $summary . ' ' . implode(' ', $report['messages'])],
            default => ['Gestionnaire de fichiers non initialisé', ContextualFeedbackSeverity::ERROR, 'Le site a bien été créé, mais les dossiers et le stockage de fichiers doivent être créés manuellement. ' . implode(' ', $report['messages'])],
        };

        $this->addFlashMessage($body, $title, $severity);
    }

    /**
     * Restitue le résultat du provisionnement du groupe et des utilisateurs de la commune.
     *
     * @param array{
     *     status: string,
     *     messages: array<int, string>,
     *     communeGroupUid: int,
     *     communeGroupTitle: string,
     *     assignedGroupUids: array<int, int>,
     *     users: array{
     *         secretariat: array{uid: int, username: string, realName: string, email: string, plainPassword: string, success: bool},
     *         elu: array{uid: int, username: string, realName: string, email: string, plainPassword: string, success: bool}
     *     }
     * }|null $report
     */
    private function addUserProvisioningFlashMessage(?array $report): void
    {
        if ($report === null) {
            return;
        }

        $groupTitle = $report['communeGroupTitle'] ?? '';
        $users = $report['users'] ?? [];
        $sec = $users['secretariat'] ?? [];
        $elu = $users['elu'] ?? [];

        $hasUsers = (!empty($sec['username']) || !empty($elu['username']));
        $summary = $hasUsers
            ? sprintf(
                'Groupe « %s » créé. Comptes utilisateurs prêts : « %s » (%s) et « %s » (%s). Mots de passe générés (à réinitialiser à la 1ère connexion).',
                $groupTitle,
                $sec['username'] ?? 'secretariat',
                $sec['realName'] ?? 'Secrétariat',
                $elu['username'] ?? 'elu',
                $elu['realName'] ?? 'Élu'
            )
            : sprintf('Groupe « %s » créé avec succès (aucun compte utilisateur demandé).', $groupTitle);

        [$title, $severity, $body] = match ($report['status']) {
            CommuneBackendUserService::STATUS_SUCCESS => ['Accès utilisateurs déployés', ContextualFeedbackSeverity::OK, $summary],
            CommuneBackendUserService::STATUS_WARNING => ['Accès utilisateurs : points d\'attention', ContextualFeedbackSeverity::WARNING, $summary . ' ' . implode(' ', $report['messages'])],
            default => ['Erreur lors de la création des accès', ContextualFeedbackSeverity::ERROR, implode(' ', $report['messages'])],
        };

        $this->addFlashMessage($body, $title, $severity);
    }
}
