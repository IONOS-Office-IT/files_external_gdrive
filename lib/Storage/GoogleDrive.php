<?php

declare(strict_types=1);

namespace OCA\Files_External_Gdrive\Storage;

use Google\Client as GoogleClient;
use Google\Service\Drive as GoogleDriveService;
use Google\Service\Drive\DriveFile;
use Icewind\Streams\CallbackWrapper;
use Icewind\Streams\IteratorDirectory;
use OC\Files\Storage\Common;
use Psr\Log\LoggerInterface;

/**
 * Google Drive storage backend for Nextcloud.
 *
 * Extends \OC\Files\Storage\Common directly (the Flysystem base class
 * was removed in Nextcloud 23). All file operations go through the
 * Google Drive API v3.
 */
class GoogleDrive extends Common
{
    public const APP_NAME = 'files_external_gdrive';

    private const FOLDER_MIME = 'application/vnd.google-apps.folder';
    private const DOCUMENT_MIME = 'application/vnd.google-apps.document';
    private const SPREADSHEET_MIME = 'application/vnd.google-apps.spreadsheet';
    private const DRAWING_MIME = 'application/vnd.google-apps.drawing';
    private const PRESENTATION_MIME = 'application/vnd.google-apps.presentation';

    /** Google Docs export mapping: native mime -> export mime + extension */
    private const EXPORT_MAP = [
        self::DOCUMENT_MIME => ['mime' => 'application/vnd.oasis.opendocument.text', 'ext' => 'odt'],
        self::SPREADSHEET_MIME => ['mime' => 'application/vnd.oasis.opendocument.spreadsheet', 'ext' => 'ods'],
        self::DRAWING_MIME => ['mime' => 'image/jpeg', 'ext' => 'jpeg'],
        self::PRESENTATION_MIME => ['mime' => 'application/vnd.oasis.opendocument.presentation', 'ext' => 'odp'],
    ];

    private GoogleClient $client;
    private GoogleDriveService $service;
    private LoggerInterface $logger;
    private string $id;
    private string $root = 'root';

    /** @var array<string, DriveFile> path -> DriveFile cache */
    private array $fileCache = [];

    /** @var array<string, string> path -> Google Drive file ID cache */
    private array $idCache = [];

    public function __construct(array $params)
    {
        parent::__construct($params);

        // Client credentials may come from params (via manipulateStorageConfig)
        // or we fetch them from central app config directly
        $clientId = $params['client_id'] ?? '';
        $clientSecret = $params['client_secret'] ?? '';

        if (empty($clientId) || empty($clientSecret)) {
            $config = \OC::$server->get(\OCP\IConfig::class);
            $clientId = $config->getAppValue(self::APP_NAME, 'client_id', '');
            $clientSecret = $config->getAppValue(self::APP_NAME, 'client_secret', '');
        }

        $token = $params['token'] ?? '';
        $configured = $params['configured'] ?? '';

        if ($configured === 'false' || empty($token) || empty($clientId)) {
            throw new \Exception('Google Drive storage not yet configured');
        }

        $this->client = new GoogleClient([
            'retry' => ['retries' => 2],
        ]);
        $this->client->setClientId($clientId);
        $this->client->setClientSecret($clientSecret);
        $this->client->setScopes([GoogleDriveService::DRIVE]);
        $this->client->setAccessToken($token);

        // Handle token refresh and persist the new token
        if ($this->client->isAccessTokenExpired() && $this->client->getRefreshToken()) {
            $newToken = $this->client->fetchAccessTokenWithRefreshToken($this->client->getRefreshToken());
            if (!isset($newToken['error'])) {
                $this->persistRefreshedToken($params, json_encode($this->client->getAccessToken()));
            }
        }

        $this->service = new GoogleDriveService($this->client);

        // Use a stable storage ID that survives token refreshes.
        // Each user gets their own storage instance via the external storage
        // framework, so client_id alone is sufficient to identify the mount.
        $this->id = 'google::' . substr($params['client_id'], 0, 30);

        $this->logger = \OC::$server->get(LoggerInterface::class);
    }

