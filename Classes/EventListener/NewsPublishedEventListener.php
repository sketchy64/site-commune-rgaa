<?php

declare(strict_types=1);

namespace Commune\SiteCommuneRgaa\EventListener;

use Commune\SiteCommuneRgaa\Service\WebPushService;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\DataHandling\Event\AfterDatabaseOperationsEvent;
use TYPO3\CMS\Core\Utility\GeneralUtility;

class NewsPublishedEventListener
{
    public function __construct(
        private readonly WebPushService $webPushService,
        private readonly ConnectionPool $connectionPool
    ) {}

    /**
     * Intercepte la sauvegarde et la publication d'une actualité (EXT:news tx_news_domain_model_news)
     */
    public function __invoke(object $event): void
    {
        // 1. Support de l'évènement natif EXT:news (GeorgRinger\News\Event\NewsPostPersistEvent)
        if (get_class($event) === 'GeorgRinger\News\Event\NewsPostPersistEvent') {
            /** @var mixed $event */
            $news = method_exists($event, 'getNews') ? $event->getNews() : null;
            if ($news) {
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

                $uid = method_exists($news, 'getUid') ? (int)$news->getUid() : 0;
                $detailUrl = $uid > 0 ? '/news/detail/' . $uid : '/';

                $this->webPushService->notifyAllSubscribers(
                    'Mairie : ' . $title,
                    $teaser,
                    $detailUrl
                );
            }
            return;
        }

        // 2. Support via AfterDatabaseOperationsEvent du DataHandler TYPO3 (Backend Form / List toggle)
        if ($event instanceof AfterDatabaseOperationsEvent) {
            $table = $event->getTable();
            if ($table === 'tx_news_domain_model_news') {
                $recordId = (int)$event->getRecordId();
                if ($recordId <= 0) {
                    return;
                }

                $fieldArray = $event->getFieldArray();

                // Récupération de l'enregistrement complet en base de données pour garantie d'exhaustivité
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

                // Vérification de la publication effective (visible, non supprimée, dates valides)
                if ($hidden === 0 && $deleted === 0 && ($starttime === 0 || $starttime <= $now) && ($endtime === 0 || $endtime > $now)) {
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
                    $detailUrl = !empty($pathSegment) ? '/news/' . $pathSegment : '/news/detail/' . $recordId;

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
