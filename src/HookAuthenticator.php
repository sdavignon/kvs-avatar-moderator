<?php

declare(strict_types=1);

namespace KvsAvatarModerator;

final class HookAuthenticator
{
    public function __construct(
        private readonly string $secret,
        private readonly string $storageRoot,
        private readonly int $maxClockSkew,
    ) {
        if (strlen($secret) < 32) {
            throw new \RuntimeException('KVS_HOOK_SECRET must contain at least 32 characters');
        }
    }

    public function verify(string $body, string $timestamp, string $nonce, string $signature): void
    {
        if (!ctype_digit($timestamp) || abs(time() - (int) $timestamp) > $this->maxClockSkew) {
            throw new \RuntimeException('Hook timestamp is outside the allowed window');
        }
        if (!preg_match('/^[A-Za-z0-9_-]{16,128}$/', $nonce)) {
            throw new \RuntimeException('Hook nonce is invalid');
        }
        $expected = hash_hmac('sha256', $timestamp . '.' . $nonce . '.' . $body, $this->secret);
        if (!hash_equals($expected, strtolower($signature))) {
            throw new \RuntimeException('Hook signature is invalid');
        }

        $directory = rtrim($this->storageRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'nonces';
        if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) {
            throw new \RuntimeException('Unable to create nonce storage');
        }
        $path = $directory . DIRECTORY_SEPARATOR . hash('sha256', $nonce);
        $handle = @fopen($path, 'x');
        if ($handle === false) {
            throw new \RuntimeException('Hook nonce has already been used');
        }
        fclose($handle);
        @chmod($path, 0600);

        foreach (glob($directory . DIRECTORY_SEPARATOR . '*') ?: [] as $candidate) {
            $modified = @filemtime($candidate);
            if ($modified !== false && $modified < time() - ($this->maxClockSkew * 2)) {
                @unlink($candidate);
            }
        }
    }

    public static function sign(string $body, string $timestamp, string $nonce, string $secret): string
    {
        return hash_hmac('sha256', $timestamp . '.' . $nonce . '.' . $body, $secret);
    }
}
