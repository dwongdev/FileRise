<?php

declare(strict_types=1);

namespace FileRise\Storage;

use Throwable;

require_once PROJECT_ROOT . '/src/lib/StorageFactory.php';
require_once PROJECT_ROOT . '/src/lib/SourceContext.php';

final class SourceHealth
{
    private static function randomToken(int $len = 6): string
    {
        $size = max(4, $len);
        try {
            return substr(bin2hex(random_bytes($size)), 0, $size);
        } catch (Throwable $e) {
            return substr(md5((string)microtime(true) . ':' . (string)mt_rand()), 0, $size);
        }
    }

    private static function joinPath(string $base, string $child): string
    {
        $left = rtrim($base, "/\\");
        $right = ltrim($child, "/\\");
        if ($left === '') {
            return $right;
        }
        if ($right === '') {
            return $left;
        }
        return $left . DIRECTORY_SEPARATOR . $right;
    }

    private static function adapterError($adapter): string
    {
        if (is_object($adapter) && method_exists($adapter, 'getLastError')) {
            $detail = trim((string)$adapter->getLastError());
            if ($detail !== '') {
                return $detail;
            }
        }
        return '';
    }

    private static function addCheck(array &$checks, string $key, string $label, string $state, string $message = ''): void
    {
        $normalizedState = in_array($state, ['ok', 'fail', 'skipped', 'unknown'], true) ? $state : 'unknown';
        $checks[] = [
            'key' => $key,
            'label' => $label,
            'state' => $normalizedState,
            'message' => trim($message),
        ];
    }

    private static function stateToBool(string $state): ?bool
    {
        if ($state === 'ok') {
            return true;
        }
        if ($state === 'fail') {
            return false;
        }
        return null;
    }

    private static function findCheck(array $checks, string $key): ?array
    {
        foreach ($checks as $check) {
            if (!is_array($check)) {
                continue;
            }
            if (trim((string)($check['key'] ?? '')) === $key) {
                return $check;
            }
        }
        return null;
    }

    private static function sourceRoot(array $source): string
    {
        $type = strtolower((string)($source['type'] ?? 'local'));
        $cfg = isset($source['config']) && is_array($source['config']) ? $source['config'] : [];
        if ($type === 'local') {
            $path = trim((string)($cfg['path'] ?? $cfg['root'] ?? ''));
            if ($path !== '') {
                $trimmed = rtrim($path, "/\\");
                return ($trimmed === '') ? (string)UPLOAD_DIR : ($trimmed . DIRECTORY_SEPARATOR);
            }
        }

        $id = trim((string)($source['id'] ?? ''));
        if ($id !== '' && class_exists(SourceContext::class) && method_exists(SourceContext::class, 'uploadRootForId')) {
            $root = (string)SourceContext::uploadRootForId($id);
            $trimmed = rtrim($root, "/\\");
            if ($trimmed !== '') {
                return $trimmed . DIRECTORY_SEPARATOR;
            }
        }

        return rtrim((string)UPLOAD_DIR, "/\\") . DIRECTORY_SEPARATOR;
    }

    private static function deletePath($adapter, string $path): array
    {
        $ok = false;
        $err = '';
        try {
            $ok = (bool)$adapter->delete($path);
        } catch (Throwable $e) {
            $err = trim($e->getMessage());
        }
        if ($ok) {
            return [true, ''];
        }

        $exists = false;
        try {
            $st = $adapter->stat($path);
            $exists = is_array($st);
        } catch (Throwable $e) {
            // ignore
        }
        if (!$exists) {
            return [true, ''];
        }

        if ($err === '') {
            $err = self::adapterError($adapter);
        }
        if ($err === '') {
            $err = 'Delete failed';
        }
        return [false, $err];
    }

