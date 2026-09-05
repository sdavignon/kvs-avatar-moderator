<?php

declare(strict_types=1);

namespace KvsAvatarModerator;

final class Factory
{
    public static function moderator(Config $config, ?ModerationClientInterface $client = null): AvatarModerator
    {
        $normalizer = new ImageNormalizer(
            $config->maxImageBytes,
            $config->maxImageDimension,
            $config->outputImageSize,
            $config->jpegQuality,
        );

        $client ??= new OpenAIModerationClient(
            $config->apiKey,
            $config->model,
            $config->timeoutSeconds,
            $config->maxRetries,
        );

        return new AvatarModerator(
            avatarRoot: $config->avatarRoot,
            storageRoot: $config->storageRoot,
            violationImage: $config->violationImage,
            pendingImage: $config->pendingImage,
            normalizer: $normalizer,
            client: $client,
            policy: new PolicyEngine($config->blockOnModelFlagged, $config->blockedCategories),
            publisher: new AtomicFilePublisher(),
            audit: new AuditLogger($config->storageRoot),
        );
    }

    public static function uploadModerator(Config $config, ?ModerationClientInterface $client = null): UploadModerator
    {
        $client ??= new OpenAIModerationClient(
            $config->apiKey,
            $config->model,
            $config->timeoutSeconds,
            $config->maxRetries,
        );

        return new UploadModerator(
            storageRoot: $config->storageRoot,
            violationImage: $config->violationImage,
            pendingImage: $config->pendingImage,
            normalizer: new ImageNormalizer(
                $config->maxImageBytes,
                $config->maxImageDimension,
                $config->outputImageSize,
                $config->jpegQuality,
            ),
            client: $client,
            policy: new PolicyEngine($config->blockOnModelFlagged, $config->blockedCategories),
            audit: new AuditLogger($config->storageRoot),
        );
    }

    public static function cachePurger(Config $config): ?CloudflareCachePurger
    {
        if ($config->cloudflareApiToken === null || $config->cloudflareApiToken === '' || $config->cloudflareZoneId === null || $config->cloudflareZoneId === '') {
            return null;
        }

        return new CloudflareCachePurger(
            apiToken: $config->cloudflareApiToken,
            zoneId: $config->cloudflareZoneId,
            publicBaseUrl: $config->publicBaseUrl,
        );
    }
}
