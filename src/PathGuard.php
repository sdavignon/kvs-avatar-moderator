<?php

declare(strict_types=1);

namespace KvsAvatarModerator;

final class PathGuard
{
    public static function existingFileInside(string $root, string $path): string
    {
        $rootReal = realpath($root);
        $pathReal = realpath($path);
        if ($rootReal === false || $pathReal === false || !is_file($pathReal) || is_link($path)) {
            throw new \RuntimeException('Avatar path is missing, is not a regular file, or is a symbolic link');
        }

        $prefix = rtrim($rootReal, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        $candidate = $pathReal;
        if (DIRECTORY_SEPARATOR === '\\') {
            $prefix = strtolower($prefix);
            $candidate = strtolower($candidate);
        }
        if (!str_starts_with($candidate, $prefix)) {
            throw new \RuntimeException('Avatar path is outside the configured root');
        }
        return $pathReal;
    }

    public static function resolveRelative(string $root, string $relative): string
    {
        if ($relative === '' || str_contains($relative, "\0") || preg_match('/^(?:[A-Za-z]:[\\\\\/]|[\\\\\/])/', $relative)) {
            throw new \RuntimeException('Avatar path must be relative');
        }
        $candidate = rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $relative);
        return self::existingFileInside($root, $candidate);
    }

    public static function resolveRelativeTarget(string $root, string $relative): string
    {
        if ($relative === '' || str_contains($relative, "\0") || preg_match('/^(?:[A-Za-z]:[\\\\\/]|[\\\\\/])/', $relative)) {
            throw new \RuntimeException('Avatar path must be relative');
        }
        $normalized = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $relative);
        if (in_array('..', explode(DIRECTORY_SEPARATOR, $normalized), true)) {
            throw new \RuntimeException('Avatar path traversal is not allowed');
        }
        $candidate = rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . ltrim($normalized, DIRECTORY_SEPARATOR);
        if (is_link($candidate)) {
            throw new \RuntimeException('Avatar target cannot be a symbolic link');
        }
        $parent = realpath(dirname($candidate));
        $rootReal = realpath($root);
        if ($parent === false || $rootReal === false || !is_dir($parent)) {
            throw new \RuntimeException('Avatar target directory does not exist');
        }
        $prefix = rtrim($rootReal, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        $checkedParent = rtrim($parent, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        if (DIRECTORY_SEPARATOR === '\\') {
            $prefix = strtolower($prefix);
            $checkedParent = strtolower($checkedParent);
        }
        if (!str_starts_with($checkedParent, $prefix) && rtrim($checkedParent, DIRECTORY_SEPARATOR) !== rtrim($rootReal, DIRECTORY_SEPARATOR)) {
            throw new \RuntimeException('Avatar target is outside the configured root');
        }
        return $candidate;
    }
}
