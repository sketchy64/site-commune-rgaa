<?php

declare(strict_types=1);

namespace Commune\SiteCommuneRgaa\Innogam;

use Commune\SiteCommuneRgaa\Innogam\Dto\Agent;
use Commune\SiteCommuneRgaa\Innogam\Dto\CollectiviteDetail;
use Commune\SiteCommuneRgaa\Innogam\Dto\CollectiviteSummary;
use Commune\SiteCommuneRgaa\Innogam\Dto\Elu;
use Commune\SiteCommuneRgaa\Innogam\Exception\InnogamApiException;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Http\RequestFactory;

/**
 * Client officiel pour l'API Innogam avec gestion du cache TYPO3,
 * token d'authentification Bearer et normalisation des collectivités locales.
 */
class InnogamApiClient
{
    private const DEFAULT_API_BASE_URL = 'https://innogam-api.innoapps.innovortex.network/api';
    private const DEFAULT_USERNAME = 'struct_xzwe_64';
    private const DEFAULT_PASSWORD = '^T12vehSNsjvqyfaph';
    private const DEFAULT_CACHE_LIFETIME = 86400;

    private ?FrontendInterface $cache = null;

    public function __construct(
        private readonly RequestFactory $requestFactory,
        private readonly ExtensionConfiguration $extensionConfiguration,
        private readonly CacheManager $cacheManager
    ) {
    }

    private function getCache(): FrontendInterface
    {
        if ($this->cache === null) {
            $this->cache = $this->cacheManager->getCache('site_commune_rgaa_innogam');
        }
        return $this->cache;
    }

    /**
     * Récupère la configuration de l'extension (avec valeurs par défaut de secours).
     *
     * @return array<string, mixed>
     */
    private function getConfig(): array
    {
        try {
            return $this->extensionConfiguration->get('site_commune_rgaa') ?? [];
        } catch (\Throwable) {
            return [];
        }
    }

    public function getApiBaseUrl(): string
    {
        $config = $this->getConfig();
        $url = trim((string)($config['apiBaseUrl'] ?? ''));
        return $url !== '' ? rtrim($url, '/') : self::DEFAULT_API_BASE_URL;
    }

    public function getUsername(): string
    {
        $config = $this->getConfig();
        $user = trim((string)($config['apiUsername'] ?? ''));
        return $user !== '' ? $user : self::DEFAULT_USERNAME;
    }

    public function getPassword(): string
    {
        $config = $this->getConfig();
        $pass = (string)($config['apiPassword'] ?? '');
        return $pass !== '' ? $pass : self::DEFAULT_PASSWORD;
    }

    public function getCacheLifetime(): int
    {
        $config = $this->getConfig();
        $lifetime = (int)($config['cacheLifetime'] ?? 0);
        return $lifetime > 0 ? $lifetime : self::DEFAULT_CACHE_LIFETIME;
    }

