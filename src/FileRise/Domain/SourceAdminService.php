<?php

declare(strict_types=1);

namespace FileRise\Domain;

use FileRise\Storage\SourceHealth;
use FileRise\Storage\SourcesConfig;
use RuntimeException;

require_once PROJECT_ROOT . '/config/config.php';
require_once PROJECT_ROOT . '/src/lib/SourcesConfig.php';
require_once PROJECT_ROOT . '/src/lib/SourceHealth.php';

final class SourceAdminService
{
    /**
     * Persist source settings and run post-save health checks for enabled sources.
     *
     * @param array<string,mixed> $body
     * @return array<string,mixed>
     */
    public static function save(array $body): array
    {
        $didWrite = false;
        $resultSource = null;

        if (array_key_exists('enabled', $body)) {
            $enabled = (bool)$body['enabled'];
            $ok = SourcesConfig::saveEnabled($enabled);
            if (!$ok) {
                throw new RuntimeException('Failed to save sources setting', 500);
            }
            $didWrite = true;
        }

        if (isset($body['source'])) {
            if (!is_array($body['source'])) {
                throw new RuntimeException('Invalid source payload', 400);
            }
            $res = SourcesConfig::upsertSource($body['source']);
            if (empty($res['ok'])) {
                throw new RuntimeException((string)($res['error'] ?? 'Failed to save source'), 400);
            }
            $resultSource = isset($res['source']) && is_array($res['source']) ? $res['source'] : null;
            $didWrite = true;
        }

        if (!$didWrite) {
            throw new RuntimeException('No changes provided', 400);
        }

        $sourceFallback = (isset($body['source']) && is_array($body['source'])) ? $body['source'] : null;
        $auto = self::autoTestAndMaybeDisable($resultSource, $sourceFallback);
        $resultSource = $auto['resultSource'];

        self::refreshPublicSiteConfig();

        return [
            'source' => $resultSource,
            'autoTested' => $auto['autoTested'],
            'autoTestOk' => $auto['autoTestOk'],
            'autoTestLimited' => $auto['autoTestLimited'],
            'autoTestError' => $auto['autoTestError'],
            'autoTest' => $auto['autoTest'],
            'autoDisabled' => $auto['autoDisabled'],
            'autoDisableFailed' => $auto['autoDisableFailed'],
        ];
    }

    /**
     * Execute a source health check for one source id.
     *
     * @return array<string,mixed>
     */
    public static function testById(string $id): array
    {
        $id = trim($id);
        if ($id === '') {
            throw new RuntimeException('Missing source id', 400);
        }

        $source = SourcesConfig::getSource($id);
        if (!$source) {
            throw new RuntimeException('Source not found', 404);
        }

        $result = SourceHealth::check($source);
        return self::normalizeHealthResult($result, 'Test did not return a result');
    }

    /**
     * @param array<string,mixed>|null $resultSource
     * @param array<string,mixed>|null $sourceFallback
     * @return array{
     *   resultSource:array<string,mixed>|null,
     *   autoTested:bool,
     *   autoTestOk:bool|null,
     *   autoTestLimited:bool,
     *   autoTestError:string,
     *   autoTest:array<string,mixed>|null,
     *   autoDisabled:bool,
     *   autoDisableFailed:bool
     * }
     */
    private static function autoTestAndMaybeDisable(?array $resultSource, ?array $sourceFallback): array
    {
        $autoTested = false;
        $autoTestOk = null;
        $autoTestLimited = false;
        $autoTestError = '';
        $autoTest = null;
        $autoDisabled = false;
        $autoDisableFailed = false;

        if ($resultSource && !empty($resultSource['enabled'])) {
            $autoTested = true;
            $sourceId = trim((string)($resultSource['id'] ?? ''));
            $sourceForTest = ($sourceId !== '') ? SourcesConfig::getSource($sourceId) : null;

            if (!$sourceForTest) {
                $autoTest = [
                    'ok' => false,
                    'limited' => false,
                    'message' => 'Source not found after save',
                    'error' => 'Source not found after save',
                    'checks' => [],
                    'capabilities' => [],
                    'issues' => [],
                    'checkedAt' => gmdate('c'),
                ];
            } else {
                $autoTest = self::normalizeHealthResult(SourceHealth::check($sourceForTest), 'Connection test failed');
            }

            $autoTestOk = !empty($autoTest['ok']);
            $autoTestLimited = !empty($autoTest['limited']);
            if (!$autoTestOk) {
                $autoTestError = trim((string)($autoTest['error'] ?? $autoTest['message'] ?? ''));
            }
            if ($autoTestError === '' && !$autoTestOk) {
                $autoTestError = 'Connection test failed';
            }

            if ($autoTestOk !== true) {
                if ($autoTestError === '') {
                    $autoTestError = 'Connection test failed';
                }

                $disableSource = $sourceForTest;
                if (!$disableSource && is_array($sourceFallback)) {
                    $disableSource = $sourceFallback;
                }

                if (is_array($disableSource)) {
                    $disableSource['enabled'] = false;
                    $resDisable = SourcesConfig::upsertSource($disableSource);
                    if (empty($resDisable['ok'])) {
                        $autoDisableFailed = true;
                    } else {
                        $autoDisabled = true;
                        if (isset($resDisable['source']) && is_array($resDisable['source'])) {
                            $resultSource = $resDisable['source'];
                        }
                    }
                } else {
                    $autoDisableFailed = true;
                }
            }
        }

        return [
            'resultSource' => $resultSource,
            'autoTested' => $autoTested,
            'autoTestOk' => $autoTestOk,
            'autoTestLimited' => $autoTestLimited,
            'autoTestError' => $autoTestError,
            'autoTest' => $autoTest,
            'autoDisabled' => $autoDisabled,
            'autoDisableFailed' => $autoDisableFailed,
        ];
    }

    /**
     * @param array<string,mixed> $result
     * @return array<string,mixed>
     */
    private static function normalizeHealthResult(array $result, string $fallbackMessage): array
    {
        if (isset($result['ok'])) {
            return $result;
        }

        $msg = trim($fallbackMessage);
        if ($msg === '') {
            $msg = 'Connection test failed';
        }

        return [
            'ok' => false,
            'limited' => false,
            'message' => $msg,
            'error' => $msg,
            'checks' => [],
            'capabilities' => [],
            'issues' => [],
            'checkedAt' => gmdate('c'),
        ];
    }

    private static function refreshPublicSiteConfig(): void
    {
        $cfg = AdminModel::getConfig();
        if (isset($cfg['error'])) {
            return;
        }
        $public = AdminModel::buildPublicSubset($cfg);
        AdminModel::writeSiteConfig($public);
    }
}
