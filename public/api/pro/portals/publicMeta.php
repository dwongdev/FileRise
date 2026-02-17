<?php

// public/api/pro/portals/publicMeta.php
/**
 * @OA\Get(
 *   path="/api/pro/portals/publicMeta.php",
 *   summary="Get public portal metadata",
 *   description="Returns the public metadata needed for the portal login page.",
 *   operationId="proPortalsPublicMeta",
 *   tags={"Pro"},
 *   @OA\Parameter(name="slug", in="query", required=true, @OA\Schema(type="string"), example="client-portal"),
 *   @OA\Response(response=200, description="Public portal payload"),
 *   @OA\Response(response=400, description="Missing slug"),
 *   @OA\Response(response=404, description="Portal not found or Pro inactive"),
 *   @OA\Response(response=410, description="Portal expired"),
 *   @OA\Response(response=500, description="Server error")
 * )
 */
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../../../config/config.php';
require_once PROJECT_ROOT . '/src/FileRise/Domain/PortalPublicMetaService.php';

try {
    $slug = isset($_GET['slug']) ? (string)$_GET['slug'] : '';
    $public = \FileRise\Domain\PortalPublicMetaService::getPublicPortalMeta($slug);

    echo json_encode([
        'success' => true,
        'portal' => $public,
    ]);
} catch (Throwable $e) {
    $code = (int)$e->getCode();
    if ($code < 400 || $code > 599) {
        $code = 500;
    }

    http_response_code($code);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
    ]);
}