    /**
     * Save the refreshed OAuth token back to the mount configuration
     * so subsequent requests use the new token.
     */
    private function persistRefreshedToken(array $params, string $newToken): void
    {
        try {
            $dbConfig = \OC::$server->get(\OCA\Files_External\Service\DBConfigService::class);
            $mountId = (int) ($params['mount_id'] ?? 0);

            if ($mountId > 0) {
                $dbConfig->setConfig($mountId, 'token', $newToken);
                return;
            }

            // Fallback: find the mount by storage class and user
            $mounts = $dbConfig->getAdminMountsFor(\OCA\Files_External\Service\DBConfigService::APPLICABLE_TYPE_GLOBAL, null);
            $userMounts = $dbConfig->getUserMountsFor(\OCA\Files_External\Service\DBConfigService::APPLICABLE_TYPE_USER, $params['user'] ?? '');
            foreach (array_merge($mounts, $userMounts) as $mount) {
                if (($mount['storage_backend'] ?? '') === 'files_external_gdrive') {
                    $dbConfig->setConfig((int) $mount['mount_id'], 'token', $newToken);
                    return;
                }
            }
        } catch (\Exception $e) {
            $this->logger->warning('Google Drive: failed to persist refreshed token', [
                'exception' => $e,
            ]);
        }
    }

    public function getId(): string
    {
        return $this->id;
    }

    // ──────────────────────────────────────────────
    //  Path resolution: Nextcloud path -> Drive file ID
    // ──────────────────────────────────────────────

    /**
     * Resolve a Nextcloud-style path to a Google Drive file ID.
     */
    private function getDriveId(string $path): ?string
    {
        $path = $this->normalizePath($path);

        if ($path === '' || $path === '/') {
            return $this->root;
        }

        if (isset($this->idCache[$path])) {
            return $this->idCache[$path];
        }

        $parts = explode('/', trim($path, '/'));
        $parentId = $this->root;

        foreach ($parts as $name) {
            $escapedName = str_replace("'", "\\'", $name);
            $query = sprintf(
                "'%s' in parents and name = '%s' and trashed = false",
                $parentId,
                $escapedName
            );

            $result = $this->service->files->listFiles([
                'q' => $query,
                'fields' => 'files(id, name, mimeType, size, modifiedTime, createdTime)',
                'pageSize' => 1,
            ]);

            $files = $result->getFiles();
            if (empty($files)) {
                return null;
            }

            $file = $files[0];
            $parentId = $file->getId();

            // Cache the intermediate results too
            $subPath = '/' . implode('/', array_slice($parts, 0, array_search($name, $parts) + 1));
            $this->idCache[$subPath] = $parentId;
            $this->fileCache[$subPath] = $file;
        }

        return $parentId;
    }

    /**
     * Get the DriveFile metadata object for a path.
     */
    private function getDriveFile(string $path): ?DriveFile
    {
        $path = $this->normalizePath($path);

        if (isset($this->fileCache[$path])) {
            return $this->fileCache[$path];
        }

        if ($path === '' || $path === '/') {
            // Root is a virtual directory
            return null;
        }

        $id = $this->getDriveId($path);
        if ($id === null) {
            return null;
        }

        if (isset($this->fileCache[$path])) {
            return $this->fileCache[$path];
        }

        try {
            $file = $this->service->files->get($id, [
                'fields' => 'id, name, mimeType, size, modifiedTime, createdTime',
            ]);
            $this->fileCache[$path] = $file;
            return $file;
        } catch (\Exception $e) {
            $this->logger->error('Google Drive: failed to get file metadata', [
                'path' => $path,
                'exception' => $e,
            ]);
            return null;
        }
    }

    private function normalizePath(string $path): string
    {
        $path = '/' . ltrim($path, '/');
        // Remove trailing slash except for root
        if ($path !== '/') {
            $path = rtrim($path, '/');
        }
        return $path;
    }

    private function getParentId(string $path): ?string
    {
        $parent = dirname($this->normalizePath($path));
        return $this->getDriveId($parent);
    }

    private function getBaseName(string $path): string
    {
        return basename($this->normalizePath($path));
    }

    /**
     * Strip Google Docs export extension from a filename if present.
     */
    private function getDisplayName(DriveFile $file): string
    {
        $name = $file->getName();
        $mimeType = $file->getMimeType();

        if (isset(self::EXPORT_MAP[$mimeType])) {
            $ext = self::EXPORT_MAP[$mimeType]['ext'];
            $name .= '.' . $ext;
        }

        return $name;
    }

