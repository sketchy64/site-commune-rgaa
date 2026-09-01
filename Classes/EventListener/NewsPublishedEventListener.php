<?php

declare(strict_types=1);

namespace Commune\SiteCommuneRgaa\EventListener;

use Commune\SiteCommuneRgaa\Service\WebPushService;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\DataHandling\Event\AfterDatabaseOperationsEvent;
use TYPO3\CMS\Core\DataHandling\Event\AfterRecordPublicationEvent;

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

        // 1. Support de l'évènement natif EXT:news (GeorgRinger\News\Event\NewsPostPersistEvent)
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

                $pathSegment = method_exists($news, 'getPathSegment') ? trim((string)$news->getPathSegment()) : '';
                if (!empty($pathSegment)) {
                    $detailUrl = '/news/' . ltrim($pathSegment, '/');
                } elseif ($uid > 0) {
                    $detailUrl = '/?tx_news_pi1%5Bnews%5D=' . $uid . '&tx_news_pi1%5Bcontroller%5D=News&tx_news_pi1%5Baction%5D=detail';
                } else {
                    $detailUrl = '/';
                }

                if ($uid > 0) {
                    self::$processedUids[$uid] = true;
                }

                $this->webPushService->notifyAllSubscribers(
                    'Mairie : ' . $title,
                    $teaser,
                    $detailUrl
                );
            }
            return;
        }

        // 2. Support via DataHandler Events TYPO3 v13/v14 (AfterDatabaseOperationsEvent / AfterRecordPublicationEvent)
        if ($event instanceof AfterDatabaseOperationsEvent || $event instanceof AfterRecordPublicationEvent) {
            /** @var mixed $event */
            $table = method_exists($event, 'getTable') ? $event->getTable() : '';
            if ($table === 'tx_news_domain_model_news') {
                $rawId = method_exists($event, 'getRecordId') ? $event->getRecordId() : 0;
                $recordId = is_numeric($rawId) ? (int)$rawId : 0;

                // Si le recordId est un placeholder 'NEW66c...', résolution de l'UID réel créé par DataHandler
                if ($recordId <= 0 && method_exists($event, 'getDataHandler')) {
                    $dh = $event->getDataHandler();
                    if ($dh && isset($dh->substNEWwithIDs[$rawId])) {
                        $recordId = (int)$dh->substNEWwithIDs[$rawId];
                    }
                }

                // Secours universel si l'ID est toujours nul (ex: NEW...) : Récupérer le dernier UID inséré
                if ($recordId <= 0) {
                    $connTemp = $this->connectionPool->getConnectionForTable('tx_news_domain_model_news');
                    $latestUid = (int)$connTemp->executeQuery('SELECT uid FROM tx_news_domain_model_news ORDER BY uid DESC LIMIT 1')->fetchOne();
                    if ($latestUid > 0) {
                        $recordId = $latestUid;
                    }
                }

                if ($recordId <= 0 || isset(self::$processedUids[$recordId])) {
                    return;
                }

                $fieldArray = method_exists($event, 'getFieldArray') ? (array)$event->getFieldArray() : [];

                // Récupération de l'enregistrement complet en base de données
                $connection = $this->connectionPool->getConnectionForTable('tx_news_domain_model_news');
                $fullRecord = $connection->select(
                    ['*'],
                    'tx_news_domain_model_news',
                    ['uid' => $recordId]
                )->fetchAssociative();

                if (!$fullRecord) {
                    return;
                }

                // Fusion des modifications récentes avec l'enregistrement complet
                $record = array_merge($fullRecord, $fieldArray);

                $hidden = (int)($record['hidden'] ?? 0);
                $deleted = (int)($record['deleted'] ?? 0);
                $starttime = (int)($record['starttime'] ?? 0);
                $endtime = (int)($record['endtime'] ?? 0);
                $now = time();

                // Vérification de la publication effective (visible, non supprimée, dates valides avec tolérance 60s)
                if ($hidden === 0 && $deleted === 0 && ($starttime === 0 || $starttime <= ($now + 60)) && ($endtime === 0 || $endtime > $now)) {
                    self::$processedUids[$recordId] = true;

                    $title = trim((string)($record['title'] ?? 'Nouvelle actualité'));
                    $teaser = trim((string)($record['teaser'] ?? ''));

                    if (empty($teaser) && !empty($record['bodytext'])) {
                        $teaser = strip_tags((string)$record['bodytext']);
                    }
                    $teaser = mb_substr(trim($teaser), 0, 140);
                    if (empty($teaser)) {
                        $teaser = 'Une nouvelle actualité a été publiée sur le site de la Mairie.';
                    }

                    $pathSegment = trim((string)($record['path_segment'] ?? ''));
                    if (!empty($pathSegment)) {
                        $detailUrl = '/news/' . ltrim($pathSegment, '/');
                    } else {
                        $detailUrl = '/?tx_news_pi1%5Bnews%5D=' . $recordId . '&tx_news_pi1%5Bcontroller%5D=News&tx_news_pi1%5Baction%5D=detail';
                    }

                    $this->webPushService->notifyAllSubscribers(
                        'Mairie : ' . $title,
                        $teaser,
                        $detailUrl
                    );
                }
            }
        }
    }
}
