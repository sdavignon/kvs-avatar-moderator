<?php

declare(strict_types=1);

require_once __DIR__ . '/moderate_avatar.php';

/**
 * Preferred no-exposure integration. Pass PHP's private uploaded temporary
 * file before KVS moves it to a web-accessible avatar path.
 *
 * @return array<string, mixed>
 */
function kvs_submit_avatar_for_moderation(
    string $endpoint,
    string $hookSecret,
    string $temporaryUploadPath,
    string $relativeTargetPath,
    string|int|null $userId = null,
): array {
    if (!is_uploaded_file($temporaryUploadPath) && PHP_SAPI !== 'cli') {
        throw new RuntimeException('Avatar source is not a PHP upload');
    }
    $size = filesize($temporaryUploadPath);
    if ($size === false || $size < 1 || $size > 5_242_880) {
        throw new RuntimeException('Avatar upload size is invalid');
    }
    $bytes = file_get_contents($temporaryUploadPath);
    if ($bytes === false) {
        throw new RuntimeException('Unable to read avatar upload');
    }

    $body = json_encode([
        'path' => $relativeTargetPath,
        'user_id' => $userId,
        'image_base64' => base64_encode($bytes),
    ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    $timestamp = (string) time();
    $nonce = rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '=');
    $signature = hash_hmac('sha256', $timestamp . '.' . $nonce . '.' . $body, $hookSecret);

    $curl = curl_init($endpoint);
    if ($curl === false) {
        throw new RuntimeException('Unable to initialize avatar submission');
    }
    curl_setopt_array($curl, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_TIMEOUT => 35,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'X-KVS-Timestamp: ' . $timestamp,
            'X-KVS-Nonce: ' . $nonce,
            'X-KVS-Signature: ' . $signature,
        ],
    ]);
    $response = curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    $error = curl_error($curl);
    curl_close($curl);
    if (!is_string($response)) {
        throw new RuntimeException('Avatar moderator request failed: ' . $error);
    }
    $decoded = json_decode($response, true, 32, JSON_THROW_ON_ERROR);
    if (!is_array($decoded) || (($status < 200 || $status >= 300) && $status !== 503)) {
        throw new RuntimeException("Avatar moderator returned HTTP {$status}");
    }
    return $decoded;
}
