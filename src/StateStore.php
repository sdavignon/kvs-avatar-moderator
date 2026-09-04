<?php

declare(strict_types=1);

namespace KvsAvatarModerator;

final class StateStore
{
    private readonly string $path;

    public function __construct(string $storageRoot)
    {
        $directory = rtrim($storageRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'state';
        if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) {
            throw new \RuntimeException('Unable to create state directory');
        }
        $this->path = $directory . DIRECTORY_SEPARATOR . 'scanner.json';
    }

    /** @return array<string, array<string, mixed>> */
    public function read(): array
    {
        if (!is_file($this->path)) {
            return [];
        }
        $raw = file_get_contents($this->path);
        if ($raw === false || trim($raw) === '') {
            return [];
        }
        $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        return is_array($decoded) ? $decoded : [];
    }

    /** @param array<string, array<string, mixed>> $state */
    public function write(array $state): void
    {
        $temporary = $this->path . '.tmp-' . bin2hex(random_bytes(6));
        $json = json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
        if (file_put_contents($temporary, $json, LOCK_EX) === false || !rename($temporary, $this->path)) {
            @unlink($temporary);
            throw new \RuntimeException('Unable to persist scanner state');
        }
        @chmod($this->path, 0640);
    }
}