    /**
     * Invalidate caches for a path and its parent.
     */
    private function invalidateCache(string $path): void
    {
        $path = $this->normalizePath($path);
        unset($this->idCache[$path], $this->fileCache[$path]);

        // Also invalidate parent directory listing cache
        $parent = dirname($path);
        unset($this->idCache[$parent], $this->fileCache[$parent]);
    }

    // ──────────────────────────────────────────────
    //  Storage interface implementation
    // ──────────────────────────────────────────────

    public function mkdir(string $path): bool
    {
        $parentId = $this->getParentId($path);
        if ($parentId === null) {
            return false;
        }

        try {
            $folder = new DriveFile([
                'name' => $this->getBaseName($path),
                'mimeType' => self::FOLDER_MIME,
                'parents' => [$parentId],
            ]);

            $created = $this->service->files->create($folder, [
                'fields' => 'id, name, mimeType, modifiedTime',
            ]);

            $normalized = $this->normalizePath($path);
            $this->idCache[$normalized] = $created->getId();
            $this->fileCache[$normalized] = $created;

            return true;
        } catch (\Exception $e) {
            $this->logger->error('Google Drive: mkdir failed', [
                'path' => $path,
                'exception' => $e,
            ]);
            return false;
        }
    }

    public function rmdir(string $path): bool
    {
        return $this->deleteFile($path);
    }

    public function opendir(string $path)
    {
        $dirId = $this->getDriveId($path);
        if ($dirId === null) {
            return false;
        }

        try {
            $children = [];
            $usedNames = [];
            $pageToken = null;

            do {
                $params = [
                    'q' => sprintf("'%s' in parents and trashed = false", $dirId),
                    'fields' => 'nextPageToken, files(id, name, mimeType, size, modifiedTime, createdTime)',
                    'pageSize' => 1000,
                ];

                if ($pageToken !== null) {
                    $params['pageToken'] = $pageToken;
                }

                $result = $this->service->files->listFiles($params);

                foreach ($result->getFiles() as $file) {
                    $displayName = $this->getDisplayName($file);

                    // Google Drive allows duplicate filenames — Nextcloud does not.
                    // Skip duplicates to avoid filecache insert errors.
                    if (isset($usedNames[$displayName])) {
                        continue;
                    }
                    $usedNames[$displayName] = true;

                    $children[] = $displayName;

                    // Cache child metadata
                    $childPath = $this->normalizePath($path . '/' . $displayName);
                    $this->idCache[$childPath] = $file->getId();
                    $this->fileCache[$childPath] = $file;
                }

                $pageToken = $result->getNextPageToken();
            } while ($pageToken !== null);

            return IteratorDirectory::wrap($children);
        } catch (\Exception $e) {
            $this->logger->error('Google Drive: opendir failed', [
                'path' => $path,
                'exception' => $e,
            ]);
            return false;
        }
    }

    public function filetype(string $path): string|false
    {
        if ($this->normalizePath($path) === '/') {
            return 'dir';
        }

        $file = $this->getDriveFile($path);
        if ($file === null) {
            return false;
        }

        return $file->getMimeType() === self::FOLDER_MIME ? 'dir' : 'file';
    }

    public function file_exists(string $path): bool
    {
        if ($this->normalizePath($path) === '/') {
            return true;
        }

        return $this->getDriveId($path) !== null;
    }

    public function unlink(string $path): bool
    {
        return $this->deleteFile($path);
    }

    private function deleteFile(string $path): bool
    {
        $id = $this->getDriveId($path);
        if ($id === null) {
            return false;
        }

        try {
            // Move to trash instead of permanent delete for safety
            $update = new DriveFile(['trashed' => true]);
            $this->service->files->update($id, $update);
            $this->invalidateCache($path);
            return true;
        } catch (\Exception $e) {
            $this->logger->error('Google Drive: delete failed', [
                'path' => $path,
                'exception' => $e,
            ]);
            return false;
        }
    }

    public function fopen(string $path, string $mode)
    {
        $normalizedPath = $this->normalizePath($path);

        switch ($mode) {
            case 'r':
            case 'rb':
                return $this->readStream($normalizedPath);

            case 'w':
            case 'wb':
            case 'w+':
            case 'wb+':
                return $this->openWriteStream($normalizedPath);

            case 'a':
            case 'ab':
            case 'a+':
                // Google Drive doesn't support append; read existing, write all
                return $this->appendStream($normalizedPath);

            default:
                return false;
        }
    }

