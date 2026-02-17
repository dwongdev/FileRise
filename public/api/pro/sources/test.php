<?php

// public/api/pro/sources/test.php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../../../config/config.php';
require_once PROJECT_ROOT . '/src/FileRise/Domain/SourceAdminService.php';

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
    \FileRise\Http\Controllers\AdminController::requireAdmin();
    \FileRise\Http\Controllers\AdminController::requireCsrf();

    $raw = file_get_contents('php://input');
    $body = json_decode($raw, true);
    if (!is_array($body)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Invalid JSON body']);
        exit;
    }

    $id = trim((string)($body['id'] ?? ''));
    if ($id === '') {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Missing source id']);
        exit;
    }

    $result = \FileRise\Domain\SourceAdminService::testById($id);

    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (RuntimeException $e) {
    $status = (int)$e->getCode();
    if ($status < 400 || $status > 599) {
        $status = 500;
    }
    http_response_code($status);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Error testing source'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
