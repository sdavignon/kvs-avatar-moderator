<?php

declare(strict_types=1);

namespace KvsAvatarModerator;

final class Config
{
    /** @param list<string> $blockedCategories */
    public function __construct(
        public readonly string $projectRoot,
        public readonly string $apiKey,
        public readonly string $model,
        public readonly string $avatarRoot,
        public readonly string $storageRoot,
        public readonly string $violationImage,
        public readonly string $pendingImage,
        public readonly int $maxImageBytes,
        public readonly int $maxImageDimension,
        public readonly int $outputImageSize,
        public readonly int $jpegQuality,
        public readonly int $timeoutSeconds,
        public readonly int $maxRetries,
        public readonly int $scanLimit,
        public readonly bool $blockOnModelFlagged,
        public readonly array $blockedCategories,
        public readonly ?string $hookSecret,
        public readonly int $hookMaxClockSkew,
        public readonly ?string $cloudflareApiToken,
        public readonly ?string $cloudflareZoneId,
        public readonly string $publicBaseUrl,
    ) {
    }

    public static function fromEnvironment(string $projectRoot): self
    {
        $avatarRoot = self::existingDirectory(Environment::require('KVS_AVATAR_ROOT'), 'KVS_AVATAR_ROOT');
        $storageRoot = Environment::get('MODERATOR_STORAGE_ROOT', $projectRoot . '/storage');
        if (!is_dir($storageRoot) && !mkdir($storageRoot, 0750, true) && !is_dir($storageRoot)) {
            throw new \RuntimeException("Unable to create MODERATOR_STORAGE_ROOT: {$storageRoot}");
        }
        $storageRoot = self::existingDirectory($storageRoot, 'MODERATOR_STORAGE_ROOT');

        $violationImage = Environment::get('POLICY_VIOLATION_IMAGE', $projectRoot . '/assets/avatar-policy-violation.png');
        $pendingImage = Environment::get('PENDING_REVIEW_IMAGE', $projectRoot . '/assets/avatar-under-review.png');
        foreach (['POLICY_VIOLATION_IMAGE' => $violationImage, 'PENDING_REVIEW_IMAGE' => $pendingImage] as $name => $path) {
            if (!is_file($path) || !is_readable($path)) {
                throw new \RuntimeException("{$name} is not a readable file: {$path}");
            }
        }

        $categories = array_values(array_filter(array_map('trim', explode(',', Environment::get('BLOCKED_CATEGORIES', '') ?? ''))));

        return new self(
            projectRoot: $projectRoot,
            apiKey: Environment::require('OPENAI_API_KEY'),
            model: Environment::get('OPENAI_MODERATION_MODEL', 'omni-moderation-latest') ?? 'omni-moderation-latest',
            avatarRoot: $avatarRoot,
            storageRoot: $storageRoot,
            violationImage: realpath($violationImage) ?: $violationImage,
            pendingImage: realpath($pendingImage) ?: $pendingImage,
            maxImageBytes: Environment::int('MAX_IMAGE_BYTES', 5_242_880, 65_536, 20_971_520),
            maxImageDimension: Environment::int('MAX_IMAGE_DIMENSION', 4096, 64, 16_384),
            outputImageSize: Environment::int('OUTPUT_IMAGE_SIZE', 512, 64, 2048),
            jpegQuality: Environment::int('JPEG_QUALITY', 88, 50, 95),
            timeoutSeconds: Environment::int('OPENAI_TIMEOUT_SECONDS', 20, 3, 60),
            maxRetries: Environment::int('OPENAI_MAX_RETRIES', 2, 0, 5),
            scanLimit: Environment::int('SCAN_LIMIT', 100, 1, 10_000),
            blockOnModelFlagged: Environment::bool('BLOCK_ON_MODEL_FLAGGED', true),
            blockedCategories: $categories,
            hookSecret: Environment::get('KVS_HOOK_SECRET'),
            hookMaxClockSkew: Environment::int('HOOK_MAX_CLOCK_SKEW_SECONDS', 300, 30, 3600),
            cloudflareApiToken: Environment::get('CLOUDFLARE_API_TOKEN'),
            cloudflareZoneId: Environment::get('CLOUDFLARE_ZONE_ID'),
            publicBaseUrl: Environment::get('PUBLIC_BASE_URL', 'https://theync.com') ?? 'https://theync.com',
        );
    }

    private static function existingDirectory(string $path, string $name): string
    {
        $real = realpath($path);
        if ($real === false || !is_dir($real)) {
            throw new \RuntimeException("{$name} is not an existing directory: {$path}");
        }
        return rtrim($real, DIRECTORY_SEPARATOR);
    }
}
