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
            $runtimeState = [
                'inode_before' => fileinode($temporaryPath) ?: null,
                'inode_after' => null,
                'is_uploaded_after' => null,
                'mime_after' => null,
            ];
            register_shutdown_function(static function () use ($config, $temporaryPath, &$runtimeState): void {
                $lastError = error_get_last();
                $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR];
                $isFatal = is_array($lastError) && in_array((int) ($lastError['type'] ?? 0), $fatalTypes, true);
                $status = http_response_code();
                $status = is_int($status) ? $status : 200;

                $auditDirectory = rtrim($config->storageRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'audit';
                if (!is_dir($auditDirectory)) {
                    @mkdir($auditDirectory, 0750, true);
                }
                $record = [
                    'timestamp' => gmdate(DATE_ATOM),
                    'http_status' => $status,
                    'fatal' => $isFatal,
                    'error_type' => $isFatal ? (int) ($lastError['type'] ?? 0) : null,
                    'error_message' => $isFatal ? substr((string) ($lastError['message'] ?? ''), 0, 1000) : null,
                    'error_file' => $isFatal ? (string) ($lastError['file'] ?? '') : null,
                    'error_line' => $isFatal ? (int) ($lastError['line'] ?? 0) : null,
                    'response_headers' => headers_list(),
                    'connection_status' => connection_status(),
                    'upload_exists_at_shutdown' => is_file($temporaryPath),
                    'runtime_state' => $runtimeState,
                ];
                @file_put_contents(
                    $auditDirectory . DIRECTORY_SEPARATOR . 'runtime-errors.jsonl',
                    json_encode($record, JSON_UNESCAPED_SLASHES) . PHP_EOL,
                    FILE_APPEND | LOCK_EX,
                );
            });
            $result = Factory::uploadModerator($config)->moderate($temporaryPath);

            clearstatcache(true, $temporaryPath);
            $runtimeState['inode_after'] = fileinode($temporaryPath) ?: null;
            $runtimeState['is_uploaded_after'] = is_uploaded_file($temporaryPath);
            $runtimeState['mime_after'] = (new finfo(FILEINFO_MIME_TYPE))->file($temporaryPath) ?: null;

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
    })();
}
