<?php

declare(strict_types=1);

namespace Commune\SiteCommuneRgaa\Service;

use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Http\RequestFactory;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Utility\GeneralUtility;

class WebPushService
{
    // Clefs VAPID par défaut conforme P-256 (NIST P-256 / prime256v1)
    private const DEFAULT_PUBLIC_KEY = 'BLQcfzIu2XgG6fT4vVsRY6BYy1LgVkyKH8XYYJajIUts74MKtdOlZOZt2ZCs62LmUIUnaunEZPevfxIxIHzn_iY';
    private const DEFAULT_PRIVATE_KEY = 'ftRVnQjBwQBjx2AyBOUS4xIqqFQzMOxEGtWdvITg-y8';
    private const DEFAULT_SUBJECT = 'mailto:contact@notre-commune.fr';

    private readonly RequestFactory $requestFactory;
    private readonly ConnectionPool $connectionPool;
    private readonly SiteFinder $siteFinder;

    public function __construct(
        ?RequestFactory $requestFactory = null,
        ?ConnectionPool $connectionPool = null,
        ?SiteFinder $siteFinder = null
    ) {
        $this->requestFactory = $requestFactory ?? GeneralUtility::makeInstance(RequestFactory::class);
        $this->connectionPool = $connectionPool ?? GeneralUtility::makeInstance(ConnectionPool::class);
        $this->siteFinder = $siteFinder ?? GeneralUtility::makeInstance(SiteFinder::class);
    }

    /**
     * Helper sécurisé pour la lecture d'un paramètre de site (Compatible TYPO3 v12 et v13 SiteSettings)
     */
    private function getSiteSetting(string $key): mixed
    {
        try {
            $site = current($this->siteFinder->getAllSites());
            if ($site && method_exists($site, 'getSettings')) {
                $settings = $site->getSettings();
                if (is_object($settings) && method_exists($settings, 'get')) {
                    $val = $settings->get($key);
                    if ($val !== null && $val !== '') {
                        return $val;
                    }
                    if (str_contains($key, '.')) {
                        $parts = explode('.', $key, 2);
                        $section = $settings->get($parts[0]);
                        if (is_array($section) && isset($section[$parts[1]])) {
                            return $section[$parts[1]];
                        }
                    }
                } elseif (is_array($settings)) {
                    $parts = explode('.', $key);
                    $curr = $settings;
                    foreach ($parts as $part) {
                        if (is_array($curr) && isset($curr[$part])) {
                            $curr = $curr[$part];
                        } else {
                            $curr = null;
                            break;
                        }
                    }
                    if ($curr !== null) {
                        return $curr;
                    }
                }
            }
        } catch (\Throwable $e) {
        }
        return null;
    }

    /**
     * Récupère la clef VAPID publique configurée dans le site ou la clef par défaut
     */
    public function getVapidPublicKey(): string
    {
        $val = (string)($this->getSiteSetting('commune.pwa_vapid_public_key') ?? '');
        return (!empty($val) && strlen($val) > 20) ? $val : self::DEFAULT_PUBLIC_KEY;
    }

    /**
     * Récupère la clef VAPID privée configurée dans le site ou la clef par défaut
     */
    public function getVapidPrivateKey(): string
    {
        $val = (string)($this->getSiteSetting('commune.pwa_vapid_private_key') ?? '');
        return (!empty($val) && strlen($val) > 10) ? $val : self::DEFAULT_PRIVATE_KEY;
    }

    /**
     * Récupère le sujet de contact VAPID (mailto:...)
     */
    public function getVapidSubject(): string
    {
        $val = (string)($this->getSiteSetting('commune.pwa_vapid_email') ?? '');
        if (!empty($val)) {
            return str_starts_with($val, 'mailto:') ? $val : 'mailto:' . $val;
        }
        return self::DEFAULT_SUBJECT;
    }

