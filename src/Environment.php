<?php

declare(strict_types=1);

namespace KvsAvatarModerator;

final class Environment
{
    public static function load(string $path): void
    {
        if (!is_file($path) || !is_readable($path)) {
            return;
        }

        foreach (file($path, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            $separator = strpos($line, '=');
            if ($separator === false) {
                continue;
            }

            $name = trim(substr($line, 0, $separator));
            if (!preg_match('/^[A-Z_][A-Z0-9_]*$/i', $name) || getenv($name) !== false) {
                continue;
            }

            $value = trim(substr($line, $separator + 1));
            if (strlen($value) >= 2 && (($value[0] === '"' && str_ends_with($value, '"')) || ($value[0] === "'" && str_ends_with($value, "'")))) {
                $value = substr($value, 1, -1);
            }

            putenv($name . '=' . $value);
            $_ENV[$name] = $value;
        }
    }

    public static function get(string $name, ?string $default = null): ?string
    {
        $value = getenv($name);
        return $value === false || trim($value) === '' ? $default : $value;
    }

    public static function require(string $name): string
    {
        $value = self::get($name);
        if ($value === null) {
            throw new \RuntimeException("Missing required environment variable: {$name}");
        }
        return $value;
    }

    public static function bool(string $name, bool $default): bool
    {
        $value = self::get($name);
        if ($value === null) {
            return $default;
        }
        $parsed = filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
        if ($parsed === null) {
            throw new \RuntimeException("{$name} must be true or false");
        }
        return $parsed;
    }

    public static function int(string $name, int $default, int $min, int $max): int
    {
        $value = self::get($name);
        if ($value === null) {
            return $default;
        }
        $parsed = filter_var($value, FILTER_VALIDATE_INT);
        if ($parsed === false || $parsed < $min || $parsed > $max) {
            throw new \RuntimeException("{$name} must be between {$min} and {$max}");
        }
        return $parsed;
    }
}
