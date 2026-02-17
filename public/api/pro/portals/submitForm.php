<?php

// public/api/pro/portals/submitForm.php
/**
 * @OA@Post(
 *   path="/api/pro/portals/submitForm.php",
 *   summary="Submit portal form",
 *   description="Submits a portal form payload (requires auth, Pro).",
 *   operationId="proPortalsSubmitForm",
 *   tags={"Pro"},
 *   security={{"cookieAuth": {}}},
 *   @OA\Parameter(name="X-CSRF-Token", in="header", required=true, @OA\Schema(type="string")),
 *   @OA\RequestBody(
 *     required=true,
 *     @OA\JsonContent(
 *       required={"slug","form"},
 *       @OA\Property(property="slug", type="string", example="client-portal"),
 *       @OA\Property(
 *         property="form",
 *         type="object",
 *         @OA\Property(property="name", type="string", example="Jane Doe"),
 *         @OA\Property(property="email", type="string", example="jane@example.com"),
 *         @OA\Property(property="reference", type="string", example="PO-123"),
 *         @OA\Property(property="notes", type="string", example="Please review")
 *       )
 *     )
 *   ),
 *   @OA\Response(response=200, description="Submission saved"),
 *   @OA\Response(response=400, description="Invalid input"),
 *   @OA\Response(response=401, description="Unauthorized"),
 *   @OA\Response(response=403, description="Forbidden or Pro required"),
 *   @OA\Response(response=405, description="Method not allowed"),
 *   @OA\Response(response=500, description="Server error")
 * )
 */
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../../../config/config.php';
require_once PROJECT_ROOT . '/src/FileRise/Domain/PortalSubmissionsService.php';

try {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'error' => 'Method not allowed']);
        return;
    }

    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }

    \FileRise\Http\Controllers\AdminController::requireAuth();
    \FileRise\Http\Controllers\AdminController::requireCsrf();

    $raw = file_get_contents('php://input');
    $body = json_decode($raw, true);
    if (!is_array($body)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid JSON body']);
        return;
    }

    $slug = isset($body['slug']) ? trim((string)$body['slug']) : '';
    if ($slug === '') {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Missing portal slug']);
        return;
    }

    // Make sure portal exists and is not expired
    $portal = \FileRise\Http\Controllers\PortalController::getPortalBySlug($slug);

    $submittedBy = (string)($_SESSION['username'] ?? '');
    $built = \FileRise\Domain\PortalSubmissionsService::buildSubmissionPayload(
        $slug,
        $portal,
        $body,
        $submittedBy,
        $_SERVER
    );

    \FileRise\Domain\PortalSubmissionsService::storeSubmission($slug, $built['payload']);

    echo json_encode([
        'success' => true,
        'submissionRef' => $built['submissionRef'],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    $code = $e instanceof InvalidArgumentException ? 400 : 500;
    if ((int)$e->getCode() >= 400 && (int)$e->getCode() <= 599) {
        $code = (int)$e->getCode();
    }

    http_response_code($code);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
