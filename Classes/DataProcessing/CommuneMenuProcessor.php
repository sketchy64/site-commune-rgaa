<?php

declare(strict_types=1);

namespace Commune\SiteCommuneRgaa\DataProcessing;

use Psr\Http\Message\ServerRequestInterface;
use Symfony\Component\Yaml\Yaml;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Frontend\ContentObject\ContentObjectRenderer;
use TYPO3\CMS\Frontend\ContentObject\DataProcessorInterface;
use TYPO3\CMS\Frontend\DataProcessing\MenuProcessor;

/**
 * Processeur de données intelligent et résilient pour la navigation communale.
 * Compatible TYPO3 v12.4, v13.4 et v14.x.
 *
 * Résout automatiquement les dossiers du Menu Principal, du Pied de page et
 * de la Navigation latérale (avec validation d'appartenance au site et auto-détection),
 * puis alimente les variables Fluid :
 * - {mainMenuItems} (Megamenu 3 niveaux conforme RGAA 4.1)
 * - {sitemapTree} (Plan du site hiérarchisé 4 niveaux)
 * - {footerMenuItems} (Liens utiles de bas de page)
 * - {subMenuItems} (Navigation de rubrique latérale)
 *
 * Rétrocompatible : alimente également {mainNavigation}, {footerNavigation} et {subNavigation}
 * au format HTML brut si ces variables sont absentes.
 */
class CommuneMenuProcessor implements DataProcessorInterface
{
    private readonly ConnectionPool $connectionPool;
    private readonly MenuProcessor $menuProcessor;

    /**
     * Cache mémoire des timestamps sys_template par page racine
     * @var array<int, int>
     */
    private static array $templateTstampCache = [];

    public function __construct(
        ?ConnectionPool $connectionPool = null,
        ?MenuProcessor $menuProcessor = null
    ) {
        $this->connectionPool = $connectionPool ?? GeneralUtility::makeInstance(ConnectionPool::class);
        $this->menuProcessor = $menuProcessor ?? GeneralUtility::makeInstance(MenuProcessor::class);
    }

