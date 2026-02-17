<?php

declare(strict_types=1);

// public/api/pro/audit/list.php
/**
 * @OA\Get(
 *   path="/api/pro/audit/list.php",
 *   summary="List audit log entries",
 *   description="Returns audit log entries for admins, or for a specific folder when non-admin.",
 *   operationId="proAuditList",
 *   tags={"Pro"},
 *   security={{"cookieAuth": {}}},
 *   @OA\Parameter(name="folder", in="query", required=false, @OA\Schema(type="string"), example="team"),
 *   @OA\Parameter(name="user", in="query", required=false, @OA\Schema(type="string")),
 *   @OA\Parameter(name="action", in="query", required=false, @OA\Schema(type="string")),
 *   @OA\Parameter(name="source", in="query", required=false, @OA\Schema(type="string")),
 *   @OA\Parameter(name="storage", in="query", required=false, @OA\Schema(type="string")),
 *   @OA\Parameter(name="from", in="query", required=false, @OA\Schema(type="string"), description="ISO timestamp or epoch"),
 *   @OA\Parameter(name="to", in="query", required=false, @OA\Schema(type="string")),
 *   @OA\Parameter(name="limit", in="query", required=false, @OA\Schema(type="integer", minimum=1, maximum=500), example=200),
 *   @OA\Response(response=200, description="Audit list payload"),
 *   @OA\Response(response=400, description="Invalid input"),
 *   @OA\Response(response=401, description="Unauthorized"),
 *   @OA\Response(response=403, description="Forbidden or Pro required")
 * )
 */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

require_once __DIR__ . '/../../../../config/config.php';
require_once PROJECT_ROOT . '/src/lib/ACL.php';
require_once PROJECT_ROOT . '/src/FileRise/Domain/AuditAccessPolicy.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
if (empty($_SESSION['authenticated'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
    exit;
}

$username = (string)($_SESSION['username'] ?? '');
$perms = [
    'role' => $_SESSION['role'] ?? null,
    'admin' => $_SESSION['admin'] ?? null,
    'isAdmin' => $_SESSION['isAdmin'] ?? null,
    'folderOnly' => $_SESSION['folderOnly'] ?? null,
    'readOnly' => $_SESSION['readOnly'] ?? null,
];
@session_write_close();

if (!defined('FR_PRO_ACTIVE') || !FR_PRO_ACTIVE || !class_exists('ProAudit') || !fr_pro_api_level_at_least(FR_PRO_API_REQUIRE_AUDIT)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'pro_required']);
    exit;
}

try {
    $folder = \FileRise\Domain\AuditAccessPolicy::normalizeFolderFilter((string)($_GET['folder'] ?? ''));
    \FileRise\Domain\AuditAccessPolicy::assertAuditFolderReadable($folder, $username, $perms);

    $filters = \FileRise\Domain\AuditAccessPolicy::buildFilters($_GET, $folder);

    $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 200;
    $limit = max(1, min(500, $limit));

    $result = \ProAudit::list($filters, $limit);

    if (empty($result['ok'])) {
        $code = 400;
        if (($result['error'] ?? '') === 'pro_required') {
            $code = 403;
        }
        http_response_code($code);
    }

    echo json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    $code = (int)$e->getCode();
    if ($code >= 400 && $code <= 599) {
        http_response_code($code);
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
        exit;
    }

    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'server_error']);
}
