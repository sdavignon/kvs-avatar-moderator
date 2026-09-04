<?php

declare(strict_types=1);

use KvsAvatarModerator\Config;
use KvsAvatarModerator\Factory;
use KvsAvatarModerator\HookAuthenticator;
use KvsAvatarModerator\PathGuard;

require dirname(__DIR__) . '/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    echo json_encode(['status' => 'error', 'message' => 'POST required']);
    exit;
}

try {
    $contentLength = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
    if ($contentLength < 2 || $contentLength > 16_384) {
        throw new RuntimeException('Invalid request size');
    }
    $body = file_get_contents('php://input');
    if (!is_string($body)) {
        throw new RuntimeException('Unable to read request body');
    }

    $config = Config::fromEnvironment(dirname(__DIR__));
    if ($config->hookSecret === null) {
        throw new RuntimeException('KVS hook endpoint is not configured');
    }
    $auth = new HookAuthenticator($config->hookSecret, $config->storageRoot, $config->hookMaxClockSkew);
    $auth->verify(
        $body,
        (string) ($_SERVER['HTTP_X_KVS_TIMESTAMP'] ?? ''),
        (string) ($_SERVER['HTTP_X_KVS_NONCE'] ?? ''),
        (string) ($_SERVER['HTTP_X_KVS_SIGNATURE'] ?? ''),
    );

    $payload = json_decode($body, true, 32, JSON_THROW_ON_ERROR);
    if (!is_array($payload) || !is_string($payload['path'] ?? null)) {
        throw new RuntimeException('Request must include a relative avatar path');
    }
    $target = PathGuard::resolveRelative($config->avatarRoot, $payload['path']);
    $result = Factory::moderator($config)->moderate($target, $target, $payload['user_id'] ?? null);
    http_response_code($result['status'] === 'review_required' ? 503 : 200);
    echo json_encode($result, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
} catch (JsonException $exception) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Invalid JSON request']);
} catch (Throwable $exception) {
    error_log('KVS avatar moderator rejected request: ' . $exception::class . ': ' . $exception->getMessage());
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Request rejected']);
}
