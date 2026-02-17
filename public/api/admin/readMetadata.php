<?php

/**
 * @OA\Get(
 *   path="/api/admin/readMetadata.php",
 *   summary="Read share metadata JSON",
 *   description="Admin-only: returns the cleaned metadata for file or folder share links.",
 *   tags={"Admin"},
 *   operationId="readMetadata",
 *   security={{"cookieAuth":{}}},
 *   @OA\Parameter(
 *     name="file",
 *     in="query",
 *     required=true,
 *     description="Which metadata file to read",
 *     @OA\Schema(type="string", enum={"share_links.json","share_folder_links.json"})
 *   ),
 *   @OA\Response(
 *     response=200,
 *     description="OK",
 *     @OA\JsonContent(oneOf={
 *       @OA\Schema(ref="#/components/schemas/ShareLinksMap"),
 *       @OA\Schema(ref="#/components/schemas/ShareFolderLinksMap")
 *     })
 *   ),
 *   @OA\Response(response=400, description="Missing or invalid file param"),
 *   @OA\Response(response=403, description="Forbidden (admin only)"),
 *   @OA\Response(response=500, description="Corrupted JSON")
 * )
 */

declare(strict_types=1);

require_once __DIR__ . '/../../../config/config.php';
require_once PROJECT_ROOT . '/src/FileRise/Domain/MetadataReadService.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

if (empty($_SESSION['isAdmin']) || $_SESSION['isAdmin'] !== true) {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden']);
    exit;
}

$file = isset($_GET['file']) ? (string)$_GET['file'] : '';
$result = \FileRise\Domain\MetadataReadService::readAndPrune($file);

if (empty($result['ok'])) {
    $status = (int)($result['status'] ?? 500);
    http_response_code($status > 0 ? $status : 500);

    $payload = ['error' => (string)($result['error'] ?? 'Internal error')];
    if (!empty($result['sourceId'])) {
        $payload['sourceId'] = (string)$result['sourceId'];
    }

    echo json_encode($payload);
    exit;
}

http_response_code(200);
header('Content-Type: application/json');
echo json_encode($result['data'] ?? []);
exit;
