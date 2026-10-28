<?php

declare(strict_types=1);

namespace Commune\SiteCommuneRgaa\Service;

use Commune\SiteCommuneRgaa\Utility\FolderNameSanitizer;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Resource\Folder;
use TYPO3\CMS\Core\Resource\ResourceStorage;
use TYPO3\CMS\Core\Resource\StorageRepository;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Provisionnement du gestionnaire de fichiers lors du déploiement d'une NOUVELLE commune :
 *
 *  1. Création physique de fileadmin/<identifiant>/template/ et fileadmin/<identifiant>/uploads/
 *  2. Enregistrement du stockage FAL "Local filesystem" (sys_file_storage) sur fileadmin/<identifiant>/uploads/
 *  3. Création, dans ce stockage, d'un dossier numéroté par page sélectionnée du "Menu principal"
 *     (même hiérarchie, même ordre), suivi du dossier "FORMATION"
 *  4. Création d'un point de montage (sys_filemounts) sur la racine du stockage, destiné aux
 *     groupes d'utilisateurs backend (rédacteurs) de la commune
 *
 * Principe de non-écrasement : rien n'est jamais supprimé, renommé ni déplacé. Seuls les éléments
 * absents sont créés ; un stockage ou un point de montage existant pour le même chemin est réutilisé.
 * Si le dossier uploads/ contient déjà des éléments (organisation manuelle préexistante), la génération
 * de l'arborescence des pages est entièrement ignorée.
 *
 * Ce service ne crée ni ne modifie aucune page : il se contente de LIRE l'arborescence déjà générée.
 */
