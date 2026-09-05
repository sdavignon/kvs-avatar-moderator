<?php

declare(strict_types=1);

namespace KvsAvatarModerator;

final class UploadModerator
{
    public function __construct(
        private readonly string $storageRoot,
        private readonly string $violationImage,
        private readonly string $pendingImage,
        private readonly ImageNormalizer $normalizer,
        private readonly ModerationClientInterface $client,
        private readonly PolicyEngine $policy,
        private readonly AuditLogger $audit,
    ) {
    }

    /** @return array<string, mixed> */
    public function moderate(string $uploadPath, string|int|null $userId = null): array
    {
        if (!is_file($uploadPath) || is_link($uploadPath)) {
            throw new InvalidImageException('Avatar upload is not a regular file');
        }

        $sourceHash = hash_file('sha256', $uploadPath) ?: '';
        $prepared = $this->temporaryFile();
        $replacement = null;

        try {
            try {
                $metadata = $this->normalizer->normalize($uploadPath, $prepared, 'image/jpeg');
            } catch (InvalidImageException) {
                $quarantine = $this->quarantine($uploadPath, $userId, $sourceHash);
                $replacement = $this->temporaryFile();
                $this->normalizer->normalize($this->violationImage, $replacement, 'image/jpeg');
                $this->replacePrivateUpload($replacement, $uploadPath);
                $result = [
                    'status' => 'invalid_replaced',
                    'approved' => false,
                    'user_id' => $userId,
                    'path' => 'pending-kvs-upload',
                    'violations' => ['invalid_image'],
                    'warning' => 'Avatar removed: upload a valid JPEG, PNG, or WebP image.',
                    'quarantine_path' => $this->relativeStoragePath($quarantine),
                ];
                $this->audit->write($result + ['source_sha256' => $sourceHash, 'source' => 'kvs_pre_upload']);
                return $result;
            }

            $data = file_get_contents($prepared);
            if ($data === false) {
                throw new \RuntimeException('Unable to read normalized avatar');
            }

            try {
                $moderation = $this->client->moderate('data:' . $metadata['mime'] . ';base64,' . base64_encode($data));
                $decision = $this->policy->decide($moderation['result']);
            } catch (\Throwable $exception) {
                $quarantine = $this->quarantine($uploadPath, $userId, $sourceHash);
                $replacement = $this->temporaryFile();
                $this->normalizer->normalize($this->pendingImage, $replacement, 'image/jpeg');
                $this->replacePrivateUpload($replacement, $uploadPath);
                $result = [
                    'status' => 'review_required',
                    'approved' => false,
                    'user_id' => $userId,
                    'path' => 'pending-kvs-upload',
                    'violations' => [],
                    'warning' => 'Avatar is temporarily under review. Please check back.',
                    'retry_source' => $this->relativeStoragePath($quarantine),
                    'error_type' => $exception::class,
                ];
                $this->audit->write($result + ['source_sha256' => $sourceHash, 'source' => 'kvs_pre_upload']);
                return $result;
            }

            if (!$decision->approved) {
                $quarantine = $this->quarantine($uploadPath, $userId, $sourceHash);
                $replacement = $this->temporaryFile();
                $this->normalizer->normalize($this->violationImage, $replacement, 'image/jpeg');
                $this->replacePrivateUpload($replacement, $uploadPath);
                $result = [
                    'status' => 'violation_replaced',
                    'approved' => false,
                    'user_id' => $userId,
                    'path' => 'pending-kvs-upload',
                    'violations' => $decision->violations,
                    'scores' => $decision->scores,
                    'model' => $moderation['model'] ?? null,
                    'request_id' => $moderation['id'] ?? null,
                    'warning' => 'Avatar removed because it violated the avatar policy.',
                    'quarantine_path' => $this->relativeStoragePath($quarantine),
                ];
                $this->audit->write($result + ['source_sha256' => $sourceHash, 'source' => 'kvs_pre_upload']);
                return $result;
            }

            $this->replacePrivateUpload($prepared, $uploadPath);
            $result = [
                'status' => 'approved',
                'approved' => true,
                'user_id' => $userId,
                'path' => 'pending-kvs-upload',
                'violations' => [],
                'scores' => $decision->scores,
                'model' => $moderation['model'] ?? null,
                'request_id' => $moderation['id'] ?? null,
                'warning' => null,
            ];
            $this->audit->write($result + ['source_sha256' => $sourceHash, 'source' => 'kvs_pre_upload']);
            return $result;
        } finally {
            @unlink($prepared);
            if ($replacement !== null) {
                @unlink($replacement);
            }
        }
    }

    private function quarantine(string $source, string|int|null $userId, string $sha256): string
    {
        $directory = $this->storageRoot . DIRECTORY_SEPARATOR . 'quarantine' . DIRECTORY_SEPARATOR . gmdate('Y-m-d');
        if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) {
            throw new \RuntimeException('Unable to create quarantine directory');
        }
        $safeUser = preg_replace('/[^A-Za-z0-9_-]/', '_', (string) ($userId ?? 'unknown')) ?: 'unknown';
        $target = $directory . DIRECTORY_SEPARATOR . $safeUser . '-' . substr($sha256, 0, 16) . '-' . bin2hex(random_bytes(4)) . '.upload';
        if (!copy($source, $target)) {
            throw new \RuntimeException('Unable to quarantine the original avatar');
        }
        @chmod($target, 0640);
        return $target;
    }

    private function temporaryFile(): string
    {
        $directory = $this->storageRoot . DIRECTORY_SEPARATOR . 'processed';
        if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) {
            throw new \RuntimeException('Unable to create processed directory');
        }
        $path = tempnam($directory, 'upload-');
        if ($path === false) {
            throw new \RuntimeException('Unable to create temporary upload file');
        }
        return $path;
    }

    private function replacePrivateUpload(string $preparedFile, string $uploadPath): void
    {
        $source = fopen($preparedFile, 'rb');
        if ($source === false) {
            throw new \RuntimeException('Unable to open the prepared avatar');
        }

        $target = fopen($uploadPath, 'c+b');
        if ($target === false) {
            fclose($source);
            throw new \RuntimeException('Unable to open the private PHP upload');
        }

        try {
            if (!flock($target, LOCK_EX)) {
                throw new \RuntimeException('Unable to lock the private PHP upload');
            }
            if (!ftruncate($target, 0) || fseek($target, 0) !== 0) {
                throw new \RuntimeException('Unable to reset the private PHP upload');
            }
            $written = stream_copy_to_stream($source, $target);
            if ($written === false || $written < 1 || !fflush($target)) {
                throw new \RuntimeException('Unable to rewrite the private PHP upload');
            }
        } finally {
            flock($target, LOCK_UN);
            fclose($target);
            fclose($source);
        }

        clearstatcache(true, $uploadPath);
    }

    private function relativeStoragePath(string $path): string
    {
        return ltrim(substr($path, strlen($this->storageRoot)), DIRECTORY_SEPARATOR);
    }
}
