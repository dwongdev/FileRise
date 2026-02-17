<?php

declare(strict_types=1);

namespace FileRise\Domain;

use FileRise\Http\Controllers\FolderController;
use FileRise\Support\CryptoAtRest;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

require_once PROJECT_ROOT . '/config/config.php';
require_once PROJECT_ROOT . '/src/lib/CryptoAtRest.php';

final class FolderEncryptionService
{
    private const FILE_SCAN_LIMIT = 20000;
    private const ENCRYPTED_SCAN_LIMIT = 40000;

    public static function normalizeFolder(string $folder): string
    {
        $folder = str_replace('\\', '/', trim($folder));
        return ($folder === '' || strcasecmp($folder, 'root') === 0)
            ? 'root'
            : trim($folder, '/');
    }

    public static function apply(string $folder, bool $encrypted, string $username): array
    {
        $folder = self::normalizeFolder($folder);

        if ($folder !== 'root' && !preg_match(REGEX_FOLDER_NAME, $folder)) {
            throw new RuntimeException('Invalid folder name.', 400);
        }

        $dir = self::resolveFolderPath($folder);
        self::assertPermissions($folder, $encrypted, $username);

        if ($encrypted) {
            if (self::hasAnyFile($dir, $folder)) {
                throw new RuntimeException(
                    'Folder is not empty. v1 encryption can only be enabled on an empty folder tree (move files out first).',
                    409
                );
            }
        } else {
            if (self::hasEncryptedFile($dir)) {
                throw new RuntimeException(
                    'Folder still contains encrypted files. Move them out (to decrypt) before disabling folder encryption.',
                    409
                );
            }
        }

        $res = FolderCrypto::setEncrypted($folder, $encrypted, $username);
        if (empty($res['ok'])) {
            throw new RuntimeException((string)($res['error'] ?? 'Failed to update encryption state.'), 500);
        }

        return $res;
    }

    private static function resolveFolderPath(string $folder): string
    {
        $base = realpath((string)UPLOAD_DIR);
        if ($base === false) {
            throw new RuntimeException('Server misconfiguration.', 500);
        }

        if ($folder === 'root') {
            $dir = $base;
        } else {
            $guess = rtrim((string)UPLOAD_DIR, "/\\")
                . DIRECTORY_SEPARATOR
                . str_replace('/', DIRECTORY_SEPARATOR, $folder);
            $dir = realpath($guess);
        }

        if ($dir === false || !is_dir($dir) || strpos($dir, $base) !== 0) {
            throw new RuntimeException('Folder not found.', 404);
        }

        return $dir;
    }

    private static function assertPermissions(string $folder, bool $encrypted, string $username): void
    {
        $caps = FolderController::capabilities($folder, $username);
        $encCaps = (is_array($caps) && isset($caps['encryption']) && is_array($caps['encryption']))
            ? $caps['encryption']
            : [];

        $canEncrypt = !empty($encCaps['canEncrypt']);
        $canDecrypt = !empty($encCaps['canDecrypt']);

        if ($encrypted && !$canEncrypt) {
            throw new RuntimeException('Forbidden: cannot enable encryption for this folder.', 403);
        }
        if (!$encrypted && !$canDecrypt) {
            throw new RuntimeException('Forbidden: cannot disable encryption for this folder.', 403);
        }
    }

    private static function hasAnyFile(string $rootDir, string $folder): bool
    {
        $skipDirs = ['trash', 'profile_pics', '@eadir'];
        if ($folder !== 'root') {
            // Legacy compatibility placeholder: resumable_ checks are handled separately.
            $skipDirs[] = null;
        }

        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($rootDir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        $seen = 0;
        foreach ($it as $info) {
            if (++$seen > self::FILE_SCAN_LIMIT) {
                break;
            }
            $name = $info->getFilename();
            if ($name === '' || $name[0] === '.') {
                continue;
            }

            $lower = strtolower($name);
            if (in_array($lower, $skipDirs, true)) {
                if ($info->isDir()) {
                    $it->next();
                }
                continue;
            }
            if (str_starts_with($lower, 'resumable_')) {
                continue;
            }
            if ($info->isFile()) {
                return true;
            }
        }

        return false;
    }

    private static function hasEncryptedFile(string $rootDir): bool
    {
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($rootDir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        $seen = 0;
        foreach ($it as $info) {
            if (++$seen > self::ENCRYPTED_SCAN_LIMIT) {
                break;
            }
            if (!$info->isFile()) {
                continue;
            }

            $name = $info->getFilename();
            if ($name === '' || $name[0] === '.') {
                continue;
            }

            $lower = strtolower($name);
            if ($lower === 'trash' || $lower === 'profile_pics') {
                continue;
            }
            if (str_starts_with($lower, 'resumable_')) {
                continue;
            }

            try {
                if (CryptoAtRest::isEncryptedFile($info->getPathname())) {
                    return true;
                }
            } catch (\Throwable $e) {
                // Best effort: ignore probe errors.
            }
        }

        return false;
    }
}
