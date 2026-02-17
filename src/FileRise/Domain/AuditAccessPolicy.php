<?php

declare(strict_types=1);

namespace FileRise\Domain;

use FileRise\Support\ACL;
use RuntimeException;

require_once PROJECT_ROOT . '/config/config.php';
require_once PROJECT_ROOT . '/src/lib/ACL.php';

final class AuditAccessPolicy
{
    /**
     * Normalize API folder query into ProAudit filter format.
     *
     * Returns empty string for root/no-filter.
     */
    public static function normalizeFolderFilter(?string $folder): string
    {
        $folder = trim(str_replace('\\', '/', (string)$folder));
        return ($folder === '' || strcasecmp($folder, 'root') === 0)
            ? ''
            : trim($folder, '/');
    }

    /**
     * Ensure caller can read audit entries for the selected folder.
     */
    public static function assertAuditFolderReadable(string $folder, string $username, array $perms): void
    {
        if (ACL::isAdmin($perms)) {
            return;
        }

        if ($folder === '') {
            throw new RuntimeException('folder_required', 403);
        }
        if (!preg_match(REGEX_FOLDER_NAME, $folder)) {
            throw new RuntimeException('Invalid folder name.', 400);
        }

        $canManage = ACL::canManage($username, $perms, $folder);
        $ownsPath = ACL::ownsFolderOrAncestor($username, $perms, $folder);

        if (!$canManage && !$ownsPath) {
            throw new RuntimeException('Forbidden', 403);
        }
    }

    /**
     * @param array<string,mixed> $query
     * @return array<string,string>
     */
    public static function buildFilters(array $query, string $folder): array
    {
        return [
            'user' => isset($query['user']) ? (string)$query['user'] : '',
            'action' => isset($query['action']) ? (string)$query['action'] : '',
            'source' => isset($query['source']) ? (string)$query['source'] : '',
            'storage' => isset($query['storage']) ? (string)$query['storage'] : '',
            'folder' => $folder,
            'from' => isset($query['from']) ? (string)$query['from'] : '',
            'to' => isset($query['to']) ? (string)$query['to'] : '',
        ];
    }
}
