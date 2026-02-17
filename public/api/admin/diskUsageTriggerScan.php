<?php

// public/api/admin/diskUsageTriggerScan.php
/**
 * @OA@Post(
 *   path="/api/admin/diskUsageTriggerScan.php",
 *   summary="Trigger disk usage scan",
 *   description="Starts a background disk usage scan to build a new snapshot.",
 *   operationId="adminDiskUsageTriggerScan",
 *   tags={"Admin"},
 *   security={{"cookieAuth": {}}},
 *   @OA\RequestBody(
 *     required=false,
 *     @OA\JsonContent(
 *       @OA\Property(property="sourceId", type="string", example="local")
 *     )
 *   ),
 *   @OA\Response(response=200, description="Scan started"),
 *   @OA\Response(response=403, description="Forbidden"),
 *   @OA\Response(response=500, description="Server error")
 * )
 */
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../../config/config.php';
require_once PROJECT_ROOT . '/src/FileRise/Domain/DiskUsageScanLauncher.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    echo json_encode([
        'ok' => false,
        'error' => 'Method not allowed',
    ]);
    return;
}

$username = (string)($_SESSION['username'] ?? '');
$isAdmin = !empty($_SESSION['isAdmin']) || (!empty($_SESSION['admin']) && $_SESSION['admin'] === '1');
if ($username === '' || !$isAdmin) {
    http_response_code(403);
    echo json_encode([
        'ok' => false,
        'error' => 'Forbidden',
    ]);
    return;
}

$csrf = trim((string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''));
if (empty($_SESSION['csrf_token']) || $csrf === '' || !hash_equals((string)$_SESSION['csrf_token'], $csrf)) {
    http_response_code(403);
    echo json_encode([
        'ok' => false,
        'error' => 'Invalid CSRF token',
    ]);
    return;
}

@session_write_close();

try {
    $raw = file_get_contents('php://input');
    $body = json_decode($raw, true);

    $sourceId = '';
    if (is_array($body) && isset($body['sourceId'])) {
        $sourceId = trim((string)$body['sourceId']);
    } elseif (isset($_GET['sourceId'])) {
        $sourceId = trim((string)$_GET['sourceId']);
    }

    $launch = \FileRise\Domain\DiskUsageScanLauncher::launch($sourceId);

    http_response_code(200);
    echo json_encode([
        'ok' => true,
        'pid' => $launch['pid'],
        'message' => 'Disk usage scan started in the background.',
        'logFile' => $launch['logFile'],
        'logMtime' => $launch['logMtime'],
        'sourceId' => $launch['sourceId'],
    ], JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    $code = (int)$e->getCode();
    if ($code >= 400 && $code <= 599) {
        http_response_code($code);
        $error = ($code === 400) ? 'invalid_source' : 'internal_error';
        echo json_encode([
            'ok' => false,
            'error' => $error,
            'message' => $e->getMessage(),
        ]);
        return;
    }

    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => 'internal_error',
        'message' => $e->getMessage(),
    ]);
}
