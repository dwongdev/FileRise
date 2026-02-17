<?php

// public/api/pro/gateways/test.php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../../../config/config.php';
require_once PROJECT_ROOT . '/src/FileRise/Domain/GatewayTestService.php';

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

    if (!defined('FR_PRO_ACTIVE') || !FR_PRO_ACTIVE || !class_exists('ProGateways')) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Pro is not active']);
        exit;
    }

    $raw = file_get_contents('php://input');
    $body = json_decode($raw, true);
    if (!is_array($body)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Invalid JSON body']);
        exit;
    }

    $id = (string)($body['id'] ?? '');
    $includeSecrets = !empty($body['includeSecrets']);
    $result = \FileRise\Domain\GatewayTestService::run($id, $includeSecrets);

    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    $code = (int)$e->getCode();
    if ($code >= 400 && $code <= 599) {
        http_response_code($code);
        echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Error testing gateway share'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
