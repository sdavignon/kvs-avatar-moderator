<?php

declare(strict_types=1);

namespace KvsAvatarModerator;

final class AtomicFilePublisher
{
    public function replace(string $preparedFile, string $target): void
    {
        $directory = dirname($target);
        if (!is_dir($directory) || !is_writable($directory)) {
            throw new \RuntimeException("Avatar directory is not writable: {$directory}");
        }

        $temporary = $directory . DIRECTORY_SEPARATOR . '.' . basename($target) . '.moderating-' . bin2hex(random_bytes(8));
        if (!copy($preparedFile, $temporary)) {
            throw new \RuntimeException('Unable to stage the avatar replacement');
        }

        $mode = fileperms($target);
        @chmod($temporary, $mode === false ? 0640 : ($mode & 0777));
        if (!rename($temporary, $target)) {
            @unlink($temporary);
            throw new \RuntimeException('Unable to publish the avatar replacement atomically');
        }
        clearstatcache(true, $target);
    }
}