    /**
     * Envoie une notification push à tous les abonnés enregistrés
     */
    public function notifyAllSubscribers(string $title, string $body, string $url = '/', string $icon = '/_assets/site_commune_rgaa/Icons/pwa-192x192.png'): int
    {
        $connection = $this->connectionPool->getConnectionForTable('tx_sitecommunergaa_push_subscription');
        $subscriptions = $connection->select(
            ['*'],
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

        // Utilisation de la librairie Minishlink\WebPush si disponible
        if (class_exists(WebPush::class)) {
            $auth = [
                'VAPID' => [
                    'subject' => $this->getVapidSubject(),
                    'publicKey' => $this->getVapidPublicKey(),
                    'privateKey' => $this->getVapidPrivateKey(),
                ],
            ];

            try {
                $webPush = new WebPush($auth);
                foreach ($subscriptions as $sub) {
                    $endpoint = $sub['endpoint'] ?? '';
                    $p256dh = $sub['p256dh'] ?? '';
                    $authToken = $sub['auth'] ?? '';

                    if (empty($endpoint) || empty($p256dh) || empty($authToken)) {
                        continue;
                    }

                    $subscription = Subscription::create([
                        'endpoint' => $endpoint,
                        'publicKey' => $p256dh,
                        'authToken' => $authToken,
                        'contentEncoding' => 'aes128gcm',
                        'keys' => [
                            'p256dh' => $p256dh,
                            'auth' => $authToken,
                        ]
                    ]);

                    $report = $webPush->sendOneNotification($subscription, $payload);
                    if ($report->isSuccess()) {
                        $sentCount++;
                    } else {
                        // Masquer uniquement si l'abonnement est expiré ou révoqué (404/410)
                        if ($report->isSubscriptionExpired()) {
                            $connection->update(
                                'tx_sitecommunergaa_push_subscription',
                                ['hidden' => 1],
                                ['uid' => (int)$sub['uid']]
                            );
                        }
                    }
                }
                return $sentCount;
            } catch (\Throwable $e) {
                // Secours en cas d'erreur minishlink
            }
        }

        // Mode Secours : Envoi natif VAPID JWT + POST (si minishlink n'est pas encore chargé)
        foreach ($subscriptions as $sub) {
            $endpoint = $sub['endpoint'] ?? '';
            if (empty($endpoint)) {
                continue;
            }

            $resultStatus = $this->sendNativeVapidNotification($endpoint, $payload, $sub['p256dh'] ?? '', $sub['auth'] ?? '');
            if ($resultStatus >= 200 && $resultStatus < 300) {
                $sentCount++;
            } elseif ($resultStatus === 404 || $resultStatus === 410) {
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
     * Envoie une notification via VAPID JWT natif (Secours quand Minishlink n'est pas disponible)
     */
    private function sendNativeVapidNotification(string $endpoint, string $payload, string $p256dh, string $auth): int
    {
        try {
            $parsedUrl = parse_url($endpoint);
            $origin = ($parsedUrl['scheme'] ?? 'https') . '://' . ($parsedUrl['host'] ?? '');

            $headers = [
                'TTL' => '86400',
                'Urgency' => 'high',
                'Content-Type' => 'application/json',
            ];

            // VAPID JWT Header
            $jwt = $this->createVapidJwt($origin);
            $headers['Authorization'] = 'vapid t=' . $jwt . ', k=' . $this->getVapidPublicKey();

            $options = [
                'headers' => $headers,
                'body' => $payload,
                'timeout' => 5.0,
                'http_errors' => false
            ];

            $response = $this->requestFactory->request($endpoint, 'POST', $options);
            return $response->getStatusCode();
        } catch (\Throwable $e) {
            return 500;
        }
    }

    /**
     * Génération d'un Token VAPID JWT (RFC 8292)
     */
    private function createVapidJwt(string $origin): string
    {
        $header = ['typ' => 'JWT', 'alg' => 'ES256'];
        $claims = [
            'aud' => $origin,
            'exp' => time() + 43200, // 12h
            'sub' => $this->getVapidSubject()
        ];

        $encodedHeader = $this->base64UrlEncode(json_encode($header));
        $encodedClaims = $this->base64UrlEncode(json_encode($claims));
        $dataToSign = $encodedHeader . '.' . $encodedClaims;

        $signature = $this->signEcP256($dataToSign, $this->getVapidPrivateKey());
        return $dataToSign . '.' . $signature;
    }

    /**
     * Signature ECDSA P-256 (SHA256)
     */
    private function signEcP256(string $data, string $privateKeyBase64): string
    {
        try {
            $privKeyBytes = $this->base64UrlDecode($privateKeyBase64);
            $pem = "-----BEGIN PRIVATE KEY-----\n" .
                chunk_split(base64_encode("\x30\x41\x02\x01\x00\x30\x13\x06\x07\x2a\x86\x48\xce\x3d\x02\x01\x06\x08\x2a\x86\x48\xce\x3d\x03\x01\x07\x04\x27\x30\x25\x02\x01\x01\x04\x20" . $privKeyBytes), 64, "\n") .
                "-----END PRIVATE KEY-----";

            $key = openssl_pkey_get_private($pem);
            if ($key && openssl_sign($data, $rawSig, $key, OPENSSL_ALGO_SHA256)) {
                return $this->base64UrlEncode($this->convertDerToRsa($rawSig));
            }
        } catch (\Throwable $e) {
        }
        return '';
    }

    private function convertDerToRsa(string $der): string
    {
        // Extraction R et S du format DER pour ECDSA ES256 (64 bytes)
        $offset = 2;
        $rLen = ord($der[$offset + 1]);
        $r = substr($der, $offset + 2, $rLen);
        $sOffset = $offset + 2 + $rLen;
        $sLen = ord($der[$sOffset + 1]);
        $s = substr($der, $sOffset + 2, $sLen);

        $r = ltrim($r, "\x00");
        $s = ltrim($s, "\x00");

        return str_pad($r, 32, "\x00", STR_PAD_LEFT) . str_pad($s, 32, "\x00", STR_PAD_LEFT);
    }

    private function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private function base64UrlDecode(string $data): string
    {
        return base64_decode(strtr($data, '-_', '+/') . str_repeat('=', (4 - strlen($data) % 4) % 4));
    }
}
