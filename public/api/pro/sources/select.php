<?php

// public/api/pro/sources/select.php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../../../config/config.php';
require_once PROJECT_ROOT . '/src/lib/SourceContext.php';
require_once PROJECT_ROOT . '/src/FileRise/Domain/SourceAccessService.php';

try {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        http_response_code(405);
        echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
        exit;
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
        echo json_encode(['ok' => false, 'error' => 'Invalid JSON body']);
        exit;
    }

    $id = trim((string)($body['id'] ?? ''));
    \FileRise\Domain\SourceAccessService::requireSelectableSource($id);

    $username = (string)($_SESSION['username'] ?? '');
    $perms = \FileRise\Domain\SourceAccessService::loadUserPermissions($username);

    if (!\FileRise\Domain\SourceAccessService::userCanAccessSourceRoot($id, $username, $perms)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Access denied']);
        exit;
    }

    if (class_exists('SourceContext')) {
        SourceContext::setActiveId($id, true);
    }

    echo json_encode(['ok' => true, 'activeId' => $id], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    $code = (int)$e->getCode();
    if ($code >= 400 && $code <= 599) {
        http_response_code($code);
        echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Error selecting source'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