    private static function buildResult(array $source, array $checks): array
    {
        $checkKeys = ['connect', 'read', 'createFolder', 'write', 'moveRename', 'delete'];
        $caps = [];
        foreach ($checkKeys as $key) {
            $found = self::findCheck($checks, $key);
            $caps[$key] = $found ? self::stateToBool((string)($found['state'] ?? 'unknown')) : null;
        }

        $ok = ($caps['connect'] === true) && ($caps['read'] === true);
        $readOnly = !empty($source['readOnly']);
        $limited = false;
        if ($ok && !$readOnly) {
            foreach (['createFolder', 'write', 'moveRename', 'delete'] as $key) {
                if ($caps[$key] === false) {
                    $limited = true;
                    break;
                }
            }
        }

        $issues = [];
        foreach ($checks as $check) {
            if (!is_array($check) || (($check['state'] ?? '') !== 'fail')) {
                continue;
            }
            $issues[] = [
                'key' => (string)($check['key'] ?? ''),
                'label' => (string)($check['label'] ?? ''),
                'message' => (string)($check['message'] ?? ''),
            ];
        }

        $message = '';
        if (!$ok) {
            $primaryFail = null;
            foreach (['connect', 'read'] as $key) {
                $found = self::findCheck($checks, $key);
                if ($found && (($found['state'] ?? '') === 'fail')) {
                    $primaryFail = $found;
                    break;
                }
            }
            if (!$primaryFail && $issues) {
                $primaryFail = $issues[0];
            }
            $message = trim((string)($primaryFail['message'] ?? ''));
            if ($message === '') {
                $message = 'Connection test failed';
            }
        } elseif ($limited) {
            $message = 'Connected with permission limitations';
        } else {
            $message = 'Connected';
        }

        return [
            'ok' => $ok,
            'limited' => $limited,
            'message' => $message,
            'error' => $ok ? '' : $message,
            'sourceId' => trim((string)($source['id'] ?? '')),
            'sourceType' => strtolower((string)($source['type'] ?? 'local')),
            'sourceReadOnly' => $readOnly,
            'capabilities' => $caps,
            'checks' => $checks,
            'issues' => $issues,
            'checkedAt' => gmdate('c'),
        ];
    }