    public function process(
        ContentObjectRenderer $cObj,
        array $contentObjectConfiguration,
        array $processorConfiguration,
        array $processedData
    ): array {
        $request = method_exists($cObj, 'getRequest') ? $cObj->getRequest() : ($GLOBALS['TYPO3_REQUEST'] ?? null);
        $site = $request?->getAttribute('site');
        $rootPageId = $this->resolveRootPageId($site, $request);

        // 1. Résolution & validation du dossier de Menu Principal
        $mainMenuFolderUid = $this->resolveMainMenuFolderUid($site, $rootPageId);

        // 2. Résolution & validation du dossier de Pied de Page
        $footerFolderUid = $this->resolveFooterFolderUid($site, $rootPageId);

        // 3. Détermination du niveau d'entrée de la navigation latérale (sous-pages)
        $subEntryLevel = $this->resolveSubNavigationEntryLevel($rootPageId, $request);

        // 4. Génération de mainMenuItems (Megamenu 3 niveaux)
        if ($mainMenuFolderUid > 0) {
            $mainMenuConf = [
                'special' => 'directory',
                'special.' => [
                    'value' => (string)$mainMenuFolderUid,
                ],
                'levels' => 3,
                'expandAll' => 1,
                'includeSpacer' => 0,
                'as' => 'mainMenuItems',
                'excludeDoktypes' => '199,254',
            ];
            $processedData = $this->menuProcessor->process($cObj, $contentObjectConfiguration, $mainMenuConf, $processedData);
        }

        // 5. Génération de sitemapTree (Plan du site 4 niveaux)
        if ($mainMenuFolderUid > 0) {
            $sitemapConf = [
                'special' => 'directory',
                'special.' => [
                    'value' => (string)$mainMenuFolderUid,
                ],
                'levels' => 4,
                'expandAll' => 1,
                'includeSpacer' => 0,
                'as' => 'sitemapTree',
                'excludeDoktypes' => '199,254',
            ];
            $processedData = $this->menuProcessor->process($cObj, $contentObjectConfiguration, $sitemapConf, $processedData);
        }

        // 6. Génération de footerMenuItems (Pied de page)
        if ($footerFolderUid > 0) {
            $footerConf = [
                'special' => 'directory',
                'special.' => [
                    'value' => (string)$footerFolderUid,
                ],
                'levels' => 1,
                'expandAll' => 1,
                'includeSpacer' => 0,
                'as' => 'footerMenuItems',
                'excludeDoktypes' => '199,254',
            ];
            $processedData = $this->menuProcessor->process($cObj, $contentObjectConfiguration, $footerConf, $processedData);
        }

        // 7. Génération de subMenuItems (Navigation latérale de rubrique)
        $subConf = [
            'entryLevel' => $subEntryLevel,
            'levels' => 3,
            'expandAll' => 0,
            'as' => 'subMenuItems',
            'excludeDoktypes' => '199,254',
        ];
        $processedData = $this->menuProcessor->process($cObj, $contentObjectConfiguration, $subConf, $processedData);

        // 8. Rétrocompatibilité HTML brut pour les gabarits non migrés
        if (empty($processedData['mainNavigation']) && !empty($processedData['mainMenuItems'])) {
            $processedData['mainNavigation'] = $this->renderMainMenuHtml($processedData['mainMenuItems']);
        }

        if (empty($processedData['footerNavigation']) && !empty($processedData['footerMenuItems'])) {
            $processedData['footerNavigation'] = $this->renderFooterMenuHtml($processedData['footerMenuItems']);
        }

        if (empty($processedData['subNavigation']) && !empty($processedData['subMenuItems'])) {
            $processedData['subNavigation'] = $this->renderSubMenuHtml($processedData['subMenuItems']);
        }

        return $processedData;
    }

    /**
     * Résout l'UID de la page racine du site courant.
     */
    private function resolveRootPageId(?Site $site, ?ServerRequestInterface $request): int
    {
        if ($site instanceof Site && $site->getRootPageId() > 0) {
            return $site->getRootPageId();
        }

        $pageInfo = $request?->getAttribute('frontend.page.information');
        if (is_object($pageInfo) && method_exists($pageInfo, 'getRootLine')) {
            $rootLine = $pageInfo->getRootLine();
            if (!empty($rootLine) && isset($rootLine[0]['uid'])) {
                return (int)$rootLine[0]['uid'];
            }
        }

        if (isset($GLOBALS['TSFE']->rootLine[0]['uid'])) {
            return (int)$GLOBALS['TSFE']->rootLine[0]['uid'];
        }

        return 1;
    }

    /**
     * Résout et valide l'identifiant du dossier du Menu Principal.
     */
    private function resolveMainMenuFolderUid(?Site $site, int $rootPageId): int
    {
        $configuredUid = (int)$this->resolveSiteSetting($site, $rootPageId, 'commune.main_menu_folder_uid');

        // 1. Validation de l'UID configuré (s'il existe, appartient au site et a des pages)
        if ($configuredUid > 0 && $this->isFolderValidForSite($configuredUid, $rootPageId)) {
            return $configuredUid;
        }

        // 2. Auto-détection d'un dossier système direct sous la racine ("Menu principal")
        $autoDetected = $this->findDirectFolderByPattern($rootPageId, ['menu principal', 'menu-principal', 'dossier-menu-principal']);
        if ($autoDetected > 0) {
            return $autoDetected;
        }

        // 3. Auto-détection de n'importe quel dossier système sous la racine contenant des pages standards
        $firstFolderWithPages = $this->findFirstFolderWithNavigablePages($rootPageId);
        if ($firstFolderWithPages > 0) {
            return $firstFolderWithPages;
        }

        // 4. Repli sur la page racine elle-même (arborescence standard TYPO3 sans dossier intermédiaire)
        return $rootPageId;
    }

