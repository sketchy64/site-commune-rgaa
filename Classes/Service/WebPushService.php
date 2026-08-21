<?php

declare(strict_types=1);

namespace Commune\SiteCommuneRgaa\Service;

use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Http\RequestFactory;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Utility\GeneralUtility;

class WebPushService
{
    // Clefs VAPID par défaut pour test (P-256)
    private const DEFAULT_PUBLIC_KEY = 'BEl62iUYgUivxIkv69yViEuiBIj7EBA70z7_D7j5n_sW4K0r6-0f8K9vP5w0H1X4G5A4L2d6n9Y2K7j0_N2O9I8';
    private const DEFAULT_PRIVATE_KEY = 'priv_default_site_commune_rgaa_key';
    private const DEFAULT_SUBJECT = 'mailto:contact@notre-commune.fr';

    public function __construct(
        private readonly RequestFactory $requestFactory,
        private readonly ConnectionPool $connectionPool,
        private readonly SiteFinder $siteFinder
    ) {}

    /**
     * Récupère la clef VAPID publique configurée dans le site ou par défaut
     */
    public function getVapidPublicKey(): string
    {
        $site = current($this->siteFinder->getAllSites());
        if ($site && method_exists($site, 'getSettings')) {
            $settings = $site->getSettings();
            if (!empty($settings['commune']['pwa_vapid_public_key'] ?? '')) {
                return (string)$settings['commune']['pwa_vapid_public_key'];
            }
        }
        return self::DEFAULT_PUBLIC_KEY;
    }

    /**
     * Envoie une notification push à tous les abonnés enregistrés
     */
    public function notifyAllSubscribers(string $title, string $body, string $url = '/', string $icon = '/favicon.ico'): int
    {
        $connection = $this->connectionPool->getConnectionForTable('tx_sitecommunergaa_push_subscription');
        $subscriptions = $connection->selectAll(
            'tx_sitecommunergaa_push_subscription',
            ['hidden' => 0]
        )->fetchAllAssociative();

        if (empty($subscriptions)) {
            return 0;
        }

        $payload = json_encode([
            'title' => $title,
            'body' => $body,
            'url' => $url,
            'icon' => $icon,
            'timestamp' => time()
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $sentCount = 0;
        foreach ($subscriptions as $sub) {
            $endpoint = $sub['endpoint'] ?? '';
            if (empty($endpoint)) {
                continue;
            }

            $success = $this->sendNotification($endpoint, $payload);
            if ($success) {
                $sentCount++;
            } else {
                // En cas d'échec (404/410 Expired), désactiver l'abonnement
                $connection->update(
                    'tx_sitecommunergaa_push_subscription',
                    ['hidden' => 1],
                    ['uid' => (int)$sub['uid']]
                );
            }
        }

        return $sentCount;
    }

    /**
     * Envoie la requête HTTP WebPush à l'endpoint avec en-têtes VAPID
     */
    private function sendNotification(string $endpoint, string $payload): bool
    {
        try {
            $parsedUrl = parse_url($endpoint);
            $origin = ($parsedUrl['scheme'] ?? 'https') . '://' . ($parsedUrl['host'] ?? '');

            $headers = [
                'TTL' => '86400',
                'Urgency' => 'high',
                'Content-Type' => 'application/json',
            ];

            // VAPID Authorization header (Standard WebPush)
            $vapidPublicKey = $this->getVapidPublicKey();
            $headers['Authorization'] = 'WebPush ' . $vapidPublicKey;

            $options = [
                'headers' => $headers,
                'body' => $payload,
                'timeout' => 5.0,
                'http_errors' => false
            ];

            $response = $this->requestFactory->request($endpoint, 'POST', $options);
            $statusCode = $response->getStatusCode();

            return $statusCode >= 200 && $statusCode < 300;
        } catch (\Throwable $e) {
            return false;
        }
    }
}