    private function readStream(string $path): mixed
    {
        $file = $this->getDriveFile($path);
        if ($file === null) {
            return false;
        }

        try {
            $mimeType = $file->getMimeType();

            if (isset(self::EXPORT_MAP[$mimeType])) {
                // Google Docs must be exported
                $exportMime = self::EXPORT_MAP[$mimeType]['mime'];
                $response = $this->service->files->export($file->getId(), $exportMime, [
                    'alt' => 'media',
                ]);
            } else {
                $response = $this->service->files->get($file->getId(), [
                    'alt' => 'media',
                ]);
            }

            $body = $response->getBody();
            $stream = fopen('php://temp', 'r+');
            fwrite($stream, $body->getContents());
            rewind($stream);
            return $stream;
        } catch (\Exception $e) {
            $this->logger->error('Google Drive: read failed', [
                'path' => $path,
                'exception' => $e,
            ]);
            return false;
        }
    }

    private function openWriteStream(string $path): mixed
    {
        $tmpFile = \OC::$server->getTempManager()->getTemporaryFile();
        $handle = fopen($tmpFile, 'w+b');

        return CallbackWrapper::wrap($handle, null, null, function () use ($path, $tmpFile) {
            $this->uploadFile($path, $tmpFile);
            unlink($tmpFile);
        });
    }

    private function appendStream(string $path): mixed
    {
        $tmpFile = \OC::$server->getTempManager()->getTemporaryFile();

        // Copy existing content if file exists
        $existingStream = $this->readStream($path);
        if ($existingStream !== false) {
            $handle = fopen($tmpFile, 'w+b');
            stream_copy_to_stream($existingStream, $handle);
            fclose($existingStream);
            fclose($handle);
        }

        $handle = fopen($tmpFile, 'a+b');

        return CallbackWrapper::wrap($handle, null, null, function () use ($path, $tmpFile) {
            $this->uploadFile($path, $tmpFile);
            unlink($tmpFile);
        });
    }

    private function uploadFile(string $path, string $localFile): void
    {
        $parentId = $this->getParentId($path);
        if ($parentId === null) {
            throw new \Exception('Parent directory not found for: ' . $path);
        }

        $existingId = $this->getDriveId($path);
        $mimeType = \OC::$server->getMimeTypeDetector()->detectPath($path);

        try {
            $fileMetadata = new DriveFile([
                'name' => $this->getBaseName($path),
            ]);

            $content = file_get_contents($localFile);

            if ($existingId !== null) {
                // Update existing file
                $this->service->files->update($existingId, $fileMetadata, [
                    'data' => $content,
                    'mimeType' => $mimeType,
                    'uploadType' => 'multipart',
                    'fields' => 'id, name, mimeType, size, modifiedTime',
                ]);
            } else {
                // Create new file
                $fileMetadata->setParents([$parentId]);
                $this->service->files->create($fileMetadata, [
                    'data' => $content,
                    'mimeType' => $mimeType,
                    'uploadType' => 'multipart',
                    'fields' => 'id, name, mimeType, size, modifiedTime',
                ]);
            }

            $this->invalidateCache($path);
        } catch (\Exception $e) {
            $this->logger->error('Google Drive: upload failed', [
                'path' => $path,
                'exception' => $e,
            ]);
            throw $e;
        }
    }

    public function stat(string $path): array|false
    {
        if ($this->normalizePath($path) === '/') {
            return [
                'mtime' => 0,
                'size' => 0,
            ];
        }

        $file = $this->getDriveFile($path);
        if ($file === null) {
            return false;
        }

        $mtime = $file->getModifiedTime()
            ? strtotime($file->getModifiedTime())
            : 0;

        return [
            'mtime' => $mtime,
            'size' => (int) ($file->getSize() ?? 0),
        ];
    }

    public function getMimeType(string $path): string|false
    {
        if ($this->normalizePath($path) === '/') {
            return 'httpd/unix-directory';
        }

        $file = $this->getDriveFile($path);
        if ($file === null) {
            return false;
        }

        $mimeType = $file->getMimeType();

        if ($mimeType === self::FOLDER_MIME) {
            return 'httpd/unix-directory';
        }

        if (isset(self::EXPORT_MAP[$mimeType])) {
            return self::EXPORT_MAP[$mimeType]['mime'];
        }

        return $mimeType;
    }

