<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

namespace LiteSpeed\Cache\Update;

class ModuleUpdater
{
    private const GITHUB_API_URL = 'https://api.github.com/repos/litespeedtech/lscache_prestashop/releases';
    private const CACHE_KEY = 'LITESPEED_GITHUB_RELEASES';
    private const CACHE_TTL = 3600;
    private const MAX_BACKUPS = 5;

    private string $modulePath;
    private string $backupDir;

    public function __construct(string $modulePath)
    {
        $this->modulePath = rtrim($modulePath, '/') . '/';
        $this->backupDir = _PS_ROOT_DIR_ . '/var/litespeedcache/backups/';
    }

    public function getAvailableReleases(bool $forceRefresh = false): array
    {
        $cached = \Configuration::getGlobalValue(self::CACHE_KEY);
        if (!$forceRefresh && $cached) {
            $data = json_decode($cached, true);
            if (is_array($data) && !empty($data['ts']) && (time() - $data['ts']) < self::CACHE_TTL) {
                return $data['releases'];
            }
        }

        $releases = $this->fetchReleasesFromGitHub();
        if ($releases !== null) {
            \Configuration::updateGlobalValue(self::CACHE_KEY, json_encode([
                'ts' => time(),
                'releases' => $releases,
            ]));
            return $releases;
        }

        // Return stale cache if GitHub is unreachable
        if ($cached) {
            $data = json_decode($cached, true);
            if (is_array($data) && !empty($data['releases'])) {
                return $data['releases'];
            }
        }

        return [];
    }

