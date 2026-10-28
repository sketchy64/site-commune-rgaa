<?php

declare(strict_types=1);

namespace Commune\SiteCommuneRgaa\Controller;

use Commune\SiteCommuneRgaa\Innogam\CollectiviteSettingsMapper;
use Commune\SiteCommuneRgaa\Innogam\Dto\CollectiviteSummary;
use Commune\SiteCommuneRgaa\Innogam\InnogamApiClient;
use Commune\SiteCommuneRgaa\Service\SiteManagementService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Http\JsonResponse;

/**
 * Contrôleur AJAX Backend pour la recherche et la récupération des fiches collectivités Innogam.
 */
class InnogamAjaxController
{
    public function __construct(
        private readonly InnogamApiClient $innogamApiClient,
        private readonly CollectiviteSettingsMapper $collectiviteSettingsMapper,
        private readonly SiteManagementService $siteManagementService
    ) {
    }

    /**
     * Recherche instantanée de collectivités (communes, intercos, syndicats) avec auto-complétion.
     */
    public function searchAction(ServerRequestInterface $request): ResponseInterface
    {
        $queryParams = $request->getQueryParams();
        $parsedBody = is_array($request->getParsedBody()) ? $request->getParsedBody() : [];
        $extbaseArgs = $queryParams['tx_sitecommunergaa_site_communergaa'] ?? $parsedBody['tx_sitecommunergaa_site_communergaa'] ?? [];

        $query = trim((string)(
            $queryParams['q']
            ?? $parsedBody['q']
            ?? (is_array($extbaseArgs) ? ($extbaseArgs['q'] ?? '') : '')
        ));

        if (mb_strlen($query) < 2) {
            return new JsonResponse([
                'success' => true,
                'results' => [],
                'count' => 0,
            ]);
        }

        try {
            $summaries = $this->innogamApiClient->search($query, 12);
            $results = array_map(static fn(CollectiviteSummary $c) => $c->toArray(), $summaries);

            return new JsonResponse([
                'success' => true,
                'results' => $results,
                'count' => count($results),
            ]);
        } catch (\Throwable $e) {
            return new JsonResponse([
                'success' => false,
                'error' => $e->getMessage(),
                'results' => [],
            ], 500);
        }
    }

    /**
     * Récupère la fiche détaillée d'une collectivité et ses correspondances avec les paramètres TYPO3.
     */
    public function detailAction(ServerRequestInterface $request): ResponseInterface
    {
        $queryParams = $request->getQueryParams();
        $parsedBody = is_array($request->getParsedBody()) ? $request->getParsedBody() : [];
        $extbaseArgs = $queryParams['tx_sitecommunergaa_site_communergaa'] ?? $parsedBody['tx_sitecommunergaa_site_communergaa'] ?? [];

        $id = trim((string)(
            $queryParams['id']
            ?? $parsedBody['id']
            ?? (is_array($extbaseArgs) ? ($extbaseArgs['id'] ?? '') : '')
        ));
        $type = trim((string)(
            $queryParams['type']
            ?? $parsedBody['type']
            ?? (is_array($extbaseArgs) ? ($extbaseArgs['type'] ?? 'commune') : 'commune')
        ));
        $siteIdentifier = trim((string)(
            $queryParams['siteIdentifier']
            ?? $parsedBody['siteIdentifier']
            ?? (is_array($extbaseArgs) ? ($extbaseArgs['siteIdentifier'] ?? '') : '')
        ));

        if ($id === '') {
            return new JsonResponse([
                'success' => false,
                'error' => 'Identifiant de la collectivité manquant.',
            ], 400);
        }

        try {
            $detail = $this->innogamApiClient->getDetail($id, $type);
            $mappedSettings = $this->collectiviteSettingsMapper->mapToSettings($detail);

            $comparison = [];
            if ($siteIdentifier !== '') {
                $currentSettings = $this->siteManagementService->getSiteSettings($siteIdentifier);
                $comparison = $this->collectiviteSettingsMapper->buildComparison($currentSettings, $detail);
            }

            return new JsonResponse([
                'success' => true,
                'detail' => $detail->toArray(),
                'settings' => $mappedSettings,
                'comparison' => $comparison,
            ]);
        } catch (\Throwable $e) {
            return new JsonResponse([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
