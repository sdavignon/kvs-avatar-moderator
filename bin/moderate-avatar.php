<?php

declare(strict_types=1);

use KvsAvatarModerator\Config;
use KvsAvatarModerator\Factory;
use KvsAvatarModerator\PathGuard;

require dirname(__DIR__) . '/bootstrap.php';

$options = getopt('', ['path:', 'source::', 'user-id::']);
$relativePath = is_string($options['path'] ?? null) ? $options['path'] : null;
if ($relativePath === null) {
    fwrite(STDERR, "Usage: php bin/moderate-avatar.php --path=relative/avatar.jpg [--source=/private/retry.jpg] [--user-id=123]\n");
    exit(64);
}

try {
    $config = Config::fromEnvironment(dirname(__DIR__));
    $target = PathGuard::resolveRelative($config->avatarRoot, $relativePath);
    $source = is_string($options['source'] ?? null) ? $options['source'] : $target;
    $result = Factory::moderator($config)->moderate($source, $target, $options['user-id'] ?? null);
    fwrite(STDOUT, json_encode($result, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL);
    exit(match ($result['status']) {
        'approved' => 0,
        'violation_replaced', 'invalid_replaced' => 2,
        default => 3,
    });
} catch (Throwable $exception) {
    fwrite(STDERR, json_encode([
        'status' => 'error',
        'error_type' => $exception::class,
        'message' => $exception->getMessage(),
    ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL);
    exit(1);
}