    /**
     * Résout et valide l'identifiant du dossier du Menu de Pied de Page.
     */
    private function resolveFooterFolderUid(?Site $site, int $rootPageId): int
    {
        $configuredUid = (int)$this->resolveSiteSetting($site, $rootPageId, 'commune.footer_folder_uid');

        // 1. Validation de l'UID configuré
        if ($configuredUid > 0 && $this->isFolderValidForSite($configuredUid, $rootPageId)) {
            return $configuredUid;
        }

        // 2. Auto-détection d'un dossier système direct ("Menu en pied de page")
        $autoDetected = $this->findDirectFolderByPattern($rootPageId, ['pied de page', 'menu en pied de page', 'menu-pied-de-page', 'dossier-menu-pied-de-page']);
        if ($autoDetected > 0) {
            return $autoDetected;
        }

        return $rootPageId;
    }

    /**
     * Détermine dynamiquement le niveau d'entrée pour le sous-menu de rubrique (subNavigation).
     * Si la page courante est sous un dossier système (ex: Root -> Menu principal -> Rubrique), entryLevel = 2.
     * Si les pages sont directement sous la racine (ex: Root -> Rubrique), entryLevel = 1.
     */
    private function resolveSubNavigationEntryLevel(int $rootPageId, ?ServerRequestInterface $request): int
    {
        $rootLine = [];
        $pageInfo = $request?->getAttribute('frontend.page.information');
        if (is_object($pageInfo) && method_exists($pageInfo, 'getRootLine')) {
            $rootLine = $pageInfo->getRootLine();
        } elseif (isset($GLOBALS['TSFE']->rootLine) && is_array($GLOBALS['TSFE']->rootLine)) {
            $rootLine = $GLOBALS['TSFE']->rootLine;
        }

        if (count($rootLine) >= 2) {
            $level1Doktype = (int)($rootLine[1]['doktype'] ?? 1);
            if ($level1Doktype === 254) {
                // Page dans un dossier système (Menu principal) -> niveau 2
                return 2;
            }
        }

        return 1;
    }

