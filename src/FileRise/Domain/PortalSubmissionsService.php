<?php

declare(strict_types=1);

namespace FileRise\Domain;

use FileRise\Storage\SourceContext;
use RuntimeException;

require_once PROJECT_ROOT . '/config/config.php';
require_once PROJECT_ROOT . '/src/lib/SourceContext.php';

final class PortalSubmissionsService
{
    private const LOG_FILE = 'portal_downloads.log';

    public static function sanitizeSubmissionRef(string $value): string
    {
        $clean = strtoupper((string)preg_replace('/[^A-Za-z0-9_-]/', '', $value));
        if ($clean === '') {
            return '';
        }
        return substr($clean, 0, 48);
    }

    public static function generateSubmissionRef(): string
    {
        try {
            $rand = strtoupper(bin2hex(random_bytes(3)));
        } catch (\Throwable $e) {
            $rand = strtoupper(substr(sha1(uniqid('', true)), 0, 6));
        }
        return 'PRT-' . gmdate('Ymd') . '-' . $rand;
    }

    /**
     * @param array<string,mixed> $server
     */
    public static function detectClientIp(array $server): string
    {
        if (!empty($server['HTTP_X_FORWARDED_FOR'])) {
            $parts = explode(',', (string)$server['HTTP_X_FORWARDED_FOR']);
            foreach ($parts as $part) {
                $candidate = trim($part);
                if ($candidate !== '') {
                    return $candidate;
                }
            }
        }

        if (!empty($server['HTTP_X_REAL_IP'])) {
            return trim((string)$server['HTTP_X_REAL_IP']);
        }
        if (!empty($server['REMOTE_ADDR'])) {
            return trim((string)$server['REMOTE_ADDR']);
        }

        return '';
    }

    /**
     * @param array<string,mixed> $portal
     * @param array<string,mixed> $body
     * @param array<string,mixed> $server
     * @return array{payload:array<string,mixed>,submissionRef:string}
     */
    public static function buildSubmissionPayload(
        string $slug,
        array $portal,
        array $body,
        string $submittedBy,
        array $server
    ): array {
        $form = isset($body['form']) && is_array($body['form']) ? $body['form'] : [];

        $name = trim((string)($form['name'] ?? ''));
        $email = trim((string)($form['email'] ?? ''));
        $reference = trim((string)($form['reference'] ?? ''));
        $notes = trim((string)($form['notes'] ?? ''));

        $submissionRefRaw = isset($body['submissionRef']) ? (string)$body['submissionRef'] : '';
        $submissionRef = self::sanitizeSubmissionRef($submissionRefRaw);
        if ($submissionRef === '') {
            $submissionRef = self::generateSubmissionRef();
        }

        $payload = [
            'slug' => $slug,
            'portalLabel' => $portal['label'] ?? '',
            'folder' => $portal['folder'] ?? '',
            'sourceId' => $portal['sourceId'] ?? '',
            'submissionRef' => $submissionRef,
            'form' => [
                'name' => $name,
                'email' => $email,
                'reference' => $reference,
                'notes' => $notes,
            ],
            'submittedBy' => $submittedBy,
            'ip' => self::detectClientIp($server),
            'userAgent' => $server['HTTP_USER_AGENT'] ?? '',
            'createdAt' => gmdate('c'),
        ];

        return [
            'payload' => $payload,
            'submissionRef' => $submissionRef,
        ];
    }

    /**
     * @param array<string,mixed> $payload
     */
    public static function storeSubmission(string $slug, array $payload): void
    {
        self::requireProSubmissionsStore();
        $store = new \ProPortalSubmissions((string)FR_PRO_BUNDLE_DIR);
        $ok = $store->store($slug, $payload);
        if (!$ok) {
            throw new RuntimeException('Failed to store portal submission.');
        }
    }

