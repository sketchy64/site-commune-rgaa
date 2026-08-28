<?php

declare(strict_types=1);

namespace Commune\SiteCommuneRgaa\Hook;

use Commune\SiteCommuneRgaa\Service\WebPushService;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Utility\GeneralUtility;

class DataHandlerNewsHook
{
    private static array $processedUids = [];

    /**
     * Intercepte la sauvegarde et la publication d'une actualité via le DataHandler TYPO3 (Compatibilité v12 & v13 Backend)
     */
    public function processDatamap_afterDatabaseOperations(
        string $status,
        string $table,
        mixed $id,
        array $fieldArray,
        DataHandler $dataHandler
    ): void {
        if ($table !== 'tx_news_domain_model_news') {
            return;
        }

        $recordId = is_numeric($id) ? (int)$id : (int)($dataHandler->substNEWwithIDs[$id] ?? 0);
        if ($recordId <= 0 || isset(self::$processedUids[$recordId])) {
            return;
        }

        $webPushService = GeneralUtility::makeInstance(WebPushService::class);
        if (!$webPushService->isPushEnabled()) {
            return;
        }

        $connectionPool = GeneralUtility::makeInstance(ConnectionPool::class);
        $connection = $connectionPool->getConnectionForTable('tx_news_domain_model_news');
        $fullRecord = $connection->select(
            ['*'],
            'tx_news_domain_model_news',
            ['uid' => $recordId]
        )->fetchAssociative();

        if (!$fullRecord) {
            return;
        }

        $record = array_merge($fullRecord, $fieldArray);

        $hidden = (int)($record['hidden'] ?? 0);
        $deleted = (int)($record['deleted'] ?? 0);
        $starttime = (int)($record['starttime'] ?? 0);
        $endtime = (int)($record['endtime'] ?? 0);
        $now = time();

        // Publication effective (visible, non supprimée, dates de publication valides)
        if ($hidden === 0 && $deleted === 0 && ($starttime === 0 || $starttime <= $now) && ($endtime === 0 || $endtime > $now)) {
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
            $detailUrl = !empty($pathSegment) ? '/news/' . $pathSegment : '/news/detail/' . $recordId;

            $webPushService->notifyAllSubscribers(
                'Mairie : ' . $title,
                $teaser,
                $detailUrl
            );
        }
    }
}
