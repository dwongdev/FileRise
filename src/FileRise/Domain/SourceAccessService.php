<?php

declare(strict_types=1);

namespace FileRise\Domain;

use FileRise\Storage\SourceContext;
use FileRise\Storage\SourcesConfig;
use FileRise\Support\ACL;
use RuntimeException;

require_once PROJECT_ROOT . '/config/config.php';
require_once PROJECT_ROOT . '/src/lib/ACL.php';
require_once PROJECT_ROOT . '/src/lib/SourceContext.php';
require_once PROJECT_ROOT . '/src/lib/SourcesConfig.php';

final class SourceAccessService
{
    private const SOURCE_ID_REGEX = '/^[A-Za-z0-9_-]{1,64}$/';

    public static function isValidSourceId(string $id): bool
    {
        return (bool)preg_match(self::SOURCE_ID_REGEX, $id);
    }

    /**
     * Load per-user permissions from legacy function or Domain model.
     */
    public static function loadUserPermissions(string $username): array
    {
        $username = (string)$username;
        $perms = [];

        try {
            if (function_exists('loadUserPermissions')) {
                $p = loadUserPermissions($username);
                return is_array($p) ? $p : [];
            }

            if (class_exists(UserModel::class) && method_exists(UserModel::class, 'getUserPermissions')) {
                $all = UserModel::getUserPermissions();
                if (is_array($all)) {
                    if (isset($all[$username])) {
                        $perms = (array)$all[$username];
                    } else {
                        $lk = strtolower($username);
                        if (isset($all[$lk])) {
                            $perms = (array)$all[$lk];
                        }
                    }
                }
            }
        } catch (\Throwable $e) {
            return [];
        }

        return $perms;
    }

    /**
     * Validate a selected source for /api/pro/sources/select.php.
     *
     * @return array<string,mixed>
     */
    public static function requireSelectableSource(string $id): array
    {
        $id = trim($id);
        if ($id === '' || !self::isValidSourceId($id)) {
            throw new RuntimeException('Invalid source id', 400);
        }

        $cfg = SourcesConfig::getConfig();
        if (empty($cfg['enabled'])) {
            throw new RuntimeException('Sources are not enabled', 400);
        }

        $source = SourcesConfig::getSource($id);
        if (!$source || empty($source['enabled'])) {
            throw new RuntimeException('Source not found', 404);
        }

        return $source;
    }

    /**
     * Check whether a user has any root access in the target source.
     */
    public static function userCanAccessSourceRoot(string $sourceId, string $username, array $perms): bool
    {
        $sourceId = trim($sourceId);
        if ($sourceId === '') {
            return ACL::userHasAnyAccess($username, $perms, 'root');
        }

        if (!class_exists('SourceContext')) {
            return ACL::userHasAnyAccess($username, $perms, 'root');
        }

        $originalId = SourceContext::getActiveId();
        SourceContext::setActiveId($sourceId, false);
        try {
            return ACL::userHasAnyAccess($username, $perms, 'root');
        } finally {
            // Preserve legacy behavior: only restore when there was a concrete previous id.
            if ($originalId !== '') {
                SourceContext::setActiveId($originalId, false);
            }
        }
    }

    /**
     * Filter source list to what the user can read at root.
     *
     * @param array<int,mixed> $sources
     * @return array<int,mixed>
     */
    public static function filterVisibleSources(array $sources, string $username, array $perms): array
    {
        $visible = [];

        if (!class_exists('SourceContext')) {
            foreach ($sources as $src) {
                if (is_array($src) && ACL::userHasAnyAccess($username, $perms, 'root')) {
                    $visible[] = $src;
                }
            }
            return $visible;
        }

        $originalId = SourceContext::getActiveId();
        try {
            foreach ($sources as $src) {
                if (!is_array($src)) {
                    continue;
                }
                $id = (string)($src['id'] ?? '');
                if ($id === '') {
                    continue;
                }

                SourceContext::setActiveId($id, false);
                if (ACL::userHasAnyAccess($username, $perms, 'root')) {
                    $visible[] = $src;
                }
            }
        } finally {
            if ($originalId !== '') {
                SourceContext::setActiveId($originalId, false);
            }
        }

        return $visible;
    }

    /**
     * Validate source id for local-only storage explorer operations.
     *
     * @return array{id:string,source:array<string,mixed>}
     */
    public static function requireLocalExplorerSource(string $sourceId): array
    {
        $sourceId = trim($sourceId);
        if ($sourceId === '') {
            throw new RuntimeException('Invalid source id.', 400);
        }

        if (!class_exists('SourceContext') || !SourceContext::sourcesEnabled()) {
            throw new RuntimeException('Invalid source id.', 400);
        }

        if (!self::isValidSourceId($sourceId)) {
            throw new RuntimeException('Invalid source id.', 400);
        }

        $src = SourceContext::getSourceById($sourceId);
        if (!$src || empty($src['enabled'])) {
            throw new RuntimeException('Invalid source id.', 400);
        }

        $type = strtolower((string)($src['type'] ?? 'local'));
        if ($type !== 'local') {
            throw new RuntimeException('Storage explorer is only available for local sources.', 400);
        }

        return [
            'id' => $sourceId,
            'source' => $src,
        ];
    }

    /**
     * Run a callback inside a temporary local source context for storage explorer actions.
     *
     * @template T
     * @param callable():T $fn
     * @return T
     */
    public static function withLocalExplorerSource(string $sourceId, callable $fn)
    {
        $ctx = self::requireLocalExplorerSource($sourceId);
        $id = (string)$ctx['id'];

        if (!class_exists('SourceContext')) {
            return $fn();
        }

        $prevSourceId = SourceContext::getActiveId();
        SourceContext::setActiveId($id, false, true);
        try {
            return $fn();
        } finally {
            SourceContext::setActiveId($prevSourceId, false, true);
        }
    }
}
