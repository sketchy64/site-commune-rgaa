<?php

declare(strict_types=1);

namespace Commune\SiteCommuneRgaa\EventListener;

use Commune\SiteCommuneRgaa\Service\WebPushService;
use TYPO3\CMS\Core\DataHandling\Event\AfterDatabaseOperationsEvent;
use TYPO3\CMS\Core\Utility\GeneralUtility;

class NewsPublishedEventListener
{
    public function __construct(
        private readonly WebPushService $webPushService
    ) {}

    /**
     * Intercepte la sauvegarde d'une actualité (EXT:news tx_news_domain_model_news)
     */
    public function __invoke(object $event): void
    {
        // 1. Support de l'évènement natif EXT:news si l'objet GeorgRinger\News\Event\NewsPostPersistEvent est transmis
        if (get_class($event) === 'GeorgRinger\News\Event\NewsPostPersistEvent') {
            /** @var mixed $event */
            $news = method_exists($event, 'getNews') ? $event->getNews() : null;
            if ($news) {
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

        // 2. Support via AfterDatabaseOperationsEvent du DataHandler TYPO3 (si mise à jour/création directe dans la table tx_news_domain_model_news)
        if ($event instanceof AfterDatabaseOperationsEvent) {
            $table = $event->getTable();
            if ($table === 'tx_news_domain_model_news') {
                $operation = $event->getOperation();
                $recordId = $event->getRecordId();
                $fieldArray = $event->getFieldArray();

                // Notification uniquement à la création ou lors de la publication (hidden = 0)
                $isHidden = (int)($fieldArray['hidden'] ?? 0);
                if ($isHidden === 0 && ($operation === 'insert' || isset($fieldArray['title']))) {
                    $title = (string)($fieldArray['title'] ?? 'Nouvelle actualité');
                    $teaser = (string)($fieldArray['teaser'] ?? '');
                    if (empty($teaser) && !empty($fieldArray['bodytext'])) {
                        $teaser = strip_tags((string)$fieldArray['bodytext']);
                    }
                    $teaser = mb_substr(trim($teaser), 0, 140);
                    if (empty($teaser)) {
                        $teaser = 'Une nouvelle actualité a été publiée sur le site de la Mairie.';
                    }

                    $detailUrl = is_numeric($recordId) ? '/news/detail/' . $recordId : '/';

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
