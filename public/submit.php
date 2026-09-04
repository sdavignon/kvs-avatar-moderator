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

$incoming = null;
try {
    $config = Config::fromEnvironment(dirname(__DIR__));
    $contentLength = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
    $maximumBody = (int) ceil($config->maxImageBytes * 1.38) + 16_384;
    if ($contentLength < 2 || $contentLength > $maximumBody) {
        throw new RuntimeException('Invalid request size');
    }
    $body = file_get_contents('php://input');
    if (!is_string($body) || strlen($body) > $maximumBody) {
        throw new RuntimeException('Unable to read request body');
    }
    if ($config->hookSecret === null) {
        throw new RuntimeException('KVS hook endpoint is not configured');
    }
    (new HookAuthenticator($config->hookSecret, $config->storageRoot, $config->hookMaxClockSkew))->verify(
        $body,
        (string) ($_SERVER['HTTP_X_KVS_TIMESTAMP'] ?? ''),
        (string) ($_SERVER['HTTP_X_KVS_NONCE'] ?? ''),
        (string) ($_SERVER['HTTP_X_KVS_SIGNATURE'] ?? ''),
    );

    $payload = json_decode($body, true, 32, JSON_THROW_ON_ERROR);
    if (!is_array($payload) || !is_string($payload['path'] ?? null) || !is_string($payload['image_base64'] ?? null)) {
        throw new RuntimeException('Request must include path and image_base64');
    }
    $imageBytes = base64_decode($payload['image_base64'], true);
    if (!is_string($imageBytes) || strlen($imageBytes) < 1 || strlen($imageBytes) > $config->maxImageBytes) {
        throw new RuntimeException('Encoded image is invalid or too large');
    }

    $incomingDirectory = $config->storageRoot . DIRECTORY_SEPARATOR . 'incoming';
    if (!is_dir($incomingDirectory) && !mkdir($incomingDirectory, 0750, true) && !is_dir($incomingDirectory)) {
        throw new RuntimeException('Unable to create incoming storage');
    }
    $incoming = tempnam($incomingDirectory, 'upload-');
    if ($incoming === false || file_put_contents($incoming, $imageBytes, LOCK_EX) === false) {
        throw new RuntimeException('Unable to stage avatar privately');
    }
    @chmod($incoming, 0640);

    $target = PathGuard::resolveRelativeTarget($config->avatarRoot, $payload['path']);
    $result = Factory::moderator($config)->moderate($incoming, $target, $payload['user_id'] ?? null);
    http_response_code($result['status'] === 'review_required' ? 503 : 200);
    echo json_encode($result, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
} catch (JsonException) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Invalid JSON request']);
} catch (Throwable $exception) {
    error_log('KVS avatar submission rejected: ' . $exception::class . ': ' . $exception->getMessage());
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Request rejected']);
} finally {
    if (is_string($incoming)) {
        @unlink($incoming);
    }
}
