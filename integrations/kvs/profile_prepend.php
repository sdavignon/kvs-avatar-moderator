<?php

declare(strict_types=1);

use KvsAvatarModerator\Config;
use KvsAvatarModerator\Factory;

require_once dirname(__DIR__, 2) . '/bootstrap.php';

if (!defined('KVS_AVATAR_MODERATOR_PREPROCESSED')) {
    define('KVS_AVATAR_MODERATOR_PREPROCESSED', true);

    // KVS executes this file before its own global bootstrap. Keep every hook
    // variable in a private scope so names such as $config cannot overwrite
    // variables that KVS expects to define and reuse as arrays.
    (static function (): void {
        $avatar = $_FILES['avatar'] ?? null;
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || !is_array($avatar) || (int) ($avatar['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return;
        }

        try {
            if ((int) ($avatar['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_string($avatar['tmp_name'] ?? null)) {
                throw new RuntimeException('KVS avatar upload did not complete');
            }
            $temporaryPath = $avatar['tmp_name'];
            if (!is_uploaded_file($temporaryPath) && PHP_SAPI !== 'cli') {
                throw new RuntimeException('KVS avatar source is not a PHP upload');
            }

            $projectRoot = dirname(__DIR__, 2);
            $config = Config::fromEnvironment($projectRoot);
            $result = Factory::uploadModerator($config)->moderate($temporaryPath);

            $_FILES['avatar']['name'] = 'avatar-' . bin2hex(random_bytes(12)) . '.jpg';
            $_FILES['avatar']['type'] = 'image/jpeg';
            $_FILES['avatar']['size'] = filesize($temporaryPath) ?: 0;
            $GLOBALS['kvs_avatar_moderation_result'] = $result;

            $cachePurger = Factory::cachePurger($config);
            if ($cachePurger !== null) {
                $requestStartedAt = (int) floor((float) ($_SERVER['REQUEST_TIME_FLOAT'] ?? time()));
                register_shutdown_function(static function () use ($cachePurger, $config, $requestStartedAt): void {
                    try {
                        $status = http_response_code();
                        if (is_int($status) && ($status < 200 || $status >= 300)) {
                            return;
                        }

                        $userId = (int) ($_SESSION['user_id'] ?? 0);
                        $sessionUserData = $_SESSION['userdata'] ?? null;
                        if ($userId < 1 && is_array($sessionUserData)) {
                            $userId = (int) ($sessionUserData['user_id'] ?? 0);
                        }
                        if ($userId < 1 || !function_exists('get_dir_by_id')) {
                            return;
                        }

                        $directory = (string) get_dir_by_id($userId);
                        $publishedPath = $config->avatarRoot . DIRECTORY_SEPARATOR . $directory . DIRECTORY_SEPARATOR . $userId . '.jpg';
                        clearstatcache(true, $publishedPath);
                        if (!is_file($publishedPath)) {
                            return;
                        }
                        $modifiedAt = filemtime($publishedPath);
                        if ($modifiedAt === false || $modifiedAt < $requestStartedAt) {
                            return;
                        }

                        $purge = $cachePurger->purgeAvatar($userId, $directory);
                        (new KvsAvatarModerator\AuditLogger($config->storageRoot))->write([
                            'event' => 'cache_purge',
                            'status' => 'purged',
                            'user_id' => $userId,
                            'url' => $purge['url'],
                            'http_status' => $purge['status'],
                        ]);
                    } catch (Throwable $exception) {
                        error_log('KVS avatar Cloudflare purge failed: ' . $exception::class . ': ' . $exception->getMessage());
                    }
                });
            }
        } catch (Throwable $exception) {
            error_log('KVS avatar pre-upload moderation failed closed: ' . $exception::class . ': ' . $exception->getMessage());
            $_FILES['avatar']['error'] = UPLOAD_ERR_EXTENSION;
            $_FILES['avatar']['tmp_name'] = '';
            $_FILES['avatar']['size'] = 0;
        }
    })();
}