    public function free_space(string $path): int|float|false
    {
        try {
            $about = $this->service->about->get(['fields' => 'storageQuota']);
            $quota = $about->getStorageQuota();

            $limit = (int) $quota->getLimit();
            $usage = (int) $quota->getUsage();

            if ($limit === 0) {
                // Unlimited storage (e.g., Google Workspace)
                return \OCP\Files\FileInfo::SPACE_UNLIMITED;
            }

            return $limit - $usage;
        } catch (\Exception $e) {
            $this->logger->error('Google Drive: free_space failed', ['exception' => $e]);
            return false;
        }
    }

    public function touch(string $path, ?int $mtime = null): bool
    {
        if (!$this->file_exists($path)) {
            // Create empty file
            $parentId = $this->getParentId($path);
            if ($parentId === null) {
                return false;
            }

            try {
                $fileMetadata = new DriveFile([
                    'name' => $this->getBaseName($path),
                    'parents' => [$parentId],
                ]);

                $this->service->files->create($fileMetadata, [
                    'data' => '',
                    'mimeType' => 'application/octet-stream',
                    'uploadType' => 'multipart',
                    'fields' => 'id, name, mimeType, size, modifiedTime',
                ]);

                $this->invalidateCache($path);
                return true;
            } catch (\Exception $e) {
                $this->logger->error('Google Drive: touch (create) failed', [
                    'path' => $path,
                    'exception' => $e,
                ]);
                return false;
            }
        }

        if ($mtime !== null) {
            $id = $this->getDriveId($path);
            if ($id === null) {
                return false;
            }

            try {
                $update = new DriveFile([
                    'modifiedTime' => date('Y-m-d\TH:i:s.000\Z', $mtime),
                ]);
                $this->service->files->update($id, $update, [
                    'fields' => 'id, modifiedTime',
                ]);
                $this->invalidateCache($path);
                return true;
            } catch (\Exception $e) {
                $this->logger->error('Google Drive: touch (mtime) failed', [
                    'path' => $path,
                    'exception' => $e,
                ]);
                return false;
            }
        }

        return true;
    }

    public function rename(string $source, string $target): bool
    {
        $sourceId = $this->getDriveId($source);
        if ($sourceId === null) {
            return false;
        }

        $sourceParentId = $this->getParentId($source);
        $targetParentId = $this->getParentId($target);

        if ($targetParentId === null) {
            return false;
        }

        try {
            $update = new DriveFile([
                'name' => $this->getBaseName($target),
            ]);

            $params = [
                'fields' => 'id, name, mimeType, size, modifiedTime',
            ];

            // If moving to a different parent, set addParents/removeParents
            if ($sourceParentId !== $targetParentId) {
                $params['addParents'] = $targetParentId;
                $params['removeParents'] = $sourceParentId;
            }

            $this->service->files->update($sourceId, $update, $params);

            $this->invalidateCache($source);
            $this->invalidateCache($target);

            return true;
        } catch (\Exception $e) {
            $this->logger->error('Google Drive: rename failed', [
                'source' => $source,
                'target' => $target,
                'exception' => $e,
            ]);
            return false;
        }
    }

    public function copy(string $source, string $target): bool
    {
        $sourceId = $this->getDriveId($source);
        if ($sourceId === null) {
            return false;
        }

        $targetParentId = $this->getParentId($target);
        if ($targetParentId === null) {
            return false;
        }

        try {
            $copyMetadata = new DriveFile([
                'name' => $this->getBaseName($target),
                'parents' => [$targetParentId],
            ]);

            $this->service->files->copy($sourceId, $copyMetadata, [
                'fields' => 'id, name, mimeType, size, modifiedTime',
            ]);

            $this->invalidateCache($target);

            return true;
        } catch (\Exception $e) {
            $this->logger->error('Google Drive: copy failed', [
                'source' => $source,
                'target' => $target,
                'exception' => $e,
            ]);
            return false;
        }
    }

    public function test(): bool
    {
        try {
            $about = $this->service->about->get(['fields' => 'storageQuota']);
            return $about->getStorageQuota() !== null;
        } catch (\Exception $e) {
            $this->logger->error('Google Drive: connection test failed', ['exception' => $e]);
            return false;
        }
    }

    public function hasUpdated(string $path, int $time): bool
    {
        $file = $this->getDriveFile($path);
        if ($file === null) {
            return false;
        }

        $mtime = $file->getModifiedTime()
            ? strtotime($file->getModifiedTime())
            : 0;

        return $mtime > $time;
    }
}
