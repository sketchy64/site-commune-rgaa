<?php

declare(strict_types=1);

namespace Commune\SiteCommuneRgaa\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Http\JsonResponse;
use TYPO3\CMS\Core\Http\Response;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\PathUtility;

class PwaMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $path = $request->getUri()->getPath();

        // 1. Service du Web App Manifest (/manifest.webmanifest)
        if ($path === '/manifest.webmanifest') {
            return $this->handleManifestRequest($request);
        }

        // 2. Service du Service Worker (/sw.js)
        if ($path === '/sw.js') {
            return $this->handleServiceWorkerRequest();
        }

        // 3. API d'Abonnement Push (/api/pwa/subscribe)
        if ($path === '/api/pwa/subscribe') {
            return $this->handlePushSubscriptionRequest($request);
        }

        return $handler->handle($request);
    }

    private function handleManifestRequest(ServerRequestInterface $request): ResponseInterface
    {
        $manifestPath = GeneralUtility::getFileAbsFileName('EXT:site_commune_rgaa/Resources/Public/manifest.webmanifest');
        if (!file_exists($manifestPath)) {
            return new Response('php://temp', 404);
        }

        $content = file_get_contents($manifestPath);
        $manifestData = json_decode($content, true) ?: [];

        // Remplacement dynamique du préfixe EXT: par un chemin web valide
        if (isset($manifestData['icons']) && is_array($manifestData['icons'])) {
            foreach ($manifestData['icons'] as &$icon) {
                if (isset($icon['src']) && str_starts_with($icon['src'], 'EXT:')) {
                    $absPath = GeneralUtility::getFileAbsFileName($icon['src']);
                    $webPath = PathUtility::getAbsoluteWebPath($absPath);
                    $icon['src'] = $webPath;
                }
            }
        }

        $response = new Response('php://temp', 200, [
            'Content-Type' => 'application/manifest+json; charset=utf-8',
            'Cache-Control' => 'no-cache, no-store, must-revalidate',
        ]);
        $response->getBody()->write(json_encode($manifestData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return $response;
    }

    private function handleServiceWorkerRequest(): ResponseInterface
    {
        $swPath = GeneralUtility::getFileAbsFileName('EXT:site_commune_rgaa/Resources/Public/JavaScript/sw.js');
        if (!file_exists($swPath)) {
            return new Response('php://temp', 404);
        }

        $content = file_get_contents($swPath);
        $response = new Response('php://temp', 200, [
            'Content-Type' => 'application/javascript; charset=utf-8',
            'Service-Worker-Allowed' => '/',
            'Cache-Control' => 'no-cache, no-store, must-revalidate',
        ]);
        $response->getBody()->write($content);

        return $response;
    }

    private function handlePushSubscriptionRequest(ServerRequestInterface $request): ResponseInterface
    {
        $method = $request->getMethod();
        $input = json_decode((string)$request->getBody(), true) ?: [];

        $connection = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('tx_sitecommunergaa_push_subscription');

        if ($method === 'POST') {
            $endpoint = trim((string)($input['endpoint'] ?? ''));
            $p256dh = trim((string)($input['keys']['p256dh'] ?? ''));
            $auth = trim((string)($input['keys']['auth'] ?? ''));
            $userAgent = substr((string)($request->getHeaderLine('User-Agent') ?? ''), 0, 255);

            if (empty($endpoint) || empty($p256dh) || empty($auth)) {
                return new JsonResponse(['error' => 'Invalid payload'], 400);
            }

            // Vérification si déjà inscrit
            $existing = $connection->select(
                ['uid'],
                'tx_sitecommunergaa_push_subscription',
                ['endpoint' => $endpoint]
            )->fetchOne();

            if ($existing) {
                $connection->update(
                    'tx_sitecommunergaa_push_subscription',
                    [
                        'p256dh' => $p256dh,
                        'auth' => $auth,
                        'user_agent' => $userAgent,
                        'tstamp' => time(),
                        'hidden' => 0
                    ],
                    ['uid' => (int)$existing]
                );
            } else {
                $connection->insert(
                    'tx_sitecommunergaa_push_subscription',
                    [
                        'pid' => 0,
                        'endpoint' => $endpoint,
                        'p256dh' => $p256dh,
                        'auth' => $auth,
                        'user_agent' => $userAgent,
                        'crdate' => time(),
                        'tstamp' => time(),
                        'hidden' => 0
                    ]
                );
            }

            return new JsonResponse(['success' => true, 'message' => 'Subscription saved']);
        }

        if ($method === 'DELETE') {
            $endpoint = trim((string)($input['endpoint'] ?? ''));
            if (!empty($endpoint)) {
                $connection->delete('tx_sitecommunergaa_push_subscription', ['endpoint' => $endpoint]);
            }
            return new JsonResponse(['success' => true, 'message' => 'Subscription deleted']);
        }

        return new JsonResponse(['error' => 'Method not allowed'], 405);
    }
}