    /**
     * Authentification et obtention du jeton d'accès (Bearer Token).
     * Le jeton est mis en cache pour éviter les requêtes répétées.
     */
    public function getToken(bool $forceRefresh = false): string
    {
        $cache = $this->getCache();
        $cacheKey = 'innogam_auth_token';

        if (!$forceRefresh && $cache->has($cacheKey)) {
            $cachedToken = (string)$cache->get($cacheKey);
            if (!empty($cachedToken)) {
                return $cachedToken;
            }
        }

        $loginUrl = $this->getApiBaseUrl() . '/connexion';

        try {
            $response = $this->requestFactory->request($loginUrl, 'POST', [
                'headers' => [
                    'Content-Type' => 'application/x-www-form-urlencoded',
                    'Accept' => 'application/json',
                ],
                'body' => http_build_query([
                    'username' => $this->getUsername(),
                    'password' => $this->getPassword(),
                ]),
                'timeout' => 10,
            ]);

            if ($response->getStatusCode() !== 200) {
                throw new InnogamApiException(
                    sprintf('Échec de la connexion API Innogam (HTTP %d). Vérifiez vos identifiants.', $response->getStatusCode())
                );
            }

            $body = (string)$response->getBody();
            $data = json_decode($body, true);

            if (!is_array($data) || empty($data['access_token'])) {
                $err = is_array($data) && isset($data['error']) ? (string)$data['error'] : 'Réponse invalide de l\'API';
                throw new InnogamApiException('Authentification Innogam impossible : ' . $err);
            }

            $token = (string)$data['access_token'];
            // Token stocké pendant 50 minutes (validité standard JWT 1 heure)
            $cache->set($cacheKey, $token, [], 3000);

            return $token;
        } catch (\Throwable $e) {
            if ($e instanceof InnogamApiException) {
                throw $e;
            }
            throw new InnogamApiException('Erreur réseau lors de la connexion à l\'API Innogam : ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Récupère la liste complète des communes et intercommunalités/syndicats.
     * Mise en cache selon la durée configurée.
     *
     * @return array<int, CollectiviteSummary>
     */
    public function getCollectivites(bool $forceRefresh = false): array
    {
        $cache = $this->getCache();
        $cacheKey = 'innogam_collectivites_list';

        if (!$forceRefresh && $cache->has($cacheKey)) {
            $cached = $cache->get($cacheKey);
            if (is_array($cached)) {
                return $cached;
            }
        }

        $token = $this->getToken($forceRefresh);
        $baseUrl = $this->getApiBaseUrl();

        try {
            $headers = [
                'Authorization' => 'Bearer ' . $token,
                'Accept' => 'application/json',
            ];

            // 1. Communes
            $communesRes = $this->requestFactory->request($baseUrl . '/communes', 'GET', [
                'headers' => $headers,
                'timeout' => 15,
            ]);
            $communes = json_decode((string)$communesRes->getBody(), true);

            // 2. Intercommunalités & EPCI / Syndicats
            $intercosRes = $this->requestFactory->request($baseUrl . '/intercos', 'GET', [
                'headers' => $headers,
                'timeout' => 15,
            ]);
            $intercos = json_decode((string)$intercosRes->getBody(), true);

            $results = [];

            if (is_array($communes)) {
                foreach ($communes as $c) {
                    if (isset($c['insee'], $c['nom'])) {
                        $results[] = new CollectiviteSummary(
                            (string)$c['insee'],
                            'commune',
                            (string)$c['nom']
                        );
                    }
                }
            }

            if (is_array($intercos)) {
                foreach ($intercos as $i) {
                    if (isset($i['siren'], $i['nom'])) {
                        $results[] = new CollectiviteSummary(
                            (string)$i['siren'],
                            'interco',
                            (string)$i['nom'],
                            (string)($i['nom_court'] ?? '')
                        );
                    }
                }
            }

            // Tri alphabétique sur le nom
            usort($results, static function (CollectiviteSummary $a, CollectiviteSummary $b): int {
                return strcmp(self::normalizeString($a->getNom()), self::normalizeString($b->getNom()));
            });

            $cache->set($cacheKey, $results, [], $this->getCacheLifetime());

            return $results;
        } catch (\Throwable $e) {
            // Si échec token expiré, tenter un rafraîchissement unique
            if (!$forceRefresh && str_contains($e->getMessage(), '401')) {
                return $this->getCollectivites(true);
            }
            throw new InnogamApiException('Erreur lors du chargement des collectivités Innogam : ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Recherche textuelle avec auto-complétion multi-critères et suppression des accents.
     *
     * @return array<int, CollectiviteSummary>
     */
    public function search(string $query, int $limit = 12): array
    {
        $trimmed = trim($query);
        if (mb_strlen($trimmed) < 2) {
            return [];
        }

        $all = $this->getCollectivites();
        $normalizedQuery = self::normalizeString($trimmed);
        $searchTerms = array_values(array_filter(explode(' ', $normalizedQuery)));

        if (empty($searchTerms)) {
            return [];
        }

        $matches = [];

        foreach ($all as $item) {
            $normalizedNom = self::normalizeString($item->getNom());
            $normalizedNomCourt = self::normalizeString($item->getNomCourt());
            $id = $item->getId();

            $allTermsMatch = true;
            foreach ($searchTerms as $term) {
                if (
                    !str_contains($normalizedNom, $term)
                    && !str_contains($normalizedNomCourt, $term)
                    && !str_contains($id, $term)
                ) {
                    $allTermsMatch = false;
                    break;
                }
            }

            if ($allTermsMatch) {
                $matches[] = $item;
                if (count($matches) >= $limit) {
                    break;
                }
            }
        }

        return $matches;
    }

    /**
     * Récupère la fiche détaillée d'une commune ou d'une intercommunalité/EPCI.
     */
    public function getDetail(string $id, string $type = 'commune', bool $forceRefresh = false): CollectiviteDetail
    {
        $id = trim($id);
        $type = $type === 'interco' ? 'interco' : 'commune';

        if ($id === '') {
            throw new \InvalidArgumentException('L\'identifiant de la collectivité ne peut pas être vide.');
        }

        $cache = $this->getCache();
        $cacheKey = 'innogam_detail_' . $type . '_' . $id;

        if (!$forceRefresh && $cache->has($cacheKey)) {
            $cached = $cache->get($cacheKey);
            if ($cached instanceof CollectiviteDetail) {
                return $cached;
            }
        }

        $token = $this->getToken($forceRefresh);
        $endpoint = $type === 'commune'
            ? '/wordpress/commune?insee=' . urlencode($id)
            : '/wordpress/epci?siren=' . urlencode($id);

        $url = $this->getApiBaseUrl() . $endpoint;

        try {
            $response = $this->requestFactory->request($url, 'GET', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $token,
                    'Accept' => 'application/json',
                ],
                'timeout' => 15,
            ]);

            if ($response->getStatusCode() === 401 && !$forceRefresh) {
                // Token expiré, réessayer une fois
                return $this->getDetail($id, $type, true);
            }

            if ($response->getStatusCode() !== 200) {
                throw new InnogamApiException(sprintf('Collectivité introuvable sur l\'API Innogam (HTTP %d).', $response->getStatusCode()));
            }

            $raw = json_decode((string)$response->getBody(), true);
            $item = (is_array($raw) && isset($raw[0]) && is_array($raw[0])) ? $raw[0] : (is_array($raw) ? $raw : []);

            if (empty($item)) {
                throw new InnogamApiException('Aucune information retournée par l\'API pour cette collectivité.');
            }

            // Traitement des élus
            $elus = [];
            if (isset($item['elus']) && is_array($item['elus'])) {
                foreach ($item['elus'] as $e) {
                    if (is_array($e)) {
                        $elus[] = new Elu(
                            (string)($e['civilite'] ?? ''),
                            (string)($e['nom'] ?? ''),
                            (string)($e['prenom'] ?? ''),
                            (string)($e['photo'] ?? ''),
                            (string)($e['type_mandat'] ?? '')
                        );
                    }
                }
            }

            // Traitement des agents
            $agents = [];
            if (isset($item['agents']) && is_array($item['agents'])) {
                foreach ($item['agents'] as $a) {
                    if (is_array($a)) {
                        $agents[] = new Agent(
                            (string)($a['nom'] ?? ''),
                            (string)($a['prenom'] ?? ''),
                            (string)($a['type'] ?? ''),
                            (string)($a['fonction'] ?? ''),
                            (string)($a['service'] ?? ''),
                            (string)($a['tel_professionnel'] ?? ''),
                            (string)($a['email_professionnel'] ?? '')
                        );
                    }
                }
            }

            // Communes membres (si intercommunalité)
            $communesMembres = [];
            if (isset($item['communes_membres']) && is_array($item['communes_membres'])) {
                foreach ($item['communes_membres'] as $cm) {
                    if (is_array($cm) && isset($cm['insee'])) {
                        $communesMembres[] = [
                            'insee' => (string)$cm['insee'],
                            'nom' => (string)($cm['nom'] ?? ''),
                        ];
                    }
                }
            }

            $population = isset($item['population_totale']) && is_numeric($item['population_totale'])
                ? (int)$item['population_totale']
                : null;

            $superficie = isset($item['superficie']) && is_numeric($item['superficie'])
                ? (float)$item['superficie']
                : null;

            $detail = new CollectiviteDetail(
                $id,
                $type,
                (string)($item['nom'] ?? ''),
                (string)($item['nom_court'] ?? ''),
                (string)($item['ligne_adresse_1'] ?? ''),
                (string)($item['ligne_adresse_2'] ?? ''),
                (string)($item['code_postal'] ?? ''),
                (string)($item['ville'] ?? ''),
                (string)($item['cedex'] ?? ''),
                (string)($item['tel'] ?? ''),
                (string)($item['email_publique'] ?? ''),
                (string)($item['site'] ?? ''),
                (string)($item['horaires'] ?? ''),
                (string)($item['gentille'] ?? ''),
                $population,
                $superficie,
                (string)($item['nom_intercommunalite'] ?? ''),
                $communesMembres,
                $elus,
                $agents
            );

            // Mise en cache des détails pour 24 heures
            $cache->set($cacheKey, $detail, [], $this->getCacheLifetime());

            return $detail;
        } catch (\Throwable $e) {
            if ($e instanceof InnogamApiException) {
                throw $e;
            }
            throw new InnogamApiException('Erreur lors de la récupération des détails Innogam : ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Supprime les accents et convertit en minuscules pour comparaison insensible.
     */
    public static function normalizeString(string $string): string
    {
        if ($string === '') {
            return '';
        }

        if (function_exists('transliterator_transliterate')) {
            $transliterated = transliterator_transliterate('Any-Latin; Latin-ASCII; Lower()', $string);
            if ($transliterated !== false) {
                return $transliterated;
            }
        }

        $unwanted = [
            'Š'=>'S', 'š'=>'s', 'Ž'=>'Z', 'ž'=>'z', 'À'=>'A', 'Á'=>'A', 'Â'=>'A', 'Ã'=>'A', 'Ä'=>'A', 'Å'=>'A', 'Æ'=>'A', 'Ç'=>'C', 'È'=>'E', 'É'=>'E',
            'Ê'=>'E', 'Ë'=>'E', 'Ì'=>'I', 'Í'=>'I', 'Î'=>'I', 'Ï'=>'I', 'Ñ'=>'N', 'Ò'=>'O', 'Ó'=>'O', 'Ô'=>'O', 'Õ'=>'O', 'Ö'=>'O', 'Ø'=>'O', 'Ù'=>'U',
            'Ú'=>'U', 'Û'=>'U', 'Ü'=>'U', 'Ý'=>'Y', 'Þ'=>'B', 'ß'=>'Ss', 'à'=>'a', 'á'=>'a', 'â'=>'a', 'ã'=>'a', 'ä'=>'a', 'å'=>'a', 'æ'=>'a', 'ç'=>'c',
            'è'=>'e', 'é'=>'e', 'ê'=>'e', 'ë'=>'e', 'ì'=>'i', 'í'=>'i', 'î'=>'i', 'ï'=>'i', 'ð'=>'o', 'ñ'=>'n', 'ò'=>'o', 'ó'=>'o', 'ô'=>'o', 'õ'=>'o',
            'ö'=>'o', 'ø'=>'o', 'ù'=>'u', 'ú'=>'u', 'û'=>'u', 'ý'=>'y', 'þ'=>'b', 'ÿ'=>'y', 'œ'=>'oe', 'Œ'=>'OE',
        ];

        return mb_strtolower(strtr($string, $unwanted));
    }
}
