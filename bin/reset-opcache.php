<?php

declare(strict_types=1);

header('Content-Type: text/plain');

if (PHP_SAPI !== 'fpm-fcgi') {
    http_response_code(403);
    echo "wrong-sapi\n";
    exit;
}

if (!function_exists('opcache_reset')) {
    http_response_code(503);
    echo "opcache-unavailable\n";
    exit;
}

if (!opcache_reset()) {
    http_response_code(500);
    echo "opcache-reset-failed\n";
    exit;
}

echo "opcache-reset-complete\n";
