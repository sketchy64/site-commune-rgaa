<?php

declare(strict_types=1);

namespace Commune\SiteCommuneRgaa\Hook;

use Commune\SiteCommuneRgaa\Service\WebPushService;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Log\LogManager;
use TYPO3\CMS\Core\Utility\GeneralUtility;

class DataHandlerNewsHook
{
    private static array $processedUids = [];

    /**
     * Intercepte la sauvegarde (création / édition) d'une actualité via le DataHandler TYPO3
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
        if ($recordId <= 0) {
            $connTemp = GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable('tx_news_domain_model_news');
            $latestUid = (int)$connTemp->executeQuery('SELECT uid FROM tx_news_domain_model_news ORDER BY uid DESC LIMIT 1')->fetchOne();
            if ($latestUid > 0) {
                $recordId = $latestUid;
            }
        }

        $this->processPublication($status, $table, $recordId, $fieldArray);
    }

    /**
     * Intercepte les commandes du DataHandler TYPO3 (ex: masquer / démasquer via les icônes de liste)
     */
    public function processCmdmap_postProcess(
        string $command,
        string $table,
        mixed $id,
        mixed $value,
        DataHandler $dataHandler
    ): void {
        if ($table !== 'tx_news_domain_model_news') {
            return;
        }

        $recordId = is_numeric($id) ? (int)$id : 0;
        if ($recordId > 0) {
            $this->processPublication('cmdmap:' . $command, $table, $recordId, []);
        }
    }

    private function processPublication(string $status, string $table, int $recordId, array $fieldArray): void
    {
        $logger = GeneralUtility::makeInstance(LogManager::class)->getLogger(__CLASS__);
        $logger->info('DataHandlerNewsHook déclenché pour tx_news_domain_model_news', ['status' => $status, 'recordId' => $recordId]);

        if ($recordId <= 0 || isset(self::$processedUids[$recordId])) {
            return;
        }

        $webPushService = GeneralUtility::makeInstance(WebPushService::class);
        if (!$webPushService->isPushEnabled()) {
            $logger->info('DataHandlerNewsHook : push désactivé globalement.');
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
            $logger->warning('DataHandlerNewsHook : Enregistrement introuvable en BDD', ['recordId' => $recordId]);
            return;
        }

        $record = array_merge($fullRecord, $fieldArray);

        $hidden = (int)($record['hidden'] ?? 0);
        $deleted = (int)($record['deleted'] ?? 0);
        $starttime = (int)($record['starttime'] ?? 0);
        $endtime = (int)($record['endtime'] ?? 0);
        $now = time();

        // Publication effective (visible, non supprimée, dates de publication valides avec tolérance 60s)
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
            $detailUrl = !empty($pathSegment) ? '/news/' . $pathSegment : '/news/detail/' . $recordId;

            $logger->info('DataHandlerNewsHook : Déclenchement de l\'envoi push pour l\'actualité', ['title' => $title, 'recordId' => $recordId]);

            $sent = $webPushService->notifyAllSubscribers(
                'Mairie : ' . $title,
                $teaser,
                $detailUrl
            );

            $logger->info('DataHandlerNewsHook : Résultat envoi push', ['sent' => $sent]);
        }
    }
}
