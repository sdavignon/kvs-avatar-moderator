<?php

declare(strict_types=1);

namespace KvsAvatarModerator;

final class CloudflareCachePurger
{
    private const API_BASE = 'https://api.cloudflare.com/client/v4';

    /** @var null|\Closure(string, string, list<string>, int): array{status: int, body: string} */
    private readonly ?\Closure $transport;

    public function __construct(
        private readonly string $apiToken,
        private readonly string $zoneId,
        private readonly string $publicBaseUrl,
        private readonly int $timeoutSeconds = 10,
        ?\Closure $transport = null,
    ) {
        if (!preg_match('/^[a-f0-9]{32}$/', $this->zoneId)) {
            throw new \InvalidArgumentException('CLOUDFLARE_ZONE_ID must be a 32-character hexadecimal zone ID');
        }
        $parts = parse_url($this->publicBaseUrl);
        if (($parts['scheme'] ?? null) !== 'https' || !is_string($parts['host'] ?? null)) {
            throw new \InvalidArgumentException('PUBLIC_BASE_URL must be an HTTPS origin URL');
        }
        if ($this->apiToken === '') {
            throw new \InvalidArgumentException('CLOUDFLARE_API_TOKEN cannot be empty');
        }
        $this->transport = $transport;
    }

    /** @return array{url: string, status: int} */
    public function purgeAvatar(int $userId, string $directory): array
    {
        if ($userId < 1 || !preg_match('/^[0-9]+$/', $directory)) {
            throw new \InvalidArgumentException('A valid KVS user ID and avatar directory are required');
        }

        $url = rtrim($this->publicBaseUrl, '/')
            . '/contents/avatars/' . rawurlencode($directory)
            . '/' . $userId . '.jpg';
        $body = json_encode(['files' => [$url]], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $endpoint = self::API_BASE . '/zones/' . $this->zoneId . '/purge_cache';
        $headers = [
            'Authorization: Bearer ' . $this->apiToken,
            'Content-Type: application/json',
            'User-Agent: kvs-avatar-moderator/1.0',
        ];

        $response = $this->transport !== null
            ? ($this->transport)($endpoint, $body, $headers, $this->timeoutSeconds)
            : $this->request($endpoint, $body, $headers);

        $decoded = json_decode($response['body'], true);
        if ($response['status'] < 200 || $response['status'] >= 300 || !is_array($decoded) || ($decoded['success'] ?? false) !== true) {
            throw new \RuntimeException('Cloudflare single-file cache purge failed with HTTP ' . $response['status']);
        }

        return ['url' => $url, 'status' => $response['status']];
    }

    /** @param list<string> $headers
     *  @return array{status: int, body: string}
     */
    private function request(string $endpoint, string $body, array $headers): array
    {
        $curl = curl_init($endpoint);
        if ($curl === false) {
            throw new \RuntimeException('Unable to initialize Cloudflare cURL request');
        }

        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => min(5, $this->timeoutSeconds),
            CURLOPT_TIMEOUT => $this->timeoutSeconds,
            CURLOPT_HTTPHEADER => $headers,
        ]);
        $response = curl_exec($curl);
        $error = curl_error($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);

        if (!is_string($response)) {
            throw new \RuntimeException($error !== '' ? 'Cloudflare cache purge network error: ' . $error : 'Cloudflare cache purge returned no response');
        }

        return ['status' => $status, 'body' => $response];
    }
}
