<?php

declare(strict_types=1);

use KvsAvatarModerator\AtomicFilePublisher;
use KvsAvatarModerator\AuditLogger;
use KvsAvatarModerator\AvatarModerator;
use KvsAvatarModerator\CloudflareCachePurger;
use KvsAvatarModerator\HookAuthenticator;
use KvsAvatarModerator\ImageNormalizer;
use KvsAvatarModerator\ModerationClientInterface;
use KvsAvatarModerator\PathGuard;
use KvsAvatarModerator\PolicyEngine;
use KvsAvatarModerator\UploadModerator;

require dirname(__DIR__) . '/bootstrap.php';

final class FakeModerationClient implements ModerationClientInterface
{
    /** @param array<string, mixed>|Throwable $response */
    public function __construct(private readonly array|Throwable $response)
    {
    }

    public function moderate(string $dataUrl): array
    {
        assertTrue(str_starts_with($dataUrl, 'data:image/'), 'moderation receives a data URL');
        if ($this->response instanceof Throwable) {
            throw $this->response;
        }
        return $this->response;
    }
}

$failures = 0;
$tests = [];

function test(string $name, callable $callback): void
{
    global $tests;
    $tests[$name] = $callback;
}

function assertTrue(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function removeTree(string $path): void
{
    if (!is_dir($path)) {
        return;
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($iterator as $file) {
        $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    }
    rmdir($path);
}

function fixturePng(string $path): void
{
    $image = imagecreatetruecolor(2, 2);
    if (!$image instanceof GdImage) {
        throw new RuntimeException('Could not allocate PNG fixture');
    }
    $color = imagecolorallocate($image, 40, 120, 200);
    imagefill($image, 0, 0, $color);
    if (!imagepng($image, $path)) {
        imagedestroy($image);
        throw new RuntimeException('Could not create PNG fixture');
    }
    imagedestroy($image);
}

/** @param array<string, mixed>|Throwable $response */
function moderator(string $avatarRoot, string $storageRoot, array|Throwable $response): AvatarModerator
{
    return new AvatarModerator(
        $avatarRoot,
        $storageRoot,
        dirname(__DIR__) . '/assets/avatar-policy-violation.png',
        dirname(__DIR__) . '/assets/avatar-under-review.png',
        new ImageNormalizer(5_242_880, 4096, 64, 85),
        new FakeModerationClient($response),
        new PolicyEngine(true, ['sexual', 'violence', 'violence/graphic', 'self-harm']),
        new AtomicFilePublisher(),
        new AuditLogger($storageRoot),
    );
}

/** @param array<string, mixed>|Throwable $response */
function uploadModerator(string $storageRoot, array|Throwable $response): UploadModerator
{
    return new UploadModerator(
        $storageRoot,
        dirname(__DIR__) . '/assets/avatar-policy-violation.png',
        dirname(__DIR__) . '/assets/avatar-under-review.png',
        new ImageNormalizer(5_242_880, 4096, 64, 85),
        new FakeModerationClient($response),
        new PolicyEngine(true, ['sexual', 'violence', 'violence/graphic', 'self-harm']),
        new AuditLogger($storageRoot),
    );
}

function moderationResponse(bool $flagged, array $categories = []): array
{
    return [
        'id' => 'modr_test',
        'model' => 'omni-moderation-latest',
        'result' => [
            'flagged' => $flagged,
            'categories' => $categories,
            'category_scores' => array_map(static fn (bool $value): float => $value ? 0.99 : 0.01, $categories),
        ],
    ];
}

test('policy engine approves a clean result', static function (): void {
    $decision = (new PolicyEngine(true, ['sexual']))->decide([
        'flagged' => false,
        'categories' => ['sexual' => false],
        'category_scores' => ['sexual' => 0.01],
    ]);
    assertTrue($decision->approved, 'clean result should be approved');
});

test('policy engine blocks a configured category', static function (): void {
    $decision = (new PolicyEngine(false, ['sexual']))->decide([
        'flagged' => true,
        'categories' => ['sexual' => true],
        'category_scores' => ['sexual' => 0.99],
    ]);
    assertTrue(!$decision->approved && $decision->violations === ['sexual'], 'sexual category should be blocked');
});

test('path guard rejects traversal outside the avatar root', static function (): void {
    $root = sys_get_temp_dir() . '/kvs-avatar-path-' . bin2hex(random_bytes(4));
    mkdir($root);
    $outside = dirname($root) . '/outside-' . bin2hex(random_bytes(4)) . '.jpg';
    file_put_contents($outside, 'x');
    try {
        PathGuard::resolveRelative($root, '../' . basename($outside));
        throw new RuntimeException('traversal was not rejected');
    } catch (RuntimeException $exception) {
        assertTrue(str_contains($exception->getMessage(), 'outside') || str_contains($exception->getMessage(), 'missing'), 'expected traversal rejection');
    } finally {
        unlink($outside);
        rmdir($root);
    }
});

test('signed hook rejects nonce replay', static function (): void {
    $root = sys_get_temp_dir() . '/kvs-avatar-nonce-' . bin2hex(random_bytes(4));
    mkdir($root);
    $secret = str_repeat('s', 32);
    $body = '{"path":"1/a.jpg"}';
    $timestamp = (string) time();
    $nonce = str_repeat('n', 24);
    $signature = HookAuthenticator::sign($body, $timestamp, $nonce, $secret);
    $auth = new HookAuthenticator($secret, $root, 300);
    $auth->verify($body, $timestamp, $nonce, $signature);
    try {
        $auth->verify($body, $timestamp, $nonce, $signature);
        throw new RuntimeException('nonce replay was not rejected');
    } catch (RuntimeException $exception) {
        assertTrue(str_contains($exception->getMessage(), 'already'), 'expected nonce replay rejection');
    } finally {
        removeTree($root);
    }
});

test('approved avatar is normalized and published', static function (): void {
    $root = sys_get_temp_dir() . '/kvs-avatar-ok-' . bin2hex(random_bytes(4));
    $avatars = $root . '/avatars';
    $storage = $root . '/private';
    mkdir($avatars, 0777, true);
    mkdir($storage, 0777, true);
    $path = $avatars . '/avatar.png';
    fixturePng($path);
    try {
        $result = moderator($avatars, $storage, moderationResponse(false, ['sexual' => false]))->moderate($path, $path, 123);
        $size = getimagesize($path);
        assertTrue($result['status'] === 'approved', 'avatar should be approved');
        assertTrue(is_array($size) && $size[0] === 64 && $size[1] === 64, 'avatar should be square and normalized');
    } finally {
        removeTree($root);
    }
});

test('flagged avatar is replaced and quarantined', static function (): void {
    $root = sys_get_temp_dir() . '/kvs-avatar-block-' . bin2hex(random_bytes(4));
    $avatars = $root . '/avatars';
    $storage = $root . '/private';
    mkdir($avatars, 0777, true);
    mkdir($storage, 0777, true);
    $path = $avatars . '/avatar.png';
    fixturePng($path);
    try {
        $result = moderator($avatars, $storage, moderationResponse(true, ['sexual' => true]))->moderate($path, $path, 456);
        assertTrue($result['status'] === 'violation_replaced', 'flagged avatar should be replaced');
        assertTrue(is_file($storage . '/' . $result['quarantine_path']), 'original should be quarantined');
        assertTrue((filesize($path) ?: 0) > 100, 'replacement should be a real image');
    } finally {
        removeTree($root);
    }
});

test('API failure publishes under-review image and keeps retry source', static function (): void {
    $root = sys_get_temp_dir() . '/kvs-avatar-retry-' . bin2hex(random_bytes(4));
    $avatars = $root . '/avatars';
    $storage = $root . '/private';
    mkdir($avatars, 0777, true);
    mkdir($storage, 0777, true);
    $path = $avatars . '/avatar.png';
    fixturePng($path);
    try {
        $result = moderator($avatars, $storage, new RuntimeException('simulated outage'))->moderate($path, $path, 789);
        assertTrue($result['status'] === 'review_required', 'failure should require review');
        assertTrue(is_file($storage . '/' . $result['retry_source']), 'retry source should be private');
    } finally {
        removeTree($root);
    }
});

test('malformed image is replaced without an API call', static function (): void {
    $root = sys_get_temp_dir() . '/kvs-avatar-invalid-' . bin2hex(random_bytes(4));
    $avatars = $root . '/avatars';
    $storage = $root . '/private';
    mkdir($avatars, 0777, true);
    mkdir($storage, 0777, true);
    $path = $avatars . '/avatar.jpg';
    file_put_contents($path, '<svg onload="alert(1)"></svg>');
    try {
        $result = moderator($avatars, $storage, moderationResponse(false))->moderate($path, $path, 999);
        assertTrue($result['status'] === 'invalid_replaced', 'malformed image should be replaced');
        assertTrue((new finfo(FILEINFO_MIME_TYPE))->file($path) === 'image/jpeg', 'replacement should match the target extension');
    } finally {
        removeTree($root);
    }
});

test('pre-upload avatar is forced to a normalized JPEG', static function (): void {
    $root = sys_get_temp_dir() . '/kvs-avatar-pre-ok-' . bin2hex(random_bytes(4));
    $storage = $root . '/private';
    mkdir($storage, 0777, true);
    $upload = $root . '/php-upload';
    fixturePng($upload);
    $inodeBefore = fileinode($upload);
    try {
        $result = uploadModerator($storage, moderationResponse(false, ['sexual' => false]))->moderate($upload, 1001);
        clearstatcache(true, $upload);
        $inodeAfter = fileinode($upload);
        $size = getimagesize($upload);
        assertTrue($result['status'] === 'approved', 'pre-upload avatar should be approved');
        assertTrue($inodeBefore !== false && $inodeBefore === $inodeAfter, 'pre-upload rewrite must preserve the PHP upload inode');
        assertTrue((new finfo(FILEINFO_MIME_TYPE))->file($upload) === 'image/jpeg', 'pre-upload output should be JPEG');
        assertTrue(is_array($size) && $size[0] === 64 && $size[1] === 64, 'pre-upload avatar should be square');
    } finally {
        removeTree($root);
    }
});

test('pre-upload invalid data is quarantined and replaced', static function (): void {
    $root = sys_get_temp_dir() . '/kvs-avatar-pre-invalid-' . bin2hex(random_bytes(4));
    $storage = $root . '/private';
    mkdir($storage, 0777, true);
    $upload = $root . '/php-upload';
    file_put_contents($upload, '<svg onload="alert(1)"></svg>');
    try {
        $result = uploadModerator($storage, moderationResponse(false))->moderate($upload, 1002);
        assertTrue($result['status'] === 'invalid_replaced', 'invalid pre-upload should be replaced');
        assertTrue(is_file($storage . '/' . $result['quarantine_path']), 'invalid pre-upload should be quarantined');
        assertTrue((new finfo(FILEINFO_MIME_TYPE))->file($upload) === 'image/jpeg', 'invalid pre-upload replacement should be JPEG');
    } finally {
        removeTree($root);
    }
});

test('pre-upload API failure publishes a pending JPEG', static function (): void {
    $root = sys_get_temp_dir() . '/kvs-avatar-pre-retry-' . bin2hex(random_bytes(4));
    $storage = $root . '/private';
    mkdir($storage, 0777, true);
    $upload = $root . '/php-upload';
    fixturePng($upload);
    try {
        $result = uploadModerator($storage, new RuntimeException('simulated outage'))->moderate($upload, 1003);
        assertTrue($result['status'] === 'review_required', 'pre-upload failure should require review');
        assertTrue(is_file($storage . '/' . $result['retry_source']), 'pre-upload retry source should be quarantined');
        assertTrue((new finfo(FILEINFO_MIME_TYPE))->file($upload) === 'image/jpeg', 'pending pre-upload replacement should be JPEG');
    } finally {
        removeTree($root);
    }
});

test('Cloudflare purger targets only the exact KVS member avatar URL', static function (): void {
    $captured = [];
    $purger = new CloudflareCachePurger(
        'test-cache-purge-token',
        str_repeat('a', 32),
        'https://theync.com',
        10,
        static function (string $endpoint, string $body, array $headers, int $timeout) use (&$captured): array {
            $captured = compact('endpoint', 'body', 'headers', 'timeout');
            return ['status' => 200, 'body' => '{"success":true,"errors":[]}'];
        },
    );

    $result = $purger->purgeAvatar(75378, '75000');
    $payload = json_decode($captured['body'] ?? '', true);
    assertTrue($result['url'] === 'https://theync.com/contents/avatars/75000/75378.jpg', 'purger should return the exact avatar URL');
    assertTrue(($payload['files'] ?? null) === [$result['url']], 'purge request should contain only the exact avatar URL');
    assertTrue(($captured['endpoint'] ?? '') === 'https://api.cloudflare.com/client/v4/zones/' . str_repeat('a', 32) . '/purge_cache', 'purger should use the configured zone endpoint');
    assertTrue(in_array('Authorization: Bearer test-cache-purge-token', $captured['headers'] ?? [], true), 'purger should use bearer token authentication');
});

test('Cloudflare purger fails closed on an unsuccessful API response', static function (): void {
    $purger = new CloudflareCachePurger(
        'test-cache-purge-token',
        str_repeat('b', 32),
        'https://theync.com',
        10,
        static fn (): array => ['status' => 403, 'body' => '{"success":false,"errors":[{"code":10000}]}'],
    );

    try {
        $purger->purgeAvatar(75378, '75000');
        throw new RuntimeException('unsuccessful purge response was not rejected');
    } catch (RuntimeException $exception) {
        assertTrue(str_contains($exception->getMessage(), 'HTTP 403'), 'purge error should include the HTTP status');
    }
});

foreach ($tests as $name => $callback) {
    try {
        $callback();
        fwrite(STDOUT, "PASS {$name}\n");
    } catch (Throwable $exception) {
        $failures++;
        fwrite(STDERR, "FAIL {$name}: {$exception->getMessage()}\n");
    }
}

fwrite(STDOUT, sprintf("%d tests, %d failures\n", count($tests), $failures));
exit($failures === 0 ? 0 : 1);
