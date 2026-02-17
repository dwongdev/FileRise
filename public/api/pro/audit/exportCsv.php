<?php

declare(strict_types=1);

// public/api/pro/audit/exportCsv.php
/**
 * @OA\Get(
 *   path="/api/pro/audit/exportCsv.php",
 *   summary="Export audit log as CSV",
 *   description="Exports audit log entries as CSV.",
 *   operationId="proAuditExportCsv",
 *   tags={"Pro"},
 *   security={{"cookieAuth": {}}},
 *   @OA\Parameter(name="folder", in="query", required=false, @OA\Schema(type="string"), example="team"),
 *   @OA\Parameter(name="user", in="query", required=false, @OA\Schema(type="string")),
 *   @OA\Parameter(name="action", in="query", required=false, @OA\Schema(type="string")),
 *   @OA\Parameter(name="source", in="query", required=false, @OA\Schema(type="string")),
 *   @OA\Parameter(name="storage", in="query", required=false, @OA\Schema(type="string")),
 *   @OA\Parameter(name="from", in="query", required=false, @OA\Schema(type="string"), description="ISO timestamp or epoch"),
 *   @OA\Parameter(name="to", in="query", required=false, @OA\Schema(type="string")),
 *   @OA\Parameter(name="limit", in="query", required=false, @OA\Schema(type="integer", minimum=1, maximum=5000), example=1000),
 *   @OA\Response(
 *     response=200,
 *     description="CSV stream",
 *     content={"text/csv": @OA\MediaType(mediaType="text/csv")}
 *   ),
 *   @OA\Response(response=400, description="Invalid input"),
 *   @OA\Response(response=401, description="Unauthorized"),
 *   @OA\Response(response=403, description="Forbidden or Pro required"),
 *   @OA\Response(response=500, description="Server error")
 * )
 */

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
    header('Content-Type: application/json; charset=utf-8');
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
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => 'pro_required']);
    exit;
}

try {
    $folder = \FileRise\Domain\AuditAccessPolicy::normalizeFolderFilter((string)($_GET['folder'] ?? ''));
    \FileRise\Domain\AuditAccessPolicy::assertAuditFolderReadable($folder, $username, $perms);

    $filters = \FileRise\Domain\AuditAccessPolicy::buildFilters($_GET, $folder);

    $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 1000;
    $limit = max(1, min(5000, $limit));

    header_remove('Content-Type');
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="filerise-audit.csv"');

    $result = \ProAudit::exportCsv($filters, $limit);
    if (empty($result['ok'])) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => $result['error'] ?? 'export_failed']);
        exit;
    }
} catch (Throwable $e) {
    $code = (int)$e->getCode();
    if ($code < 400 || $code > 599) {
        $code = 500;
    }
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