    /**
     * @return array<int,mixed>
     */
    public static function listSubmissions(string $slug, int $limit = 200): array
    {
        self::requireProSubmissionsStore();
        $store = new \ProPortalSubmissions((string)FR_PRO_BUNDLE_DIR);
        $rows = $store->listBySlug($slug, $limit);
        return is_array($rows) ? $rows : [];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public static function loadDownloadEvents(string $slug, int $limit = 400): array
    {
        $slug = trim($slug);
        if ($slug === '') {
            return [];
        }

        $events = [];
        foreach (self::downloadLogRoots() as $root) {
            $path = rtrim((string)$root, "/\\") . DIRECTORY_SEPARATOR . self::LOG_FILE;
            if (!is_file($path) || !is_readable($path)) {
                continue;
            }

            $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            if (!is_array($lines) || !$lines) {
                continue;
            }

            if (count($lines) > $limit) {
                $lines = array_slice($lines, -$limit);
            }

            foreach ($lines as $line) {
                $rec = json_decode((string)$line, true);
                if (!is_array($rec)) {
                    continue;
                }
                if (trim((string)($rec['slug'] ?? '')) !== $slug) {
                    continue;
                }
                $ts = isset($rec['createdAt']) ? strtotime((string)$rec['createdAt']) : false;
                $rec['_ts'] = ($ts !== false) ? $ts : 0;
                $events[] = $rec;
            }
        }

        if (!$events) {
            return [];
        }

        usort($events, static function ($a, $b) {
            return ($b['_ts'] ?? 0) <=> ($a['_ts'] ?? 0);
        });

        if (count($events) > $limit) {
            $events = array_slice($events, 0, $limit);
        }

        foreach ($events as &$event) {
            unset($event['_ts']);
        }
        unset($event);

        return $events;
    }

    /**
     * @param array<int,mixed> $submissions
     * @param array<int,array<string,mixed>> $downloads
     * @return array<int,mixed>
     */
    public static function attachDownloads(array $submissions, array $downloads): array
    {
        if (!$downloads || !$submissions) {
            return $submissions;
        }

        $downloadsByRef = [];
        foreach ($downloads as $dl) {
            $ref = trim((string)($dl['submissionRef'] ?? ''));
            if ($ref === '') {
                continue;
            }
            if (!isset($downloadsByRef[$ref])) {
                $downloadsByRef[$ref] = [];
            }
            $downloadsByRef[$ref][] = $dl;
        }

        foreach ($submissions as $idx => $row) {
            if (!is_array($row)) {
                continue;
            }
            $raw = isset($row['raw']) && is_array($row['raw']) ? $row['raw'] : [];
            $ref = trim((string)($row['submissionRef'] ?? ($raw['submissionRef'] ?? '')));

            if ($ref !== '' && isset($downloadsByRef[$ref])) {
                $row['downloads'] = $downloadsByRef[$ref];
            }
            if ($ref !== '' && !isset($row['submissionRef'])) {
                $row['submissionRef'] = $ref;
            }

            $submissions[$idx] = $row;
        }

        return $submissions;
    }

    /**
     * @return array<int,string>
     */
    private static function downloadLogRoots(): array
    {
        $roots = [];

        if (class_exists('SourceContext') && SourceContext::sourcesEnabled()) {
            $sources = SourceContext::listAllSources();
            foreach ($sources as $src) {
                if (!is_array($src)) {
                    continue;
                }
                $id = (string)($src['id'] ?? '');
                if ($id === '') {
                    continue;
                }
                $roots[] = (string)SourceContext::metaRootForId($id);
            }
        }

        $roots[] = rtrim((string)META_DIR, "/\\") . DIRECTORY_SEPARATOR;
        return array_values(array_unique($roots));
    }

    private static function requireProSubmissionsStore(): void
    {
        if (!defined('FR_PRO_ACTIVE') || !FR_PRO_ACTIVE || !defined('FR_PRO_BUNDLE_DIR') || !FR_PRO_BUNDLE_DIR) {
            throw new RuntimeException('FileRise Pro is not active.');
        }

        $subPath = rtrim((string)FR_PRO_BUNDLE_DIR, "/\\") . '/ProPortalSubmissions.php';
        if (!is_file($subPath)) {
            throw new RuntimeException('ProPortalSubmissions.php not found in Pro bundle.');
        }
        require_once $subPath;
    }
}
