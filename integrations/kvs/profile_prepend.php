<?php

declare(strict_types=1);

use KvsAvatarModerator\Config;
use KvsAvatarModerator\Factory;

require_once dirname(__DIR__, 2) . '/bootstrap.php';

if (!defined('KVS_AVATAR_MODERATOR_PREPROCESSED')) {
    define('KVS_AVATAR_MODERATOR_PREPROCESSED', true);

    $avatar = $_FILES['avatar'] ?? null;
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && is_array($avatar) && (int) ($avatar['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
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
        } catch (Throwable $exception) {
            error_log('KVS avatar pre-upload moderation failed closed: ' . $exception::class . ': ' . $exception->getMessage());
            $_FILES['avatar']['error'] = UPLOAD_ERR_EXTENSION;
            $_FILES['avatar']['tmp_name'] = '';
            $_FILES['avatar']['size'] = 0;
        }
    }
}
