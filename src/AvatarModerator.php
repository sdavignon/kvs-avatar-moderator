<?php

declare(strict_types=1);

namespace KvsAvatarModerator;

final class AvatarModerator
{
    public function __construct(
        private readonly string $avatarRoot,
        private readonly string $storageRoot,
        private readonly string $violationImage,
        private readonly string $pendingImage,
        private readonly ImageNormalizer $normalizer,
        private readonly ModerationClientInterface $client,
        private readonly PolicyEngine $policy,
        private readonly AtomicFilePublisher $publisher,
        private readonly AuditLogger $audit,
    ) {
    }

    /** @return array<string, mixed> */
    public function moderate(string $sourcePath, string $targetPath, string|int|null $userId = null): array
    {
        $target = is_file($targetPath)
            ? PathGuard::existingFileInside($this->avatarRoot, $targetPath)
            : $this->resolveNewTarget($targetPath);
        $source = $this->resolveSource($sourcePath);
        $relativeTarget = ltrim(substr($target, strlen($this->avatarRoot)), DIRECTORY_SEPARATOR);
        $sourceHash = hash_file('sha256', $source) ?: '';
        $outputMime = ImageNormalizer::mimeForPath($target);
        if ($outputMime === null) {
            throw new InvalidImageException('The target avatar filename must end in .jpg, .jpeg, .png, or .webp');
        }

        $prepared = $this->temporaryFile('processed');
        $replacement = null;
        try {
            try {
                $metadata = $this->normalizer->normalize($source, $prepared, $outputMime);
            } catch (InvalidImageException $exception) {
                $quarantine = $this->quarantine($source, $userId, $sourceHash);
                $this->removeIncomingSource($source);
                $replacement = $this->temporaryFile('processed');
                $this->normalizer->normalize($this->violationImage, $replacement, $outputMime);
                $this->publisher->replace($replacement, $target);
                $result = [
                    'status' => 'invalid_replaced',
                    'approved' => false,
                    'user_id' => $userId,
                    'path' => $relativeTarget,
                    'violations' => ['invalid_image'],
                    'warning' => 'Avatar removed: upload a valid JPEG, PNG, or WebP image.',
                    'quarantine_path' => $this->relativeStoragePath($quarantine),
                ];
                $this->audit->write($result + ['source_sha256' => $sourceHash]);
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
                $quarantine = $this->quarantine($source, $userId, $sourceHash);
                $this->removeIncomingSource($source);
                $replacement = $this->temporaryFile('processed');
                $this->normalizer->normalize($this->pendingImage, $replacement, $outputMime);
                $this->publisher->replace($replacement, $target);
                $result = [
                    'status' => 'review_required',
                    'approved' => false,
                    'user_id' => $userId,
                    'path' => $relativeTarget,
                    'violations' => [],
                    'warning' => 'Avatar is temporarily under review. Please check back.',
                    'retry_source' => $this->relativeStoragePath($quarantine),
                    'error_type' => $exception::class,
                ];
                $this->audit->write($result + ['source_sha256' => $sourceHash]);
                return $result;
            }

            if (!$decision->approved) {
                $quarantine = $this->quarantine($source, $userId, $sourceHash);
                $this->removeIncomingSource($source);
                $replacement = $this->temporaryFile('processed');
                $this->normalizer->normalize($this->violationImage, $replacement, $outputMime);
                $this->publisher->replace($replacement, $target);
                $result = [
                    'status' => 'violation_replaced',
                    'approved' => false,
                    'user_id' => $userId,
                    'path' => $relativeTarget,
                    'violations' => $decision->violations,
                    'scores' => $decision->scores,
                    'model' => $moderation['model'] ?? null,
                    'request_id' => $moderation['id'] ?? null,
                    'warning' => 'Avatar removed because it violated the avatar policy.',
                    'quarantine_path' => $this->relativeStoragePath($quarantine),
                ];
                $this->audit->write($result + ['source_sha256' => $sourceHash]);
                return $result;
            }

            $this->publisher->replace($prepared, $target);
            if ($source !== $target && $this->isInside($this->storageRoot . DIRECTORY_SEPARATOR . 'quarantine', $source)) {
                @unlink($source);
            }
            $result = [
                'status' => 'approved',
                'approved' => true,
                'user_id' => $userId,
                'path' => $relativeTarget,
                'violations' => [],
                'scores' => $decision->scores,
                'model' => $moderation['model'] ?? null,
                'request_id' => $moderation['id'] ?? null,
                'warning' => null,
            ];
            $this->audit->write($result + ['source_sha256' => $sourceHash]);
            return $result;
        } finally {
            @unlink($prepared);
            if ($replacement !== null) {
                @unlink($replacement);
            }
        }
    }

