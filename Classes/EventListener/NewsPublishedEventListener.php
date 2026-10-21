<?php

declare(strict_types=1);

namespace Commune\SiteCommuneRgaa\EventListener;

use Commune\SiteCommuneRgaa\Service\WebPushService;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Log\LogManager;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Utility\GeneralUtility;

class NewsPublishedEventListener
{
    private static array $processedUids = [];

    public function __construct(
        private readonly WebPushService $webPushService,
        private readonly ConnectionPool $connectionPool
    ) {}

    /**
     * Intercepte la sauvegarde et la publication d'une actualité (EXT:news tx_news_domain_model_news)
     */
    public function __invoke(object $event): void
    {
        if (!$this->webPushService->isPushEnabled()) {
            return;
        }

        // Support de l'évènement natif EXT:news (GeorgRinger\News\Event\NewsPostPersistEvent)
        if (get_class($event) === 'GeorgRinger\News\Event\NewsPostPersistEvent') {
            /** @var mixed $event */
            $news = method_exists($event, 'getNews') ? $event->getNews() : null;
            if ($news) {
                $uid = method_exists($news, 'getUid') ? (int)$news->getUid() : 0;
                if ($uid > 0 && isset(self::$processedUids[$uid])) {
                    return;
                }

                $isHidden = method_exists($news, 'getHidden') ? (bool)$news->getHidden() : false;
                if ($isHidden) {
                    return;
                }

                $title = method_exists($news, 'getTitle') ? (string)$news->getTitle() : 'Nouvelle actualité';
                $teaser = method_exists($news, 'getTeaser') ? (string)$news->getTeaser() : '';
                if (empty($teaser) && method_exists($news, 'getBodytext')) {
                    $teaser = strip_tags((string)$news->getBodytext());
                }
                $teaser = mb_substr(trim($teaser), 0, 140);
                if (empty($teaser)) {
                    $teaser = 'Une nouvelle actualité a été publiée sur le site de la Mairie.';
                }

                $detailPage = method_exists($news, 'getDetailPage') ? (int)$news->getDetailPage() : 0;
                $pid = method_exists($news, 'getPid') ? (int)$news->getPid() : 0;

                $detailUrl = $this->buildNewsDetailUrl([
                    'uid' => $uid,
                    'detail_page' => $detailPage,
                    'pid' => $pid,
                ]);

                if ($uid > 0) {
                    self::$processedUids[$uid] = true;
                }

                $this->webPushService->notifyAllSubscribers(
                    'Mairie : ' . $title,
                    $teaser,
                    $detailUrl
                );
            }
        }
    }

    /**
     * Construit dynamiquement l'URL de détail d'une actualité en interrogeant le SiteRouter TYPO3.
     * Cette méthode récupère le slug de la page Frontend à jour, quel que soit son nom ou son niveau dans l'arborescence.
     */
    private function buildNewsDetailUrl(array $record): string
    {
        $recordId = (int)($record['uid'] ?? 0);
        if ($recordId <= 0) {
            return '/';
        }

        // 1. Déterminer la véritable page Frontend (doktype = 1, non-SysFolder)
        $targetPageUid = $this->resolveNewsFrontendPageUid($record);

        // 2. Génération dynamique via le SiteRouter TYPO3 Core
        try {
            $siteFinder = GeneralUtility::makeInstance(SiteFinder::class);
            $site = null;

            if ($targetPageUid > 0) {
                try {
                    $site = $siteFinder->getSiteByPageId($targetPageUid);
                } catch (\Throwable) {
                    // Fallback sur le site racine si la page n'est pas résolue directement
                }
            }
            if (!$site) {
                $site = $siteFinder->getSiteByPageId(1);
            }

            $router = $site->getRouter();
            $queryParams = [
                'tx_news_pi1' => [
                    'controller' => 'News',
                    'action' => 'detail',
                    'news' => $recordId,
                ],
            ];

            $uri = $router->generateUri($targetPageUid, $queryParams);
            return (string)$uri;
        } catch (\Throwable $e) {
            $logger = GeneralUtility::makeInstance(LogManager::class)->getLogger(__CLASS__);
            $logger->warning('NewsPublishedEventListener : Erreur lors de la génération d\'URL via SiteRouter', ['exception' => $e->getMessage()]);
        }

        // 3. Secours si le routeur échoue
        return '/index.php?id=' . $targetPageUid . '&tx_news_pi1%5Baction%5D=detail&tx_news_pi1%5Bcontroller%5D=News&tx_news_pi1%5Bnews%5D=' . $recordId;
    }

    /**
     * Identifie l'UID de la vraie page Frontend (doktype = 1, excluant les SysFolders 254).
     */
    private function resolveNewsFrontendPageUid(array $record): int
    {
        // Si la page de détail est explicitement spécifiée dans l'enregistrement de l'actualité
        if (!empty($record['detail_page']) && (int)$record['detail_page'] > 0) {
            return (int)$record['detail_page'];
        }

        $connPages = $this->connectionPool->getConnectionForTable('pages');

        // Rechercher dans l'arborescence une page Frontend valide (doktype = 1) avec slug contenant 'actualit'
        $queryBuilder = $connPages->createQueryBuilder();
        $frontendPageUid = (int)$queryBuilder
            ->select('uid')
            ->from('pages')
            ->where($queryBuilder->expr()->eq('deleted', 0))
            ->andWhere($queryBuilder->expr()->eq('hidden', 0))
            ->andWhere($queryBuilder->expr()->eq('doktype', 1))
            ->andWhere($queryBuilder->expr()->like('slug', $queryBuilder->createNamedParameter('%actualit%')))
            ->orderBy('uid', 'ASC')
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchOne();

        if ($frontendPageUid > 0) {
            return $frontendPageUid;
        }

        // Secours si pas de slug 'actualit' : chercher la première sous-page Frontend valide (non-SysFolder)
        $fallbackPageUid = (int)$connPages->createQueryBuilder()
            ->select('uid')
            ->from('pages')
            ->where('deleted = 0 AND hidden = 0 AND doktype = 1 AND is_siteroot = 0')
            ->orderBy('uid', 'ASC')
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchOne();

        return $fallbackPageUid > 0 ? $fallbackPageUid : 1;
    }
}