    public static function check(array $source): array
    {
        $checks = [];
        $type = strtolower((string)($source['type'] ?? 'local'));
        $readOnly = !empty($source['readOnly']);
        $rootPath = self::sourceRoot($source);

        if ($type === 'local') {
            if (!is_dir($rootPath)) {
                self::addCheck($checks, 'connect', 'Connect', 'fail', 'Local path not found');
                self::addCheck($checks, 'read', 'Read/list', 'fail', 'Local path not found');
                self::addCheck($checks, 'createFolder', 'Create folder', 'skipped', 'Skipped because source root is unavailable.');
                self::addCheck($checks, 'write', 'Write file', 'skipped', 'Skipped because source root is unavailable.');
                self::addCheck($checks, 'moveRename', 'Move/Rename', 'skipped', 'Skipped because source root is unavailable.');
                self::addCheck($checks, 'delete', 'Delete', 'skipped', 'Skipped because source root is unavailable.');
                return self::buildResult($source, $checks);
            }
            if (!is_readable($rootPath)) {
                self::addCheck($checks, 'connect', 'Connect', 'fail', 'Local path is not readable');
                self::addCheck($checks, 'read', 'Read/list', 'fail', 'Local path is not readable');
                self::addCheck($checks, 'createFolder', 'Create folder', 'skipped', 'Skipped because source root is not readable.');
                self::addCheck($checks, 'write', 'Write file', 'skipped', 'Skipped because source root is not readable.');
                self::addCheck($checks, 'moveRename', 'Move/Rename', 'skipped', 'Skipped because source root is not readable.');
                self::addCheck($checks, 'delete', 'Delete', 'skipped', 'Skipped because source root is not readable.');
                return self::buildResult($source, $checks);
            }
        }

        $adapter = null;
        try {
            $adapter = StorageFactory::createAdapterFromSourceConfig($source, false);
        } catch (Throwable $e) {
            $adapter = null;
        }
        if (!$adapter) {
            self::addCheck($checks, 'connect', 'Connect', 'fail', 'Adapter unavailable');
            self::addCheck($checks, 'read', 'Read/list', 'fail', 'Adapter unavailable');
            self::addCheck($checks, 'createFolder', 'Create folder', 'skipped', 'Skipped because adapter is unavailable.');
            self::addCheck($checks, 'write', 'Write file', 'skipped', 'Skipped because adapter is unavailable.');
            self::addCheck($checks, 'moveRename', 'Move/Rename', 'skipped', 'Skipped because adapter is unavailable.');
            self::addCheck($checks, 'delete', 'Delete', 'skipped', 'Skipped because adapter is unavailable.');
            return self::buildResult($source, $checks);
        }

        $connectOk = false;
        $connectMsg = '';
        if (method_exists($adapter, 'testConnection')) {
            try {
                $connectOk = (bool)$adapter->testConnection();
            } catch (Throwable $e) {
                $connectOk = false;
                $connectMsg = trim($e->getMessage());
            }
            if ($connectOk) {
                $connectMsg = 'Connection established';
            } elseif ($connectMsg === '') {
                $connectMsg = self::adapterError($adapter);
            }
        } else {
            try {
                $probe = $adapter->list($rootPath);
                $connectOk = is_array($probe);
            } catch (Throwable $e) {
                $connectOk = false;
                $connectMsg = trim($e->getMessage());
            }
            if ($connectOk) {
                $connectMsg = 'Connection established';
            } elseif ($connectMsg === '') {
                $connectMsg = self::adapterError($adapter);
            }
        }
        if ($connectMsg === '') {
            $connectMsg = $connectOk ? 'Connection established' : 'Connection test failed';
        }
        self::addCheck($checks, 'connect', 'Connect', $connectOk ? 'ok' : 'fail', $connectMsg);

        if (!$connectOk) {
            self::addCheck($checks, 'read', 'Read/list', 'fail', 'Unable to read source root.');
            self::addCheck($checks, 'createFolder', 'Create folder', 'skipped', 'Skipped because connection failed.');
            self::addCheck($checks, 'write', 'Write file', 'skipped', 'Skipped because connection failed.');
            self::addCheck($checks, 'moveRename', 'Move/Rename', 'skipped', 'Skipped because connection failed.');
            self::addCheck($checks, 'delete', 'Delete', 'skipped', 'Skipped because connection failed.');
            return self::buildResult($source, $checks);
        }

        $readOk = false;
        $readMsg = '';
        try {
            $entries = $adapter->list($rootPath);
            $readOk = is_array($entries);
        } catch (Throwable $e) {
            $readOk = false;
            $readMsg = trim($e->getMessage());
        }
        if (!$readOk) {
            try {
                $st = $adapter->stat($rootPath);
                if (is_array($st)) {
                    $readOk = true;
                }
            } catch (Throwable $e) {
                if ($readMsg === '') {
                    $readMsg = trim($e->getMessage());
                }
            }
        }
        if ($readOk) {
            $readMsg = 'Source root is readable';
        } elseif ($readMsg === '') {
            $readMsg = self::adapterError($adapter);
            if ($readMsg === '') {
                $readMsg = 'Unable to read source root';
            }
        }
        self::addCheck($checks, 'read', 'Read/list', $readOk ? 'ok' : 'fail', $readMsg);

        if (!$readOk) {
            self::addCheck($checks, 'createFolder', 'Create folder', 'skipped', 'Skipped because read probe failed.');
            self::addCheck($checks, 'write', 'Write file', 'skipped', 'Skipped because read probe failed.');
            self::addCheck($checks, 'moveRename', 'Move/Rename', 'skipped', 'Skipped because read probe failed.');
            self::addCheck($checks, 'delete', 'Delete', 'skipped', 'Skipped because read probe failed.');
            return self::buildResult($source, $checks);
        }

        if ($readOnly) {
            self::addCheck($checks, 'createFolder', 'Create folder', 'skipped', 'Source is configured read-only.');
            self::addCheck($checks, 'write', 'Write file', 'skipped', 'Source is configured read-only.');
            self::addCheck($checks, 'moveRename', 'Move/Rename', 'skipped', 'Source is configured read-only.');
            self::addCheck($checks, 'delete', 'Delete', 'skipped', 'Source is configured read-only.');
            return self::buildResult($source, $checks);
        }

        $probeToken = '__filerise_probe_' . gmdate('Ymd_His') . '_' . self::randomToken(8);
        $probeDir = self::joinPath($rootPath, $probeToken);
        $probeFile = self::joinPath($probeDir, 'probe.txt');
        $probeMoved = self::joinPath($probeDir, 'probe-renamed.txt');
        $probePayload = "filerise source probe\n";

        $mkdirOk = false;
        $mkdirMsg = '';
        try {
            $mkdirOk = (bool)$adapter->mkdir($probeDir, 0775, true);
        } catch (Throwable $e) {
            $mkdirOk = false;
            $mkdirMsg = trim($e->getMessage());
        }
        if ($mkdirOk) {
            $mkdirMsg = 'Created probe folder';
        } elseif ($mkdirMsg === '') {
            $mkdirMsg = self::adapterError($adapter);
            if ($mkdirMsg === '') {
                $mkdirMsg = 'Failed to create probe folder';
            }
        }
        self::addCheck($checks, 'createFolder', 'Create folder', $mkdirOk ? 'ok' : 'fail', $mkdirMsg);

        $writeOk = false;
        $writeMsg = '';
        try {
            $writeOk = (bool)$adapter->write($probeFile, $probePayload);
        } catch (Throwable $e) {
            $writeOk = false;
            $writeMsg = trim($e->getMessage());
        }
        if ($writeOk) {
            $writeMsg = 'Created probe file';
        } elseif ($writeMsg === '') {
            $writeMsg = self::adapterError($adapter);
            if ($writeMsg === '') {
                $writeMsg = 'Failed to write probe file';
            }
        }
        self::addCheck($checks, 'write', 'Write file', $writeOk ? 'ok' : 'fail', $writeMsg);

        $activeFile = $writeOk ? $probeFile : '';
        $moveOk = false;
        $moveMsg = '';
        if ($writeOk) {
            try {
                $moveOk = (bool)$adapter->move($probeFile, $probeMoved);
            } catch (Throwable $e) {
                $moveOk = false;
                $moveMsg = trim($e->getMessage());
            }
            if ($moveOk) {
                $activeFile = $probeMoved;
                $moveMsg = 'Renamed probe file';
            } elseif ($moveMsg === '') {
                $moveMsg = self::adapterError($adapter);
                if ($moveMsg === '') {
                    $moveMsg = 'Failed to rename probe file';
                }
            }
            self::addCheck($checks, 'moveRename', 'Move/Rename', $moveOk ? 'ok' : 'fail', $moveMsg);
        } else {
            self::addCheck($checks, 'moveRename', 'Move/Rename', 'skipped', 'Skipped because write probe failed.');
        }

        $deleteState = 'skipped';
        $deleteMsg = 'Skipped because no writable probe artifacts were created.';
        if ($writeOk || $mkdirOk) {
            $deleteState = 'ok';
            $deleteErrors = [];

            if ($activeFile !== '') {
                [$fileDeleted, $fileDeleteErr] = self::deletePath($adapter, $activeFile);
                if (!$fileDeleted) {
                    $deleteState = 'fail';
                    $deleteErrors[] = $fileDeleteErr !== '' ? $fileDeleteErr : 'Failed to delete probe file';
                }
            }

            $dirExists = false;
            try {
                $dirExists = is_array($adapter->stat($probeDir));
            } catch (Throwable $e) {
                $dirExists = false;
            }
            if ($dirExists) {
                [$dirDeleted, $dirDeleteErr] = self::deletePath($adapter, $probeDir);
                if (!$dirDeleted) {
                    $deleteState = 'fail';
                    $deleteErrors[] = $dirDeleteErr !== '' ? $dirDeleteErr : 'Failed to delete probe folder';
                }
            }

            if ($deleteState === 'ok') {
                $deleteMsg = 'Removed probe artifacts';
            } else {
                $deleteMsg = implode('; ', array_filter($deleteErrors));
                if ($deleteMsg === '') {
                    $deleteMsg = 'Failed to clean up probe artifacts';
                }
            }
        }
        self::addCheck($checks, 'delete', 'Delete', $deleteState, $deleteMsg);

        return self::buildResult($source, $checks);
    }
}