    private function resolveSource(string $source): string
    {
        foreach ([$this->avatarRoot, $this->storageRoot . DIRECTORY_SEPARATOR . 'quarantine', $this->storageRoot . DIRECTORY_SEPARATOR . 'incoming'] as $root) {
            try {
                return PathGuard::existingFileInside($root, $source);
            } catch (\RuntimeException) {
            }
        }
        throw new \RuntimeException('Moderation source is outside approved storage');
    }

    private function quarantine(string $source, string|int|null $userId, string $sha256): string
    {
        $quarantineRoot = $this->storageRoot . DIRECTORY_SEPARATOR . 'quarantine';
        $day = gmdate('Y-m-d');
        $directory = $quarantineRoot . DIRECTORY_SEPARATOR . $day;
        if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) {
            throw new \RuntimeException('Unable to create quarantine directory');
        }
        if ($this->isInside($quarantineRoot, $source)) {
            return $source;
        }
        $safeUser = preg_replace('/[^A-Za-z0-9_-]/', '_', (string) ($userId ?? 'unknown')) ?: 'unknown';
        $extension = strtolower(pathinfo($source, PATHINFO_EXTENSION));
        $target = $directory . DIRECTORY_SEPARATOR . $safeUser . '-' . substr($sha256, 0, 16) . '-' . bin2hex(random_bytes(4)) . '.' . $extension;
        if (!copy($source, $target)) {
            throw new \RuntimeException('Unable to quarantine the original avatar');
        }
        @chmod($target, 0640);
        return $target;
    }

    private function temporaryFile(string $subdirectory): string
    {
        $directory = $this->storageRoot . DIRECTORY_SEPARATOR . $subdirectory;
        if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) {
            throw new \RuntimeException("Unable to create {$subdirectory} directory");
        }
        $path = tempnam($directory, 'avatar-');
        if ($path === false) {
            throw new \RuntimeException('Unable to create temporary moderation file');
        }
        return $path;
    }

    private function relativeStoragePath(string $path): string
    {
        return ltrim(substr($path, strlen($this->storageRoot)), DIRECTORY_SEPARATOR);
    }

    private function isInside(string $root, string $path): bool
    {
        $rootReal = realpath($root);
        $pathReal = realpath($path);
        if ($rootReal === false || $pathReal === false) {
            return false;
        }
        return str_starts_with($pathReal, rtrim($rootReal, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR);
    }

    private function removeIncomingSource(string $source): void
    {
        if ($this->isInside($this->storageRoot . DIRECTORY_SEPARATOR . 'incoming', $source)) {
            @unlink($source);
        }
    }

    private function resolveNewTarget(string $targetPath): string
    {
        $prefix = rtrim($this->avatarRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        $candidate = $targetPath;
        $checkedPrefix = $prefix;
        if (DIRECTORY_SEPARATOR === '\\') {
            $candidate = strtolower($candidate);
            $checkedPrefix = strtolower($checkedPrefix);
        }
        if (!str_starts_with($candidate, $checkedPrefix)) {
            throw new \RuntimeException('Avatar target is outside the configured root');
        }
        $relative = substr($targetPath, strlen($prefix));
        return PathGuard::resolveRelativeTarget($this->avatarRoot, $relative);
    }
}
