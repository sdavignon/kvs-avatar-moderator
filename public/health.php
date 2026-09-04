<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
echo json_encode([
    'status' => 'ok',
    'service' => 'kvs-avatar-moderator',
    'version' => '1.0.0',
    'time' => gmdate('c'),
], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