    private function fetchReleasesFromGitHub(): ?array
    {
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => "User-Agent: LiteSpeedCache-PrestaShop\r\nAccept: application/vnd.github.v3+json\r\n",
                'timeout' => 10,
            ],
            'ssl' => [
                'verify_peer' => true,
            ],
        ]);

        $response = @file_get_contents(self::GITHUB_API_URL, false, $context);
        if ($response === false) {
            return null;
        }

        $data = json_decode($response, true);
        if (!is_array($data)) {
            return null;
        }

        $releases = [];
        foreach ($data as $release) {
            if (!empty($release['draft'])) {
                continue;
            }

            $downloadUrl = $release['zipball_url'] ?? '';
            $isAsset = false;

            // Prefer attached zip asset over zipball
            if (!empty($release['assets'])) {
                foreach ($release['assets'] as $asset) {
                    if (str_ends_with($asset['name'] ?? '', '.zip')) {
                        $downloadUrl = $asset['browser_download_url'];
                        $isAsset = true;
                        break;
                    }
                }
            }

            $releases[] = [
                'tag_name'     => $release['tag_name'] ?? '',
                'name'         => $release['name'] ?? $release['tag_name'] ?? '',
                'published_at' => $release['published_at'] ?? '',
                'body'         => $release['body'] ?? '',
                'download_url' => $downloadUrl,
                'is_asset'     => $isAsset,
                'prerelease'   => !empty($release['prerelease']),
            ];
        }

        return $releases;
    }

    public static function cleanTag(string $tag): string
    {
        // Handle formats like "v1.6.0", "v.1.5.3", "1.5.0"
        return ltrim($tag, 'v.');
    }

    public function classifyRelease(string $tag, string $currentVersion): string
    {
        $clean = self::cleanTag($tag);
        $cmp = version_compare($clean, $currentVersion);
        if ($cmp === 0) {
            return 'current';
        }
        return $cmp > 0 ? 'newer' : 'older';
    }

    // ---- Backups ----------------------------------------------------------------

    public function getBackups(): array
    {
        if (!is_dir($this->backupDir)) {
            return [];
        }

        $backups = [];
        foreach (new \DirectoryIterator($this->backupDir) as $file) {
            if ($file->isDot() || $file->getExtension() !== 'zip') {
                continue;
            }
            // Parse version from filename: litespeedcache-v1.2.3-20260321_120000.zip
            $version = '-';
            if (preg_match('/litespeedcache-v(.+?)-\d{8}_\d{6}\.zip/', $file->getFilename(), $m)) {
                $version = $m[1];
            }
            $backups[] = [
                'file'    => $file->getFilename(),
                'version' => $version,
                'date'    => date('Y-m-d H:i:s', $file->getMTime()),
                'size'    => round($file->getSize() / 1048576, 2), // MB
            ];
        }

        usort($backups, fn($a, $b) => strcmp($b['date'], $a['date']));
        return $backups;
    }

    public function createBackup(string $currentVersion): string
    {
        if (!is_dir($this->backupDir)) {
            mkdir($this->backupDir, 0755, true);
        }

        $filename = 'litespeedcache-v' . $currentVersion . '-' . date('Ymd_His') . '.zip';
        $zipPath = $this->backupDir . $filename;

        $zip = new \ZipArchive();
        if ($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('Cannot create backup zip: ' . $zipPath);
        }

        $this->addDirectoryToZip($zip, $this->modulePath, 'litespeedcache/');
        $zip->close();

        $this->pruneOldBackups();

        return $filename;
    }

    private function addDirectoryToZip(\ZipArchive $zip, string $dir, string $prefix): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $item) {
            $relativePath = $prefix . substr($item->getPathname(), strlen($dir));

            // Skip vendor, node_modules, .git
            if (preg_match('#/(vendor|node_modules|\.git)/#', $relativePath)) {
                continue;
            }

            if ($item->isDir()) {
                $zip->addEmptyDir($relativePath);
            } else {
                $zip->addFile($item->getPathname(), $relativePath);
            }
        }
    }

    private function pruneOldBackups(): void
    {
        $backups = $this->getBackups();
        if (count($backups) <= self::MAX_BACKUPS) {
            return;
        }

        $toDelete = array_slice($backups, self::MAX_BACKUPS);
        foreach ($toDelete as $backup) {
            @unlink($this->backupDir . $backup['file']);
        }
    }

    // ---- Update -----------------------------------------------------------------

    public function updateToRelease(array $release, string $currentVersion): array
    {
        if (empty($release['download_url'])) {
            return ['success' => false, 'message' => 'No download URL available for this release.'];
        }

        // Check ZipArchive
        if (!class_exists('ZipArchive')) {
            return ['success' => false, 'message' => 'PHP ZipArchive extension is required.'];
        }

        // Check write permissions
        if (!is_writable($this->modulePath)) {
            return ['success' => false, 'message' => 'Module directory is not writable.'];
        }

        // Download release zip
        $tmpFile = $this->downloadFile($release['download_url']);
        if (!$tmpFile) {
            return ['success' => false, 'message' => 'Failed to download release from GitHub.'];
        }

        try {
            // Create backup first
            $backupFile = $this->createBackup($currentVersion);

            // Extract
            if (!$this->extractModuleZip($tmpFile, $release['is_asset'] ?? false)) {
                return [
                    'success' => false,
                    'message' => 'Failed to extract release. A backup was created: ' . $backupFile,
                    'backup' => $backupFile,
                ];
            }

            // Clear caches
            \Tools::clearSf2Cache();
            if (function_exists('opcache_reset')) {
                @opcache_reset();
            }

            return [
                'success' => true,
                'message' => 'Module updated to ' . $release['tag_name'] . '. Backup created: ' . $backupFile,
                'backup' => $backupFile,
            ];
        } finally {
            @unlink($tmpFile);
        }
    }

    private function downloadFile(string $url): ?string
    {
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => "User-Agent: LiteSpeedCache-PrestaShop\r\n",
                'timeout' => 60,
                'follow_location' => true,
            ],
            'ssl' => [
                'verify_peer' => true,
            ],
        ]);

        $tmpFile = tempnam(sys_get_temp_dir(), 'lsc_update_');
        $content = @file_get_contents($url, false, $context);
        if ($content === false) {
            @unlink($tmpFile);
            return null;
        }

        file_put_contents($tmpFile, $content);
        return $tmpFile;
    }

    private function extractModuleZip(string $zipPath, bool $isAsset): bool
    {
        $zip = new \ZipArchive();
        if ($zip->open($zipPath) !== true) {
            return false;
        }

        // Detect root folder inside zip
        $rootPrefix = '';
        $firstEntry = $zip->getNameIndex(0);
        if ($firstEntry && str_contains($firstEntry, '/')) {
            $rootPrefix = explode('/', $firstEntry)[0] . '/';
        }

        // If asset zip, root should be "litespeedcache/"
        // If zipball, root is "user-repo-hash/"
        $skipPrefixLen = strlen($rootPrefix);

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entryName = $zip->getNameIndex($i);
            $relativePath = substr($entryName, $skipPrefixLen);

            if ($relativePath === '') {
                continue;
            }

            // Skip files we don't want to overwrite
            if (preg_match('#^(\.git/|README\.md$|composer\.lock$|\.github/)#', $relativePath)) {
                continue;
            }

            $targetPath = $this->modulePath . $relativePath;

            if (str_ends_with($entryName, '/')) {
                // Directory
                if (!is_dir($targetPath)) {
                    mkdir($targetPath, 0755, true);
                }
            } else {
                // File
                $dir = dirname($targetPath);
                if (!is_dir($dir)) {
                    mkdir($dir, 0755, true);
                }
                $content = $zip->getFromIndex($i);
                if ($content !== false) {
                    file_put_contents($targetPath, $content);
                }
            }
        }

        $zip->close();
        return true;
    }

    // ---- Rollback ---------------------------------------------------------------

    public function rollbackFromBackup(string $backupFile): array
    {
        $backupPath = $this->backupDir . basename($backupFile);

        if (!is_file($backupPath)) {
            return ['success' => false, 'message' => 'Backup file not found: ' . $backupFile];
        }

        $zip = new \ZipArchive();
        if ($zip->open($backupPath) !== true) {
            return ['success' => false, 'message' => 'Cannot open backup file.'];
        }

        // Clear current module files (keep backups dir)
        $this->cleanModuleDir();

        // Extract backup
        $zip->extractTo(dirname($this->modulePath));
        $zip->close();

        \Tools::clearSf2Cache();
        if (function_exists('opcache_reset')) {
            @opcache_reset();
        }

        return ['success' => true, 'message' => 'Restored from backup: ' . $backupFile];
    }

    private function cleanModuleDir(): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->modulePath, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $item) {
            // Don't delete the backups directory reference or .git
            $rel = substr($item->getPathname(), strlen($this->modulePath));
            if (str_starts_with($rel, '.git')) {
                continue;
            }

            if ($item->isDir()) {
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }
    }

    public function deleteBackup(string $backupFile): bool
    {
        $path = $this->backupDir . basename($backupFile);
        if (is_file($path)) {
            return unlink($path);
        }
        return false;
    }
}