    /**
     * Vérifie si un UID de dossier est valide, non supprimé, non masqué et appartient à la racine du site.
     */
    private function isFolderValidForSite(int $folderUid, int $rootPageId): bool
    {
        try {
            $qb = $this->connectionPool->getQueryBuilderForTable('pages');
            $row = $qb
                ->select('uid', 'pid', 'doktype', 'deleted', 'hidden')
                ->from('pages')
                ->where(
                    $qb->expr()->eq('uid', $qb->createNamedParameter($folderUid, Connection::PARAM_INT)),
                    $qb->expr()->eq('deleted', 0),
                    $qb->expr()->eq('hidden', 0)
                )
                ->setMaxResults(1)
                ->executeQuery()
                ->fetchAssociative();

            if (!$row) {
                return false;
            }

            // Vérifie s'il y a au moins une sous-page navigable
            $qbSub = $this->connectionPool->getQueryBuilderForTable('pages');
            $subCount = (int)$qbSub
                ->count('uid')
                ->from('pages')
                ->where(
                    $qbSub->expr()->eq('pid', $qbSub->createNamedParameter($folderUid, Connection::PARAM_INT)),
                    $qbSub->expr()->lt('doktype', 200),
                    $qbSub->expr()->eq('nav_hide', 0),
                    $qbSub->expr()->eq('deleted', 0),
                    $qbSub->expr()->eq('hidden', 0)
                )
                ->executeQuery()
                ->fetchOne();

            return $subCount > 0;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Recherche un dossier système enfant direct de $rootPageId correspondant aux motifs donnés.
     *
     * @param string[] $patterns
     */
    private function findDirectFolderByPattern(int $rootPageId, array $patterns): int
    {
        try {
            $qb = $this->connectionPool->getQueryBuilderForTable('pages');
            $rows = $qb
                ->select('uid', 'title', 'slug')
                ->from('pages')
                ->where(
                    $qb->expr()->eq('pid', $qb->createNamedParameter($rootPageId, Connection::PARAM_INT)),
                    $qb->expr()->eq('doktype', 254),
                    $qb->expr()->eq('deleted', 0),
                    $qb->expr()->eq('hidden', 0)
                )
                ->orderBy('sorting', 'ASC')
                ->executeQuery()
                ->fetchAllAssociative();

            foreach ($rows as $row) {
                $title = mb_strtolower((string)($row['title'] ?? ''));
                $slug = mb_strtolower((string)($row['slug'] ?? ''));
                foreach ($patterns as $pattern) {
                    $pattern = mb_strtolower($pattern);
                    if (str_contains($title, $pattern) || str_contains($slug, $pattern)) {
                        return (int)$row['uid'];
                    }
                }
            }
        } catch (\Throwable) {
        }

        return 0;
    }

    /**
     * Recherche le premier dossier système enfant direct de $rootPageId contenant des pages navigables.
     */
    private function findFirstFolderWithNavigablePages(int $rootPageId): int
    {
        try {
            $qb = $this->connectionPool->getQueryBuilderForTable('pages');
            $folders = $qb
                ->select('uid')
                ->from('pages')
                ->where(
                    $qb->expr()->eq('pid', $qb->createNamedParameter($rootPageId, Connection::PARAM_INT)),
                    $qb->expr()->eq('doktype', 254),
                    $qb->expr()->eq('deleted', 0),
                    $qb->expr()->eq('hidden', 0)
                )
                ->orderBy('sorting', 'ASC')
                ->executeQuery()
                ->fetchAllAssociative();

            foreach ($folders as $folder) {
                $fUid = (int)$folder['uid'];
                $qbSub = $this->connectionPool->getQueryBuilderForTable('pages');
                $subCount = (int)$qbSub
                    ->count('uid')
                    ->from('pages')
                    ->where(
                        $qbSub->expr()->eq('pid', $qbSub->createNamedParameter($fUid, Connection::PARAM_INT)),
                        $qbSub->expr()->lt('doktype', 200),
                        $qbSub->expr()->eq('nav_hide', 0),
                        $qbSub->expr()->eq('deleted', 0),
                        $qbSub->expr()->eq('hidden', 0)
                    )
                    ->executeQuery()
                    ->fetchOne();

                if ($subCount > 0) {
                    return $fUid;
                }
            }
        } catch (\Throwable) {
        }

        return 0;
    }

    /**
     * Résout un paramètre de site avec arbitrage temporel bidirectionnel (settings.yaml vs sys_template).
     */
    private function resolveSiteSetting(?Site $site, int $rootPageId, string $key): mixed
    {
        $templateTstamp = $this->getSysTemplateTimestamp($rootPageId);
        $constantsVal = $this->getSysTemplateConstantValue($rootPageId, $key);

        $yamlValue = null;
        $yamlMtime = 0;
        if ($site instanceof Site && method_exists($site, 'getIdentifier')) {
            $settingsFile = $this->resolveSiteSettingsFilePath($site->getIdentifier());
            if (file_exists($settingsFile)) {
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

        // Arbitrage horodaté : constante TypoScript vs settings.yaml
        if ($templateTstamp > $yamlMtime && $constantsVal !== null && $constantsVal !== '') {
            return $constantsVal;
        }
        if ($yamlValue !== null && $yamlValue !== '') {
            return $yamlValue;
        }
        if ($constantsVal !== null && $constantsVal !== '') {
            return $constantsVal;
        }

        // Fallback SiteSettings TYPO3 v13/v14
        if ($site instanceof Site && method_exists($site, 'getSettings')) {
            $settings = $site->getSettings();
            if (is_object($settings) && method_exists($settings, 'get')) {
                $val = $settings->get($key);
                if ($val !== null && $val !== '') {
                    return $val;
                }
            }
        }

        return null;
    }

    /**
     * Lit la constante commune.* enregistrée dans sys_template.
     */
    private function getSysTemplateConstantValue(int $rootPageId, string $key): mixed
    {
        if ($rootPageId <= 0) {
            return null;
        }

        try {
            $qb = $this->connectionPool->getQueryBuilderForTable('sys_template');
            $row = $qb
                ->select('constants')
                ->from('sys_template')
                ->where($qb->expr()->eq('pid', $qb->createNamedParameter($rootPageId, Connection::PARAM_INT)))
                ->orderBy('root', 'DESC')
                ->addOrderBy('sorting', 'ASC')
                ->setMaxResults(1)
                ->executeQuery()
                ->fetchAssociative();

            if (!$row || empty($row['constants'])) {
                return null;
            }

            $lines = explode("\n", (string)$row['constants']);
            foreach ($lines as $line) {
                $line = trim($line);
                if (empty($line) || str_starts_with($line, '#') || str_starts_with($line, '/')) {
                    continue;
                }
                if (str_contains($line, '=')) {
                    [$k, $v] = explode('=', $line, 2);
                    $k = trim($k);
                    $v = trim($v);
                    if ($k === $key || $k === str_replace('commune.', '', $key)) {
                        return trim($v, "\"'");
                    }
                }
            }
        } catch (\Throwable) {
        }

        return null;
    }

    /**
     * Timestamp de modification de sys_template (avec cache).
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
            $qb = $this->connectionPool->getQueryBuilderForTable('sys_template');
            $row = $qb
                ->select('tstamp')
                ->from('sys_template')
                ->where($qb->expr()->eq('pid', $qb->createNamedParameter($rootPageId, Connection::PARAM_INT)))
                ->orderBy('root', 'DESC')
                ->addOrderBy('sorting', 'ASC')
                ->setMaxResults(1)
                ->executeQuery()
                ->fetchAssociative();

            $tstamp = ($row && isset($row['tstamp'])) ? (int)$row['tstamp'] : 0;
            self::$templateTstampCache[$rootPageId] = $tstamp;
            return $tstamp;
        } catch (\Throwable) {
            return 0;
        }
    }

    private function resolveSiteSettingsFilePath(string $identifier): string
    {
        $primary = Environment::getConfigPath() . '/sites/' . $identifier . '/settings.yaml';
        if (file_exists($primary)) {
            return $primary;
        }
        $legacy = GeneralUtility::getFileAbsFileName('config/sites/' . $identifier . '/settings.yaml');
        return ($legacy !== '' && file_exists($legacy)) ? $legacy : $primary;
    }

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
        return ($current !== null && $current !== '') ? $current : null;
    }

    /**
     * Rendu HTML brut de secours pour le Menu Principal (Megamenu 3 niveaux RGAA 4.1).
     *
     * @param array<int, array<string, mixed>> $items
     */
    private function renderMainMenuHtml(array $items): string
    {
        if (empty($items)) {
            return '';
        }

        $html = '<ul class="navbar-nav mb-2 mb-lg-0" role="menubar">';
        foreach ($items as $item) {
            $title = htmlspecialchars((string)($item['title'] ?? ''));
            $link = htmlspecialchars((string)($item['link'] ?? '#'));
            $isActive = !empty($item['active']);
            $isCurrent = !empty($item['current']);
            $children = $item['children'] ?? [];

            if (!empty($children)) {
                $activeClass = $isActive ? ' active' : '';
                $currAttr = $isCurrent ? ' aria-current="page"' : ($isActive ? ' aria-current="true"' : '');
                $html .= '<li class="nav-item dropdown megamenu-item' . $activeClass . '" role="none">';
                $html .= '<a href="' . $link . '" class="nav-link dropdown-toggle' . $activeClass . '" role="menuitem" aria-expanded="false" data-bs-toggle="dropdown"' . $currAttr . '>' . $title . '</a>';
                $html .= '<div class="dropdown-menu megamenu-panel shadow-lg" role="menu">';
                $html .= '<div class="container-fluid px-2 px-lg-4"><div class="row g-3 g-lg-4">';

                foreach ($children as $cat) {
                    $catTitle = htmlspecialchars((string)($cat['title'] ?? ''));
                    $catLink = htmlspecialchars((string)($cat['link'] ?? '#'));
                    $catActive = !empty($cat['active']);
                    $catCurrent = !empty($cat['current']);
                    $catActiveCls = $catActive ? ' active' : '';
                    $catCurrAttr = $catCurrent ? ' aria-current="page"' : ($catActive ? ' aria-current="true"' : '');
                    $catChildren = $cat['children'] ?? [];

                    $html .= '<div class="col-12 col-md-6 col-lg-3 megamenu-col' . $catActiveCls . '" role="none">';
                    if (!empty($catChildren)) {
                        $html .= '<div class="megamenu-category-header">';
                        $html .= '<a href="' . $catLink . '" class="megamenu-category-title' . $catActiveCls . '" role="menuitem"' . $catCurrAttr . '>' . $catTitle . '</a>';
                        $html .= '</div>';
                        $html .= '<ul class="megamenu-list list-unstyled mb-0" role="menu">';
                        foreach ($catChildren as $sub) {
                            $subTitle = htmlspecialchars((string)($sub['title'] ?? ''));
                            $subLink = htmlspecialchars((string)($sub['link'] ?? '#'));
                            $subActive = !empty($sub['active']);
                            $subCurrent = !empty($sub['current']);
                            $subActiveCls = $subActive ? ' active' : '';
                            $subCurrAttr = $subCurrent ? ' aria-current="page"' : '';
                            $html .= '<li class="megamenu-item-l3' . $subActiveCls . '" role="none">';
                            $html .= '<a href="' . $subLink . '" class="megamenu-sublink' . $subActiveCls . '" role="menuitem"' . $subCurrAttr . '>' . $subTitle . '</a>';
                            $html .= '</li>';
                        }
                        $html .= '</ul>';
                    } else {
                        $html .= '<a href="' . $catLink . '" class="megamenu-category-link' . $catActiveCls . '" role="menuitem"' . $catCurrAttr . '>' . $catTitle . '</a>';
                    }
                    $html .= '</div>';
                }

                $html .= '</div></div></div>';
                $html .= '</li>';
            } else {
                $activeClass = $isActive ? ' active' : '';
                $currAttr = $isCurrent ? ' aria-current="page"' : '';
                $html .= '<li class="nav-item' . $activeClass . '" role="none">';
                $html .= '<a href="' . $link . '" class="nav-link' . $activeClass . '" role="menuitem"' . $currAttr . '>' . $title . '</a>';
                $html .= '</li>';
            }
        }
        $html .= '</ul>';

        return $html;
    }

    /**
     * Rendu HTML brut de secours pour le Menu de Pied de Page.
     *
     * @param array<int, array<string, mixed>> $items
     */
    private function renderFooterMenuHtml(array $items): string
    {
        if (empty($items)) {
            return '';
        }

        $html = '<ul class="list-inline mb-0">';
        $count = count($items);
        $i = 0;
        foreach ($items as $item) {
            $i++;
            $title = htmlspecialchars((string)($item['title'] ?? ''));
            $link = htmlspecialchars((string)($item['link'] ?? '#'));
            $isActive = !empty($item['active']);
            $isCurrent = !empty($item['current']);
            $activeClass = $isActive ? 'text-white fw-bold' : 'text-white-50';
            $currAttr = $isCurrent ? ' aria-current="page"' : '';

            $html .= '<li class="list-inline-item">';
            $html .= '<a href="' . $link . '" class="' . $activeClass . ' text-decoration-none"' . $currAttr . '>' . $title . '</a>';
            $html .= '</li>';
            if ($i < $count) {
                $html .= '<li class="list-inline-item separator text-white-50" aria-hidden="true">·</li>';
            }
        }
        $html .= '</ul>';

        return $html;
    }

    /**
     * Rendu HTML brut de secours pour la Navigation Latérale de sous-pages.
     *
     * @param array<int, array<string, mixed>> $items
     */
    private function renderSubMenuHtml(array $items): string
    {
        if (empty($items)) {
            return '';
        }

        $html = '<ul class="list-group list-group-flush commune-sidebar-menu level-1">';
        foreach ($items as $item) {
            $title = htmlspecialchars((string)($item['title'] ?? ''));
            $link = htmlspecialchars((string)($item['link'] ?? '#'));
            $isActive = !empty($item['active']);
            $isCurrent = !empty($item['current']);
            $children = $item['children'] ?? [];
            $hasChildren = !empty($children);

            $liCls = 'list-group-item level-1-item' . ($hasChildren ? ' has-children' : '') . ($isActive ? ' active-page' : '');
            $aCls = 'sidebar-link level-1-link' . ($hasChildren ? ' is-parent' : '') . ($isActive ? ' active-link' : '');
            $currAttr = $isCurrent ? ' aria-current="page"' : ($isActive ? ' aria-current="true"' : '');

            $html .= '<li class="' . $liCls . '">';
            $html .= '<a href="' . $link . '" class="' . $aCls . '"' . $currAttr . '>';
            $html .= $hasChildren ? '<span class="sidebar-title-text">' . $title . '</span>' : $title;
            $html .= '</a>';

            if ($hasChildren && $isActive) {
                $html .= '<ul class="commune-sidebar-submenu level-2 list-unstyled mb-0">';
                foreach ($children as $l2) {
                    $l2Title = htmlspecialchars((string)($l2['title'] ?? ''));
                    $l2Link = htmlspecialchars((string)($l2['link'] ?? '#'));
                    $l2Active = !empty($l2['active']);
                    $l2Current = !empty($l2['current']);
                    $l2Children = $l2['children'] ?? [];
                    $l2HasChildren = !empty($l2Children);

                    $l2LiCls = 'sidebar-subitem level-2-item' . ($l2HasChildren ? ' has-children' : '') . ($l2Active ? ' active-page' : '');
                    $l2ACls = 'sidebar-sublink level-2-link' . ($l2HasChildren ? ' is-parent' : '') . ($l2Active ? ' active-link' : '');
                    $l2CurrAttr = $l2Current ? ' aria-current="page"' : '';

                    $html .= '<li class="' . $l2LiCls . '">';
                    $html .= '<a href="' . $l2Link . '" class="' . $l2ACls . '"' . $l2CurrAttr . '><span class="sidebar-item-text">' . $l2Title . '</span></a>';

                    if ($l2HasChildren && $l2Active) {
                        $html .= '<ul class="commune-sidebar-subsubmenu level-3 list-unstyled mb-0">';
                        foreach ($l2Children as $l3) {
                            $l3Title = htmlspecialchars((string)($l3['title'] ?? ''));
                            $l3Link = htmlspecialchars((string)($l3['link'] ?? '#'));
                            $l3Active = !empty($l3['active']);
                            $l3Current = !empty($l3['current']);
                            $l3LiCls = 'sidebar-subsubitem level-3-item' . ($l3Active ? ' active-page' : '');
                            $l3ACls = 'sidebar-subsublink level-3-link' . ($l3Active ? ' active-link' : '');
                            $l3CurrAttr = $l3Current ? ' aria-current="page"' : '';

                            $html .= '<li class="' . $l3LiCls . '">';
                            $html .= '<a href="' . $l3Link . '" class="' . $l3ACls . '"' . $l3CurrAttr . '><span class="sidebar-item-text">' . $l3Title . '</span></a>';
                            $html .= '</li>';
                        }
                        $html .= '</ul>';
                    }
                    $html .= '</li>';
                }
                $html .= '</ul>';
            }
            $html .= '</li>';
        }
        $html .= '</ul>';

        return $html;
    }
}
