<?php

/**
 * @OA@Post(
 *   path="/api/folder/setFolderEncryption.php",
 *   summary="Set folder encryption state",
 *   description="Enables or disables folder encryption (v1 compatibility).",
 *   operationId="setFolderEncryption",
 *   tags={"Folders"},
 *   security={{"cookieAuth": {}}},
 *   @OA\Parameter(name="X-CSRF-Token", in="header", required=true, @OA\Schema(type="string")),
 *   @OA\RequestBody(
 *     required=true,
 *     @OA\JsonContent(
 *       required={"folder","encrypted"},
 *       @OA\Property(property="folder", type="string", example="team/reports"),
 *       @OA\Property(property="encrypted", type="boolean", example=true)
 *     )
 *   ),
 *   @OA\Response(response=200, description="Update result"),
 *   @OA\Response(response=400, description="Invalid input"),
 *   @OA\Response(response=401, description="Unauthorized"),
 *   @OA\Response(response=403, description="Forbidden"),
 *   @OA\Response(response=404, description="Folder not found"),
 *   @OA\Response(response=409, description="Conflict"),
 *   @OA\Response(response=500, description="Server error")
 * )
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

require_once __DIR__ . '/../../../config/config.php';
require_once PROJECT_ROOT . '/src/FileRise/Domain/FolderEncryptionService.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

if (empty($_SESSION['authenticated']) || $_SESSION['authenticated'] !== true) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$hdr = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
$tok = $_SESSION['csrf_token'] ?? '';
if (!$hdr || !$tok || !hash_equals((string)$tok, (string)$hdr)) {
    http_response_code(403);
    echo json_encode(['error' => 'Invalid CSRF token']);
    exit;
}

$username = (string)($_SESSION['username'] ?? '');
if ($username === '') {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$raw = file_get_contents('php://input') ?: '';
$in = json_decode($raw, true);
if (!is_array($in)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid input.']);
    exit;
}

$folder = isset($in['folder']) ? (string)$in['folder'] : 'root';
$encrypted = isset($in['encrypted']) ? (bool)$in['encrypted'] : null;
if ($encrypted === null) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing encrypted flag.']);
    exit;
}

@session_write_close();

try {
    $res = \FileRise\Domain\FolderEncryptionService::apply($folder, $encrypted, $username);
    echo json_encode($res, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    $code = (int)$e->getCode();
    if ($code < 400 || $code > 599) {
        $code = 500;
    }
    http_response_code($code);
    echo json_encode(['error' => $e->getMessage()]);
}
