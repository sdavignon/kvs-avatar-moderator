<?php

declare(strict_types=1);

namespace KvsAvatarModerator;

final class AuditLogger
{
    private readonly string $path;

    public function __construct(string $storageRoot)
    {
        $directory = rtrim($storageRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'audit';
        if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) {
            throw new \RuntimeException('Unable to create audit directory');
        }
        $this->path = $directory . DIRECTORY_SEPARATOR . 'moderation.jsonl';
    }

    /** @param array<string, mixed> $record */
    public function write(array $record): void
    {
        $record = ['timestamp' => gmdate('c')] + $record;
        $line = json_encode($record, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
        $handle = fopen($this->path, 'ab');
        if ($handle === false) {
            throw new \RuntimeException('Unable to open moderation audit log');
        }
        try {
            if (!flock($handle, LOCK_EX)) {
                throw new \RuntimeException('Unable to lock moderation audit log');
            }
            fwrite($handle, $line);
            fflush($handle);
            flock($handle, LOCK_UN);
        } finally {
            fclose($handle);
        }
        @chmod($this->path, 0640);
    }
}
