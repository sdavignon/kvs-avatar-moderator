<?php

declare(strict_types=1);

use KvsAvatarModerator\Config;
use KvsAvatarModerator\Factory;
use KvsAvatarModerator\StateStore;

require dirname(__DIR__) . '/bootstrap.php';

try {
    $options = getopt('', ['baseline', 'process-existing']);
    $config = Config::fromEnvironment(dirname(__DIR__));
    $lockPath = $config->storageRoot . DIRECTORY_SEPARATOR . 'scanner.lock';
    $lock = fopen($lockPath, 'c');
    if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
        throw new RuntimeException('Another scanner process is already running');
    }

    $moderator = Factory::moderator($config);
    $store = new StateStore($config->storageRoot);
    $state = $store->read();
    $processed = 0;

    if (array_key_exists('baseline', $options)) {
        $count = 0;
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($config->avatarRoot, FilesystemIterator::SKIP_DOTS),
        );
        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->isLink() || !in_array(strtolower($file->getExtension()), ['jpg', 'jpeg', 'png', 'webp'], true)) {
                continue;
            }
            $path = $file->getRealPath();
            if ($path === false) {
                continue;
            }
            $relative = str_replace(DIRECTORY_SEPARATOR, '/', ltrim(substr($path, strlen($config->avatarRoot)), DIRECTORY_SEPARATOR));
            $state[$relative] = [
                'status' => 'baseline',
                'published_sha256' => hash_file('sha256', $path) ?: '',
            ];
            $count++;
        }
        $store->write($state);
        flock($lock, LOCK_UN);
        fclose($lock);
        fwrite(STDOUT, json_encode(['status' => 'baseline_complete', 'recorded' => $count], JSON_THROW_ON_ERROR) . PHP_EOL);
        exit(0);
    }

    if ($state === [] && !array_key_exists('process-existing', $options)) {
        throw new RuntimeException('Scanner is not initialized. Run with --baseline, or explicitly use --process-existing.');
    }

    foreach ($state as $relative => &$record) {
        if ($processed >= $config->scanLimit || ($record['status'] ?? null) !== 'review_required') {
            continue;
        }
        $retrySource = $record['retry_source'] ?? null;
        $target = $config->avatarRoot . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $relative);
        $source = is_string($retrySource)
            ? $config->storageRoot . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $retrySource)
            : '';
        if (!is_file($source) || !is_file($target)) {
            $record['status'] = 'retry_missing';
            continue;
        }
        $result = $moderator->moderate($source, $target, null);
        $record = $result + ['published_sha256' => hash_file('sha256', $target) ?: ''];
        $processed++;
    }
    unset($record);

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($config->avatarRoot, FilesystemIterator::SKIP_DOTS),
    );
    foreach ($iterator as $file) {
        if ($processed >= $config->scanLimit || !$file->isFile() || $file->isLink()) {
            continue;
        }
        $extension = strtolower($file->getExtension());
        if (!in_array($extension, ['jpg', 'jpeg', 'png', 'webp'], true)) {
            continue;
        }
        $path = $file->getRealPath();
        if ($path === false) {
            continue;
        }
        $relative = ltrim(substr($path, strlen($config->avatarRoot)), DIRECTORY_SEPARATOR);
        $relative = str_replace(DIRECTORY_SEPARATOR, '/', $relative);
        $hash = hash_file('sha256', $path) ?: '';
        if (($state[$relative]['published_sha256'] ?? null) === $hash) {
            continue;
        }
        $result = $moderator->moderate($path, $path, null);
        $state[$relative] = $result + ['published_sha256' => hash_file('sha256', $path) ?: ''];
        $processed++;
    }

    $store->write($state);
    flock($lock, LOCK_UN);
    fclose($lock);
    fwrite(STDOUT, json_encode(['status' => 'complete', 'processed' => $processed], JSON_THROW_ON_ERROR) . PHP_EOL);
} catch (Throwable $exception) {
    fwrite(STDERR, json_encode(['status' => 'error', 'message' => $exception->getMessage()], JSON_THROW_ON_ERROR) . PHP_EOL);
    exit(1);
}