class CommuneFileStorageService implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    public const STATUS_SUCCESS = 'success';
    public const STATUS_WARNING = 'warning';
    public const STATUS_ERROR = 'error';

    private const FILEADMIN_DIRECTORY = 'fileadmin';
    private const TEMPLATE_DIRECTORY = 'template';
    private const UPLOADS_DIRECTORY = 'uploads';

    /**
     * Dossiers hors menu principal créés tels quels (sans numérotation), après les dossiers numérotés.
     */
    private const EXTRA_FOLDERS = ['FORMATION'];

    /**
     * Garde-fou contre une récursion infinie (arborescence de pages corrompue).
     */
    private const MAX_DEPTH = 15;

    public function __construct(
        private readonly ConnectionPool $connectionPool,
        private readonly StorageRepository $storageRepository
    ) {
    }

    /**
     * Point d'entrée unique du provisionnement.
     *
     * @param string $identifier Identifiant unique de la commune (ex: "mouguerre")
     * @param string $officialName Nom officiel (ex: "Mairie de Mouguerre")
     * @param int $mainMenuFolderUid UID du dossier de pages "Menu principal" de la commune
     * @param bool $createCustomStorage Si vrai, crée un sys_file_storage dédié. Si faux (par défaut), utilise le stockage 1 (fileadmin).
     * @return array{status: string, messages: array<int, string>, storageUid: int, fileMountUid: int, createdFolders: int, basePath: string, customStorageCreated: bool}
     */
    public function provision(
        string $identifier,
        string $officialName,
        int $mainMenuFolderUid,
        bool $createCustomStorage = false
    ): array {
        $report = [
            'status' => self::STATUS_SUCCESS,
            'messages' => [],
            'storageUid' => 0,
            'fileMountUid' => 0,
            'createdFolders' => 0,
            'basePath' => '',
            'customStorageCreated' => $createCustomStorage,
        ];

        $folderIdentifier = FolderNameSanitizer::sanitizeIdentifier($identifier);
        if ($folderIdentifier === '') {
            return $this->fail($report, sprintf('Identifiant de commune invalide pour le gestionnaire de fichiers : "%s".', $identifier));
        }

        $officialName = trim($officialName) !== '' ? trim($officialName) : $folderIdentifier;
        $basePath = self::FILEADMIN_DIRECTORY . '/' . $folderIdentifier . '/' . self::UPLOADS_DIRECTORY . '/';
        $report['basePath'] = $basePath;

        // 1. Création physique des répertoires racines dans fileadmin/ (template/ et uploads/)
        $communeAbsPath = Environment::getPublicPath() . '/' . self::FILEADMIN_DIRECTORY . '/' . $folderIdentifier . '/';
        $uploadsAbsPath = $communeAbsPath . self::UPLOADS_DIRECTORY . '/';
        $uploadsAlreadyPopulated = $this->isNonEmptyDirectory($uploadsAbsPath);

        try {
            foreach ([$communeAbsPath . self::TEMPLATE_DIRECTORY . '/', $uploadsAbsPath] as $directory) {
                if (!is_dir($directory)) {
                    GeneralUtility::mkdir_deep($directory);
                }
            }
        } catch (\Throwable $e) {
            return $this->fail($report, 'Impossible de créer les répertoires dans fileadmin : ' . $e->getMessage(), $e);
        }

        // 2. Détermination du stockage FAL cible et de l'identifiant du point de montage
        $defaultStorage = $this->storageRepository->getDefaultStorage();
        if ($defaultStorage === null) {
            return $this->fail($report, 'Aucun stockage de fichiers par défaut (fileadmin) configuré dans TYPO3.');
        }

        $targetFolderForTree = null;
        if ($createCustomStorage) {
            // Mode avec point de stockage dédié : création ou réutilisation d'un sys_file_storage Local
            try {
                $storageUid = $this->findLocalStorageUidByBasePath($basePath);
                if ($storageUid > 0) {
                    $this->warn($report, sprintf('Un stockage de fichiers existe déjà pour "%s" (uid %d) : il a été réutilisé.', $basePath, $storageUid));
                } else {
                    $storageUid = $this->createLocalStorageRecord($officialName, $basePath, $folderIdentifier);
                    if (method_exists($this->storageRepository, 'flush')) {
                        $this->storageRepository->flush();
                    }
                }
                $report['storageUid'] = $storageUid;
                $filemountIdentifier = $storageUid . ':/';
                $storage = $this->getStorage($storageUid);
                $targetFolderForTree = $storage->getRootLevelFolder(false);
            } catch (\Throwable $e) {
                return $this->fail($report, 'Impossible d\'enregistrer le stockage de fichiers (sys_file_storage) : ' . $e->getMessage(), $e);
            }
        } else {
            // Mode standard (recommandé) : rattachement au stockage principal fileadmin
            $storageUid = (int)$defaultStorage->getUid();
            $report['storageUid'] = $storageUid;
            $filemountIdentifier = $storageUid . ':/' . $folderIdentifier . '/' . self::UPLOADS_DIRECTORY . '/';
            try {
                $relativeUploadsFolder = $folderIdentifier . '/' . self::UPLOADS_DIRECTORY;
                if ($defaultStorage->hasFolder($relativeUploadsFolder)) {
                    $targetFolderForTree = $defaultStorage->getFolder($relativeUploadsFolder);
                }
            } catch (\Throwable $e) {
                $this->warn($report, 'Accès au dossier uploads via le stockage principal : ' . $e->getMessage(), $e);
            }
        }

        // 3. Arborescence des pages du menu principal (uniquement si uploads/ était vierge)
        if ($uploadsAlreadyPopulated) {
            $this->warn($report, sprintf('Le dossier "%s" contenait déjà des éléments : l\'arborescence n\'a pas été générée afin de préserver l\'organisation existante.', $basePath));
        } elseif ($targetFolderForTree instanceof Folder) {
            try {
                $folderTree = $mainMenuFolderUid > 0 ? $this->buildFolderTreeFromPages($mainMenuFolderUid) : [];
                foreach (self::EXTRA_FOLDERS as $extraFolderName) {
                    $folderTree[] = ['name' => $extraFolderName, 'children' => []];
                }

                $report['createdFolders'] = $this->createFolderTree($targetFolderForTree, $folderTree);
            } catch (\Throwable $e) {
                $this->warn($report, 'Création de l\'arborescence des dossiers interrompue : ' . $e->getMessage(), $e);
            }
        }

        // 4. Point de montage pour les futurs groupes de rédacteurs
        try {
            $fileMountUid = $this->findFileMountUid($filemountIdentifier);
            if ($fileMountUid === 0) {
                $fileMountUid = $this->createFileMountRecord($officialName, $filemountIdentifier, $folderIdentifier, $createCustomStorage);
            } else {
                $this->connectionPool->getConnectionForTable('sys_filemounts')->update(
                    'sys_filemounts',
                    ['title' => $officialName, 'tstamp' => time()],
                    ['uid' => $fileMountUid]
                );
            }
            $report['fileMountUid'] = $fileMountUid;
        } catch (\Throwable $e) {
            $this->warn($report, 'Impossible de créer le point de montage (sys_filemounts) : ' . $e->getMessage(), $e);
        }

        $this->logger?->info('Provisionnement du gestionnaire de fichiers de la commune terminé.', [
            'identifier' => $folderIdentifier,
            'storageUid' => $report['storageUid'],
            'fileMountUid' => $report['fileMountUid'],
            'createdFolders' => $report['createdFolders'],
            'customStorage' => $createCustomStorage,
        ]);

        return $report;
    }

    /**
     * Lit (sans les modifier) les pages enfants d'un parent, triées comme dans l'arbre des pages,
     * et construit l'arborescence de dossiers numérotés correspondante.
     *
     * @return array<int, array{name: string, children: array}>
     */
    private function buildFolderTreeFromPages(int $parentPid, int $depth = 0, string $parentTitle = ''): array
    {
        if ($depth > self::MAX_DEPTH) {
            return [];
        }

        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('pages');
        $queryBuilder->getRestrictions()->removeAll()->add(GeneralUtility::makeInstance(DeletedRestriction::class));

        $rows = $queryBuilder
            ->select('uid', 'title')
            ->from('pages')
            ->where(
                $queryBuilder->expr()->eq('pid', $queryBuilder->createNamedParameter($parentPid, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('sys_language_uid', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT))
            )
            ->orderBy('sorting', 'ASC')
            ->addOrderBy('uid', 'ASC')
            ->executeQuery()
            ->fetchAllAssociative();

        $tree = [];
        $normalizedParentTitle = mb_strtolower(trim($parentTitle));
        $isAccueilParent = ($normalizedParentTitle === 'accueil');

        // Cas spécifique de la page "Accueil" : ajout d'un sous-dossier dédié aux médias du carrousel/bandeau
        // en tête (index 0 : "0_bandeau"). Les sous-pages suivantes démarreront ainsi à l'index 1.
        $indexOffset = 0;
        if ($isAccueilParent) {
            $tree[] = [
                'name' => '0_bandeau',
                'children' => [],
            ];
            $indexOffset = 1;
        }

        $pageIndex = 0;
        foreach ($rows as $row) {
            $title = (string)$row['title'];
            $normalizedTitle = mb_strtolower(trim($title));

            // Exclusion des pages techniques de détail (actualités, agenda...) : aucun dossier FAL dédié
            if ($normalizedTitle === 'détail' || $normalizedTitle === 'detail') {
                continue;
            }

            // Pour les pages d'actualités et d'agenda, on ne crée pas de sous-dossiers
            $hasNoSubfolders = in_array($normalizedTitle, ['actualités', 'actualites', 'agenda'], true);
            $children = $hasNoSubfolders
                ? []
                : $this->buildFolderTreeFromPages((int)$row['uid'], $depth + 1, $title);

            $folderIndex = $pageIndex + $indexOffset;
            $tree[] = [
                'name' => FolderNameSanitizer::buildFolderName($folderIndex, $title),
                'children' => $children,
            ];
            $pageIndex++;
        }

        return $tree;
    }

    /**
     * Crée récursivement les dossiers absents via l'API FAL. Les dossiers existants sont conservés tels quels.
     *
     * @param array<int, array{name: string, children: array}> $tree
     * @return int Nombre de dossiers effectivement créés
     */
    private function createFolderTree(Folder $parentFolder, array $tree): int
    {
        $created = 0;
        foreach ($tree as $node) {
            $name = (string)$node['name'];
            if ($name === '') {
                continue;
            }

            if ($parentFolder->hasFolder($name)) {
                $folder = $parentFolder->getSubfolder($name);
            } else {
                $folder = $parentFolder->createFolder($name);
                $created++;
            }

            if (!empty($node['children'])) {
                $created += $this->createFolderTree($folder, $node['children']);
            }
        }

        return $created;
    }

    /**
     * Enregistre le stockage "Local filesystem" via DataHandler : les champs non renseignés
     * (droits d'accès, navigation, publication, extraction des métadonnées...) reçoivent les valeurs
     * par défaut définies dans la TCA de TYPO3.
     */
    private function createLocalStorageRecord(string $name, string $basePath, string $identifier): int
    {
        $newId = 'NEW_commune_storage';
        $data = [
            'sys_file_storage' => [
                $newId => [
                    'pid' => 0,
                    'name' => $name,
                    'description' => sprintf('Stockage de la commune "%s" (créé automatiquement lors du déploiement).', $identifier),
                    'driver' => 'Local',
                    'configuration' => [
                        'data' => [
                            'sDEF' => [
                                'lDEF' => [
                                    'basePath' => ['vDEF' => $basePath],
                                    'pathType' => ['vDEF' => 'relative'],
                                    'baseUri' => ['vDEF' => ''],
                                    'caseSensitive' => ['vDEF' => '1'],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $uid = $this->processDataMap($data, 'sys_file_storage', $newId);

        // Contrôle a posteriori de la configuration réellement persistée
        $persistedBasePath = $this->extractBasePath($this->fetchStorageRow($uid)['configuration'] ?? '');
        if (trim($persistedBasePath, '/') !== trim($basePath, '/')) {
            throw new \RuntimeException(sprintf('Le stockage uid %d a été créé mais son chemin de base n\'a pas été enregistré correctement.', $uid), 1793020300);
        }

        return $uid;
    }

    /**
     * Crée un point de montage pointant sur le dossier uploads/ de la commune.
     * Il pourra être affecté au groupe backend propre à la collectivité.
     */
    private function createFileMountRecord(string $name, string $filemountIdentifier, string $identifier, bool $isCustomStorage): int
    {
        $newId = 'NEW_commune_filemount';
        $description = $isCustomStorage
            ? sprintf('Point de montage de la commune "%s" (stockage dédié).', $identifier)
            : sprintf('Point de montage de la commune "%s" (stockage principal fileadmin).', $identifier);

        $data = [
            'sys_filemounts' => [
                $newId => [
                    'pid' => 0,
                    'title' => $name,
                    'identifier' => $filemountIdentifier,
                    'description' => $description,
                    'read_only' => 0,
                ],
            ],
        ];

        return $this->processDataMap($data, 'sys_filemounts', $newId);
    }

    /**
     * @param array<string, array<string, array<string, mixed>>> $data
     */
    private function processDataMap(array $data, string $table, string $newId): int
    {
        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start($data, []);
        $dataHandler->process_datamap();

        if (!empty($dataHandler->errorLog)) {
            throw new \RuntimeException(implode(' | ', $dataHandler->errorLog), 1793020301);
        }

        $uid = (int)($dataHandler->substNEWwithIDs[$newId] ?? 0);
        if ($uid <= 0) {
            throw new \RuntimeException(sprintf('Aucun enregistrement "%s" n\'a été créé (droits administrateur requis).', $table), 1793020302);
        }

        return $uid;
    }

    private function getStorage(int $storageUid): ResourceStorage
    {
        // Les données sont transmises explicitement pour contourner le cache d'exécution du StorageRepository,
        // initialisé avant la création de l'enregistrement.
        $storage = $this->storageRepository->getStorageObject($storageUid, $this->fetchStorageRow($storageUid));
        $storage->setEvaluatePermissions(false);

        if (!$storage->isOnline()) {
            throw new \RuntimeException(sprintf('Le stockage uid %d est hors-ligne (chemin de base introuvable ?).', $storageUid), 1793020303);
        }

        return $storage;
    }

    private function findLocalStorageUidByBasePath(string $basePath): int
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('sys_file_storage');
        $queryBuilder->getRestrictions()->removeAll()->add(GeneralUtility::makeInstance(DeletedRestriction::class));

        $rows = $queryBuilder
            ->select('uid', 'configuration')
            ->from('sys_file_storage')
            ->where($queryBuilder->expr()->eq('driver', $queryBuilder->createNamedParameter('Local')))
            ->orderBy('uid', 'ASC')
            ->executeQuery()
            ->fetchAllAssociative();

        foreach ($rows as $row) {
            if (trim($this->extractBasePath((string)$row['configuration']), '/') === trim($basePath, '/')) {
                return (int)$row['uid'];
            }
        }

        return 0;
    }

    private function findFileMountUid(string $filemountIdentifier): int
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('sys_filemounts');
        $queryBuilder->getRestrictions()->removeAll()->add(GeneralUtility::makeInstance(DeletedRestriction::class));

        $uid = $queryBuilder
            ->select('uid')
            ->from('sys_filemounts')
            ->where($queryBuilder->expr()->eq('identifier', $queryBuilder->createNamedParameter($filemountIdentifier)))
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchOne();

        return (int)$uid;
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchStorageRow(int $uid): array
    {
        $row = $this->connectionPool
            ->getConnectionForTable('sys_file_storage')
            ->select(['*'], 'sys_file_storage', ['uid' => $uid])
            ->fetchAssociative();

        if (!is_array($row)) {
            throw new \RuntimeException(sprintf('Stockage uid %d introuvable.', $uid), 1793020304);
        }

        return $row;
    }

    private function extractBasePath(string $flexFormXml): string
    {
        if (trim($flexFormXml) === '') {
            return '';
        }

        $configuration = GeneralUtility::xml2array($flexFormXml);
        if (!is_array($configuration)) {
            return '';
        }

        return (string)($configuration['data']['sDEF']['lDEF']['basePath']['vDEF'] ?? '');
    }

    private function isNonEmptyDirectory(string $absolutePath): bool
    {
        if (!is_dir($absolutePath)) {
            return false;
        }

        $entries = scandir($absolutePath);

        return is_array($entries) && count(array_diff($entries, ['.', '..'])) > 0;
    }

    /**
     * @param array{status: string, messages: array<int, string>, storageUid: int, fileMountUid: int, createdFolders: int, basePath: string} $report
     */
    private function warn(array &$report, string $message, ?\Throwable $exception = null): void
    {
        if ($report['status'] === self::STATUS_SUCCESS) {
            $report['status'] = self::STATUS_WARNING;
        }
        $report['messages'][] = $message;
        $this->logger?->warning($message, $exception ? ['exception' => $exception] : []);
    }

    /**
     * @param array{status: string, messages: array<int, string>, storageUid: int, fileMountUid: int, createdFolders: int, basePath: string} $report
     * @return array{status: string, messages: array<int, string>, storageUid: int, fileMountUid: int, createdFolders: int, basePath: string}
     */
    private function fail(array $report, string $message, ?\Throwable $exception = null): array
    {
        $report['status'] = self::STATUS_ERROR;
        $report['messages'][] = $message;
        $this->logger?->error($message, $exception ? ['exception' => $exception] : []);

        return $report;
    }
}
