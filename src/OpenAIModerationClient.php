<?php

declare(strict_types=1);

namespace KvsAvatarModerator;

final class OpenAIModerationClient implements ModerationClientInterface
{
    private const ENDPOINT = 'https://api.openai.com/v1/moderations';

    public function __construct(
        private readonly string $apiKey,
        private readonly string $model = 'omni-moderation-latest',
        private readonly int $timeoutSeconds = 20,
        private readonly int $maxRetries = 2,
    ) {
    }

    public function moderate(string $dataUrl): array
    {
        $body = json_encode([
            'model' => $this->model,
            'input' => [[
                'type' => 'image_url',
                'image_url' => ['url' => $dataUrl],
            ]],
        ], JSON_THROW_ON_ERROR);

        $lastError = 'OpenAI moderation request failed';
        for ($attempt = 0; $attempt <= $this->maxRetries; $attempt++) {
            $requestId = null;
            $curl = curl_init(self::ENDPOINT);
            if ($curl === false) {
                throw new \RuntimeException('Unable to initialize cURL');
            }

            curl_setopt_array($curl, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $body,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => min(10, $this->timeoutSeconds),
                CURLOPT_TIMEOUT => $this->timeoutSeconds,
                CURLOPT_HTTPHEADER => [
                    'Authorization: Bearer ' . $this->apiKey,
                    'Content-Type: application/json',
                    'User-Agent: kvs-avatar-moderator/1.0',
                ],
                CURLOPT_HEADERFUNCTION => static function ($handle, string $header) use (&$requestId): int {
                    if (stripos($header, 'x-request-id:') === 0) {
                        $requestId = trim(substr($header, strlen('x-request-id:')));
                    }
                    return strlen($header);
                },
            ]);

            $response = curl_exec($curl);
            $curlError = curl_error($curl);
            $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
            curl_close($curl);

            if (is_string($response) && $status >= 200 && $status < 300) {
                $decoded = json_decode($response, true, 512, JSON_THROW_ON_ERROR);
                $result = $decoded['results'][0] ?? null;
                if (!is_array($result)) {
                    throw new \RuntimeException('OpenAI moderation response did not contain a result');
                }
                return [
                    'id' => is_string($decoded['id'] ?? null) ? $decoded['id'] : $requestId,
                    'model' => is_string($decoded['model'] ?? null) ? $decoded['model'] : $this->model,
                    'result' => $result,
                ];
            }

            $lastError = $curlError !== ''
                ? "OpenAI moderation network error: {$curlError}"
                : "OpenAI moderation returned HTTP {$status}";

            if ($attempt >= $this->maxRetries || ($status > 0 && $status !== 408 && $status !== 409 && $status !== 429 && $status < 500)) {
                break;
            }
            usleep(250_000 * (($attempt + 1) ** 2));
        }

        throw new \RuntimeException($lastError);
    }
}
