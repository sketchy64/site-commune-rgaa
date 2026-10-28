<?php

declare(strict_types=1);

namespace Commune\SiteCommuneRgaa\Service;

use Commune\SiteCommuneRgaa\Utility\FolderNameSanitizer;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use TYPO3\CMS\Core\Crypto\PasswordHashing\PasswordHashFactory;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Service de gestion et de provisionnement automatique des groupes et utilisateurs Backend
 * lors du déploiement d'une nouvelle collectivité :
 *
 *  1. Création / mise à jour du groupe Backend propre à la collectivité (ex: "MOUGUERRE")
 *     qui porte exclusivement les montages BDD (siteroot) et de fichiers (uploads FAL).
 *  2. Rattachement aux groupes globaux existants sélectionnés (ex: "_RÉDACTEUR").
 *  3. Création des 2 utilisateurs Backend dédiés :
 *     - Utilisateur 1 (Secrétariat) : "secretariat.mouguerre" / "Secrétariat MOUGUERRE"
 *     - Utilisateur 2 (Élu)        : "elu.mouguerre" / "Élu MOUGUERRE"
 *     Tous deux configurés en langue française, avec les cases "Montages BDD" et "Montages de fichiers"
 *     hérités des groupes cochées (options = 3), et un mot de passe temporaire fort hashé.
 */
