<?php

declare(strict_types=1);

namespace FileRise\Domain;

use RuntimeException;

require_once PROJECT_ROOT . '/config/config.php';

final class PortalPublicMetaService
{
    /**
     * @return array<string,mixed>
     */
    public static function getPublicPortalMeta(string $slug): array
    {
        if (!defined('FR_PRO_ACTIVE') || !FR_PRO_ACTIVE) {
            throw new RuntimeException('FileRise Pro is not active.', 404);
        }

        $slug = trim($slug);
        if ($slug === '') {
            throw new RuntimeException('Missing portal slug.', 400);
        }

        $bundleDir = defined('FR_PRO_BUNDLE_DIR') ? (string)FR_PRO_BUNDLE_DIR : '';
        if ($bundleDir === '' || !is_dir($bundleDir)) {
            throw new RuntimeException('Pro bundle directory not found.', 500);
        }

        $jsonPath = rtrim($bundleDir, "/\\") . '/portals.json';
        if (!is_file($jsonPath)) {
            throw new RuntimeException('No portals defined.', 404);
        }

        $raw = @file_get_contents($jsonPath);
        if ($raw === false) {
            throw new RuntimeException('Could not read portals store.', 500);
        }

        $data = json_decode($raw, true);
        if (!is_array($data)) {
            throw new RuntimeException('Invalid portals store.', 500);
        }

        $portals = $data['portals'] ?? [];
        if (!is_array($portals) || !isset($portals[$slug]) || !is_array($portals[$slug])) {
            throw new RuntimeException('Portal not found.', 404);
        }

        $portal = $portals[$slug];

        if (!empty($portal['expiresAt'])) {
            $ts = strtotime((string)$portal['expiresAt']);
            if ($ts !== false && $ts < time()) {
                throw new RuntimeException('This portal has expired.', 410);
            }
        }

        $logoFile = (string)($portal['logoFile'] ?? '');
        $logoUrl = (string)($portal['logoUrl'] ?? '');
        if ($logoUrl !== '') {
            $logoUrl = fr_normalize_profile_pic_url($logoUrl);
        }
        if ($logoUrl === '' && $logoFile !== '') {
            $logoUrl = fr_profile_pic_url($logoFile);
        }

        return [
            'slug' => $slug,
            'label' => (string)($portal['label'] ?? ''),
            'title' => (string)($portal['title'] ?? ''),
            'introText' => (string)($portal['introText'] ?? ''),
            'brandColor' => (string)($portal['brandColor'] ?? ''),
            'footerText' => (string)($portal['footerText'] ?? ''),
            'logoFile' => $logoFile,
            'logoUrl' => $logoUrl,
        ];
    }
}
