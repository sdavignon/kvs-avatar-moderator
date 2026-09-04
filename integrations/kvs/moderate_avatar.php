<?php

declare(strict_types=1);

/**
 * Call the moderator synchronously after KVS writes an avatar and before the
 * member-profile request returns. The endpoint should be reachable only over
 * localhost or a private network.
 *
 * @return array<string, mixed>
 */
function kvs_moderate_avatar(
    string $endpoint,
    string $hookSecret,
    string $relativeAvatarPath,
    string|int|null $userId = null,
): array {
    if (strlen($hookSecret) < 32) {
        throw new RuntimeException('Avatar moderator hook secret is too short');
    }

    $body = json_encode([
        'path' => $relativeAvatarPath,
        'user_id' => $userId,
    ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    $timestamp = (string) time();
    $nonce = rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '=');
    $signature = hash_hmac('sha256', $timestamp . '.' . $nonce . '.' . $body, $hookSecret);

    $curl = curl_init($endpoint);
    if ($curl === false) {
        throw new RuntimeException('Unable to initialize avatar moderation request');
    }
    curl_setopt_array($curl, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_TIMEOUT => 30,
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