class CommuneBackendUserService implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    public const STATUS_SUCCESS = 'success';
    public const STATUS_WARNING = 'warning';
    public const STATUS_ERROR = 'error';

    /**
     * Valeur binaire pour be_users.options :
     * 1 = hériter des montages BDD depuis les groupes
     * 2 = hériter des montages de fichiers depuis les groupes
     * 3 = 1 | 2 (les deux cochés)
     */
    public const USER_OPTIONS_MOUNTS_FROM_GROUPS = 3;

    private readonly ConnectionPool $connectionPool;
    private readonly PasswordHashFactory $passwordHashFactory;

    public function __construct(
        ?ConnectionPool $connectionPool = null,
        ?PasswordHashFactory $passwordHashFactory = null
    ) {
        $this->connectionPool = $connectionPool ?? GeneralUtility::makeInstance(ConnectionPool::class);
        $this->passwordHashFactory = $passwordHashFactory ?? GeneralUtility::makeInstance(PasswordHashFactory::class);
    }

    /**
     * Normalise le nom officiel de la commune en majuscules strictes en retirant d'éventuels
     * préfixes saisis ("Mairie de", "Commune de", "Ville de", etc.).
     *
     * Exemple : "Mairie de Mouguerre" => "MOUGUERRE"
     *           "MOUGUERRE"           => "MOUGUERRE"
     *           "Saint-Jean-de-Luz"   => "SAINT-JEAN-DE-LUZ"
     */
    public static function formatCommuneUpperName(string $name): string
    {
        $clean = trim($name);
        $patterns = [
            '/^mairie\s+de\s+/iu',
            '/^mairie\s+d[\'’]/iu',
            '/^commune\s+de\s+/iu',
            '/^commune\s+d[\'’]/iu',
            '/^ville\s+de\s+/iu',
            '/^ville\s+d[\'’]/iu',
        ];
        $clean = (string)preg_replace($patterns, '', $clean);
        $clean = trim($clean);

        if ($clean === '') {
            $clean = trim($name);
        }

        return mb_strtoupper($clean, 'UTF-8');
    }

    /**
     * Construit le slug normalisé représentant la collectivité pour les identifiants d'utilisateurs.
     * Si un identifiant unique est fourni (ex: "biarritz-2", "mouguerre"), il prévaut pour garantir
     * des logins étanches et adaptés lors du déploiement de sites multiples.
     *
     * Exemple : "MOUGUERRE" => "mouguerre"
     *           "Biarritz", identifiant "biarritz2" => "biarritz2"
     */
    public static function buildCommuneSlug(string $name, string $identifier = ''): string
    {
        $ident = trim($identifier);
        if ($ident !== '') {
            $slug = FolderNameSanitizer::slugify($ident);
            $slug = (string)preg_replace('/^commune-/', '', $slug);
            if ($slug !== '') {
                return $slug;
            }
        }

        $upper = self::formatCommuneUpperName($name);
        $slug = FolderNameSanitizer::slugify($upper);

        return $slug !== '' ? $slug : 'commune';
    }

    /**
     * Récupère tous les groupes d'utilisateurs backend existants et actifs dans TYPO3.
     *
     * @return array<int, array{uid: int, title: string, description: string}>
     */
    public function getAvailableBackendUserGroups(): array
    {
        try {
            $queryBuilder = $this->connectionPool->getQueryBuilderForTable('be_groups');
            $queryBuilder->getRestrictions()->removeAll()->add(GeneralUtility::makeInstance(DeletedRestriction::class));

            /** @var array<int, array{uid: int|string, title: string, description: string}> $rows */
            $rows = $queryBuilder
                ->select('uid', 'title', 'description')
                ->from('be_groups')
                ->where($queryBuilder->expr()->eq('hidden', 0))
                ->orderBy('title', 'ASC')
                ->executeQuery()
                ->fetchAllAssociative();

            $result = [];
            foreach ($rows as $row) {
                $result[] = [
                    'uid' => (int)$row['uid'],
                    'title' => (string)($row['title'] ?? ''),
                    'description' => (string)($row['description'] ?? ''),
                ];
            }

            return $result;
        } catch (\Throwable $e) {
            $this->logger?->error('Impossible de charger les groupes be_groups : ' . $e->getMessage(), ['exception' => $e]);
            return [];
        }
    }

    /**
     * Recherche l'UID du groupe de rédacteurs par défaut (ex: "_RÉDACTEUR", "_REDACTEUR", "RÉDACTEUR").
     *
     * @param array<int, array{uid: int, title: string, description: string}> $groups
     */
    public function findDefaultRedacteurGroupUid(array $groups): int
    {
        // 1. Recherche exacte insensible à la casse et aux accents
        foreach ($groups as $group) {
            $title = mb_strtolower(trim($group['title']), 'UTF-8');
            $normalized = str_replace(['_', ' ', 'é', 'è', 'ê', 'ë'], ['', '', 'e', 'e', 'e', 'e'], $title);
            if ($normalized === 'redacteur' || $normalized === 'redacteurs') {
                return $group['uid'];
            }
        }

        // 2. Recherche par contenant
        foreach ($groups as $group) {
            $title = mb_strtolower(trim($group['title']), 'UTF-8');
            $normalized = str_replace(['_', ' ', 'é', 'è', 'ê', 'ë'], ['', '', 'e', 'e', 'e', 'e'], $title);
            if (str_contains($normalized, 'redact')) {
                return $group['uid'];
            }
        }

        return 0;
    }

    /**
     * Provisionne automatiquement :
     *  - Le groupe BE dédié à la collectivité portant ses points de montage BDD et FAL
     *  - Les deux comptes utilisateurs (Secrétariat et Élu) rattachés aux groupes sélectionnés et au groupe communal
     *
     * @param string $identifier Identifiant unique du site (ex: "mouguerre")
     * @param string $name Nom de la commune (ex: "Mouguerre" ou "MOUGUERRE")
     * @param int $rootPageId UID de la page racine créée pour le site
     * @param int $fileMountUid UID du point de montage sys_filemounts créé pour la commune
     * @param array<string, mixed> $data Données transmises par l'assistant de déploiement
     * @return array{
     *     status: string,
     *     messages: array<int, string>,
     *     communeGroupUid: int,
     *     communeGroupTitle: string,
     *     assignedGroupUids: array<int, int>,
     *     users: array{
     *         secretariat: array{uid: int, username: string, realName: string, email: string, plainPassword: string, success: bool},
     *         elu: array{uid: int, username: string, realName: string, email: string, plainPassword: string, success: bool}
     *     }
     * }
     */
    public function provision(
        string $identifier,
        string $name,
        int $rootPageId,
        int $fileMountUid,
        array $data = []
    ): array {
        $upperCommune = self::formatCommuneUpperName(!empty($data['commune_group_title']) ? (string)$data['commune_group_title'] : $name);
        $communeSlug = self::buildCommuneSlug($name, $identifier);

        $report = [
            'status' => self::STATUS_SUCCESS,
            'messages' => [],
            'communeGroupUid' => 0,
            'communeGroupTitle' => $upperCommune,
            'assignedGroupUids' => [],
            'users' => [
                'secretariat' => [
                    'uid' => 0,
                    'username' => '',
                    'realName' => '',
                    'email' => '',
                    'plainPassword' => '',
                    'success' => false,
                ],
                'elu' => [
                    'uid' => 0,
                    'username' => '',
                    'realName' => '',
                    'email' => '',
                    'plainPassword' => '',
                    'success' => false,
                ],
            ],
        ];

        // 1. Création ou mise à jour du groupe dédié à la commune
        try {
            $communeGroupUid = $this->createOrUpdateCommuneGroup($upperCommune, $rootPageId, $fileMountUid, $identifier);
            $report['communeGroupUid'] = $communeGroupUid;
        } catch (\Throwable $e) {
            $report['status'] = self::STATUS_ERROR;
            $report['messages'][] = 'Erreur lors de la création du groupe commune : ' . $e->getMessage();
            $this->logger?->error('Échec de la création du groupe de commune', ['exception' => $e]);
            return $report;
        }

        // 2. Résolution des groupes globaux sélectionnés dans l'assistant
        $selectedGlobalGroupUids = $this->resolveSelectedGroupUids($data);
        $allGroupUids = array_values(array_unique(array_merge($selectedGlobalGroupUids, [$communeGroupUid])));
        $report['assignedGroupUids'] = $allGroupUids;
        $usergroupsCsv = implode(',', $allGroupUids);

        // Si l'administrateur a explicitement décoché la création automatique des utilisateurs
        if (isset($data['user_create']) && (string)$data['user_create'] === '0') {
            $report['messages'][] = 'La création des utilisateurs a été ignorée conformément au paramétrage.';
            return $report;
        }

        // 3. Préparation des données de l'Utilisateur 1 : Secrétariat
        $secUsername = trim((string)($data['user_secretariat_username'] ?? ''));
        if ($secUsername === '') {
            $secUsername = 'secretariat.' . $communeSlug;
        }

        $secRealName = trim((string)($data['user_secretariat_realname'] ?? ''));
        if ($secRealName === '') {
            $secRealName = 'Secrétariat ' . $upperCommune;
        }

        $secEmail = trim((string)($data['user_secretariat_email'] ?? ($data['email'] ?? '')));
        $secPassword = $this->generateStrongPassword();

        // 4. Préparation des données de l'Utilisateur 2 : Élu
        $eluUsername = trim((string)($data['user_elu_username'] ?? ''));
        if ($eluUsername === '') {
            $eluUsername = 'elu.' . $communeSlug;
        }

        $eluRealName = trim((string)($data['user_elu_realname'] ?? ''));
        if ($eluRealName === '') {
            $eluRealName = 'Élu ' . $upperCommune;
        }

        $eluEmail = trim((string)($data['user_elu_email'] ?? '')); // Laissé vide par défaut
        $eluPassword = $this->generateStrongPassword();

        // 5. Création physique des utilisateurs dans be_users
        try {
            $secUid = $this->createOrUpdateBackendUser([
                'username' => $secUsername,
                'realName' => $secRealName,
                'email' => $secEmail,
                'password' => $secPassword,
                'usergroup' => $usergroupsCsv,
            ]);

            $report['users']['secretariat'] = [
                'uid' => $secUid,
                'username' => $secUsername,
                'realName' => $secRealName,
                'email' => $secEmail,
                'plainPassword' => $secPassword,
                'success' => true,
            ];
        } catch (\Throwable $e) {
            $report['status'] = self::STATUS_WARNING;
            $report['messages'][] = 'Utilisateur Secrétariat : ' . $e->getMessage();
            $this->logger?->error('Échec création utilisateur Secrétariat', ['exception' => $e]);
        }

        try {
            $eluUid = $this->createOrUpdateBackendUser([
                'username' => $eluUsername,
                'realName' => $eluRealName,
                'email' => $eluEmail,
                'password' => $eluPassword,
                'usergroup' => $usergroupsCsv,
            ]);

            $report['users']['elu'] = [
                'uid' => $eluUid,
                'username' => $eluUsername,
                'realName' => $eluRealName,
                'email' => $eluEmail,
                'plainPassword' => $eluPassword,
                'success' => true,
            ];
        } catch (\Throwable $e) {
            $report['status'] = self::STATUS_WARNING;
            $report['messages'][] = 'Utilisateur Élu : ' . $e->getMessage();
            $this->logger?->error('Échec création utilisateur Élu', ['exception' => $e]);
        }

        return $report;
    }

    /**
     * Crée ou met à jour l'enregistrement dans la table be_groups.
     * Le groupe porte le nom officiel en majuscules (ex: "MOUGUERRE") et affecte
     * exclusivement le montage BDD et le montage de fichiers de la collectivité.
     * Si un groupe du même nom existe déjà mais appartient à une autre commune (db_mountpoints différent),
     * un groupe distinct est créé sans jamais écraser la configuration de l'autre collectivité.
     */
    private function createOrUpdateCommuneGroup(string $groupTitle, int $rootPageId, int $fileMountUid, string $identifier = ''): int
    {
        $connection = $this->connectionPool->getConnectionForTable('be_groups');
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('be_groups');
        $queryBuilder->getRestrictions()->removeAll()->add(GeneralUtility::makeInstance(DeletedRestriction::class));

        /** @var array{uid: int|string, db_mountpoints: string}|false $existing */
        $existing = $queryBuilder
            ->select('uid', 'db_mountpoints')
            ->from('be_groups')
            ->where($queryBuilder->expr()->eq('title', $queryBuilder->createNamedParameter($groupTitle)))
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();

        $now = time();
        $dbMounts = $rootPageId > 0 ? (string)$rootPageId : '';
        $fileMounts = $fileMountUid > 0 ? (string)$fileMountUid : '';

        // Si le groupe existe déjà mais est lié à une autre racine BDD, on suffixe pour ne pas écraser l'ancien site
        if ($existing !== false && (int)$existing['uid'] > 0) {
            $existingMount = trim((string)($existing['db_mountpoints'] ?? ''));
            if ($existingMount !== '' && $existingMount !== $dbMounts) {
                $suffix = $identifier !== '' ? $identifier : (string)$rootPageId;
                $groupTitle = sprintf('%s (%s)', $groupTitle, $suffix);

                // Re-vérification avec le nouveau titre suffisé
                $qb2 = $this->connectionPool->getQueryBuilderForTable('be_groups');
                $qb2->getRestrictions()->removeAll()->add(GeneralUtility::makeInstance(DeletedRestriction::class));
                /** @var array{uid: int|string}|false $existing2 */
                $existing2 = $qb2
                    ->select('uid')
                    ->from('be_groups')
                    ->where($qb2->expr()->eq('title', $qb2->createNamedParameter($groupTitle)))
                    ->setMaxResults(1)
                    ->executeQuery()
                    ->fetchAssociative();

                if ($existing2 !== false && (int)$existing2['uid'] > 0) {
                    $uid = (int)$existing2['uid'];
                    $connection->update(
                        'be_groups',
                        [
                            'db_mountpoints' => $dbMounts,
                            'file_mountpoints' => $fileMounts,
                            'description' => sprintf('Montages BDD (page %d) et fichiers (montage %d) de la collectivité %s.', $rootPageId, $fileMountUid, $groupTitle),
                            'hidden' => 0,
                            'tstamp' => $now,
                        ],
                        ['uid' => $uid]
                    );
                    return $uid;
                }

                $connection->insert('be_groups', [
                    'pid' => 0,
                    'title' => $groupTitle,
                    'description' => sprintf('Montages BDD (page %d) et fichiers (montage %d) de la collectivité %s.', $rootPageId, $fileMountUid, $groupTitle),
                    'db_mountpoints' => $dbMounts,
                    'file_mountpoints' => $fileMounts,
                    'hidden' => 0,
                    'deleted' => 0,
                    'crdate' => $now,
                    'tstamp' => $now,
                ]);

                return (int)$connection->lastInsertId();
            }

            $uid = (int)$existing['uid'];
            $connection->update(
                'be_groups',
                [
                    'db_mountpoints' => $dbMounts,
                    'file_mountpoints' => $fileMounts,
                    'description' => sprintf('Montages BDD (page %d) et fichiers (montage %d) de la collectivité %s.', $rootPageId, $fileMountUid, $groupTitle),
                    'hidden' => 0,
                    'tstamp' => $now,
                ],
                ['uid' => $uid]
            );
            return $uid;
        }

        $connection->insert('be_groups', [
            'pid' => 0,
            'title' => $groupTitle,
            'description' => sprintf('Montages BDD (page %d) et fichiers (montage %d) de la collectivité %s.', $rootPageId, $fileMountUid, $groupTitle),
            'db_mountpoints' => $dbMounts,
            'file_mountpoints' => $fileMounts,
            'hidden' => 0,
            'deleted' => 0,
            'crdate' => $now,
            'tstamp' => $now,
        ]);

        return (int)$connection->lastInsertId();
    }

    /**
     * Crée ou met à jour un utilisateur dans la table be_users.
     *
     * @param array{username: string, realName: string, email: string, password: string, usergroup: string} $userData
     */
    private function createOrUpdateBackendUser(array $userData): int
    {
        $connection = $this->connectionPool->getConnectionForTable('be_users');
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('be_users');
        $queryBuilder->getRestrictions()->removeAll()->add(GeneralUtility::makeInstance(DeletedRestriction::class));

        /** @var array{uid: int|string}|false $existing */
        $existing = $queryBuilder
            ->select('uid')
            ->from('be_users')
            ->where($queryBuilder->expr()->eq('username', $queryBuilder->createNamedParameter($userData['username'])))
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();

        $hashInstance = $this->passwordHashFactory->getDefaultHashInstance('BE');
        $hashedPassword = $hashInstance->getHashedPassword($userData['password']);
        $now = time();

        $fields = [
            'realName' => $userData['realName'],
            'email' => $userData['email'],
            'password' => $hashedPassword,
            'usergroup' => $userData['usergroup'],
            'lang' => 'fr',
            'options' => self::USER_OPTIONS_MOUNTS_FROM_GROUPS, // Montages BDD et fichiers cochés
            'disable' => 0,
            'admin' => 0,
            'tstamp' => $now,
        ];

        if ($existing !== false && (int)$existing['uid'] > 0) {
            $uid = (int)$existing['uid'];
            $connection->update('be_users', $fields, ['uid' => $uid]);
            return $uid;
        }

        $fields['pid'] = 0;
        $fields['username'] = $userData['username'];
        $fields['deleted'] = 0;
        $fields['crdate'] = $now;

        $connection->insert('be_users', $fields);

        return (int)$connection->lastInsertId();
    }

    /**
     * Résout les identifiants de groupes choisis dans les données du formulaire de l'assistant.
     *
     * @param array<string, mixed> $data
     * @return array<int, int>
     */
    private function resolveSelectedGroupUids(array $data): array
    {
        $uids = [];

        if (isset($data['backend_groups']) && is_array($data['backend_groups'])) {
            foreach ($data['backend_groups'] as $uid) {
                if (is_numeric($uid) && (int)$uid > 0) {
                    $uids[] = (int)$uid;
                }
            }
        } elseif (isset($data['backend_groups_csv']) && is_string($data['backend_groups_csv']) && $data['backend_groups_csv'] !== '') {
            $parts = explode(',', $data['backend_groups_csv']);
            foreach ($parts as $part) {
                if (is_numeric($part) && (int)$part > 0) {
                    $uids[] = (int)$part;
                }
            }
        } else {
            // Par défaut si non spécifié : rechercher le groupe _RÉDACTEUR
            $allGroups = $this->getAvailableBackendUserGroups();
            $redacteurUid = $this->findDefaultRedacteurGroupUid($allGroups);
            if ($redacteurUid > 0) {
                $uids[] = $redacteurUid;
            }
        }

        return array_values(array_unique($uids));
    }

    /**
     * Génère un mot de passe fort sans caractères visuellement ambigus (0/O, 1/l, etc.).
     */
    private function generateStrongPassword(int $length = 16): string
    {
        $sets = [
            'abcdefghjkmnpqrstuvwxyz',
            'ABCDEFGHJKMNPQRSTUVWXYZ',
            '23456789',
            '!@#$%&*?_-',
        ];

        $chars = '';
        foreach ($sets as $set) {
            $chars .= $set[random_int(0, strlen($set) - 1)];
        }

        $all = implode('', $sets);
        for ($i = strlen($chars); $i < $length; $i++) {
            $chars .= $all[random_int(0, strlen($all) - 1)];
        }

        return str_shuffle($chars);
    }
}
