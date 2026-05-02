<?php

declare(strict_types=1);

namespace Imanager\Cache;

use Psr\SimpleCache\CacheInterface;

/**
 * PSR-16 cache backed by the local filesystem.
 *
 * Designed for the recurring iManager use case: caching pre-rendered HTML
 * snippets that took non-trivial PHP work to produce. The hot path is
 * "read this small file, return its body" — an operation the OS page cache
 * answers in microseconds for files that were touched recently. Writes go
 * through a `tmp + rename` dance so concurrent requests can't observe a
 * half-written entry.
 *
 * On-disk layout:
 *   <directory>/<aa>/<bbcc>/<rest-of-sha256>
 * Two-level fanout keeps any one directory comfortable at scale (10k cache
 * keys → ~40 entries per leaf directory on a uniform hash).
 *
 * File format:
 *   First 10 bytes: zero-padded UNIX-timestamp expiry (or "0000000000" for
 *                   never-expires). Followed by `\n`. Body is PHP-serialized.
 *
 * Serialization lets the cache hold any value type (string, array, object)
 * uniformly. For the common HTML-string case the per-entry overhead is a
 * few bytes — negligible against the cache being there at all.
 */
final readonly class FilesystemCache implements CacheInterface
{
    /**
     * Reserved characters per PSR-16 §1.3 — implementations MUST throw on
     * keys containing them so a future driver swap doesn't surprise callers.
     */
    private const RESERVED_KEY_CHARS = ['{', '}', '(', ')', '/', '\\', '@', ':'];

    private const EXPIRY_HEADER_LENGTH = 10;
    private const NEVER_EXPIRES = 0;

    public function __construct(
        private string $directory,
        private ?int $defaultTtlSeconds = null,
    ) {
        if ($directory === '') {
            throw new \InvalidArgumentException('Cache directory must not be empty');
        }
        if (! is_dir($directory) && ! @mkdir($directory, 0o755, true) && ! is_dir($directory)) {
            throw new \RuntimeException(\sprintf('Cannot create cache directory "%s"', $directory));
        }
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $this->assertValidKey($key);
        $entry = $this->readEntry($this->pathFor($key));
        return $entry === null ? $default : $entry[0];
    }

    public function set(string $key, mixed $value, int|\DateInterval|null $ttl = null): bool
    {
        $this->assertValidKey($key);
        $expiry = $this->resolveExpiry($ttl);

        $path = $this->pathFor($key);
        $directory = \dirname($path);
        if (! is_dir($directory) && ! @mkdir($directory, 0o755, true) && ! is_dir($directory)) {
            return false;
        }

        $body = str_pad((string) $expiry, self::EXPIRY_HEADER_LENGTH, '0', \STR_PAD_LEFT)
            . "\n"
            . serialize($value);

        $tmp = $path . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (@file_put_contents($tmp, $body) === false) {
            return false;
        }
        if (! @rename($tmp, $path)) {
            @unlink($tmp);
            return false;
        }
        return true;
    }

    public function delete(string $key): bool
    {
        $this->assertValidKey($key);
        $path = $this->pathFor($key);
        if (! is_file($path)) {
            return true;
        }
        return @unlink($path);
    }

    public function clear(): bool
    {
        if (! is_dir($this->directory)) {
            return true;
        }
        return $this->wipeDirectory($this->directory, deleteRoot: false);
    }

    public function has(string $key): bool
    {
        $this->assertValidKey($key);
        return $this->readEntry($this->pathFor($key)) !== null;
    }

    /**
     * @param iterable<string> $keys
     *
     * @return iterable<string, mixed>
     */
    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        $out = [];
        foreach ($keys as $key) {
            $out[$key] = $this->get($key, $default);
        }
        return $out;
    }

    /**
     * @param iterable<mixed, mixed> $values
     */
    public function setMultiple(iterable $values, int|\DateInterval|null $ttl = null): bool
    {
        $allOk = true;
        foreach ($values as $key => $value) {
            if (! $this->set((string) $key, $value, $ttl)) {
                $allOk = false;
            }
        }
        return $allOk;
    }

    /**
     * @param iterable<string> $keys
     */
    public function deleteMultiple(iterable $keys): bool
    {
        $allOk = true;
        foreach ($keys as $key) {
            if (! $this->delete($key)) {
                $allOk = false;
            }
        }
        return $allOk;
    }

    private function pathFor(string $key): string
    {
        $hash = hash('sha256', $key);
        return $this->directory
            . '/' . substr($hash, 0, 2)
            . '/' . substr($hash, 2, 4)
            . '/' . substr($hash, 6);
    }

    /**
     * @return array{0: mixed}|null `null` for miss / expired, otherwise a
     *                              one-element list holding the actual value
     *                              (so a stored `null` round-trips correctly).
     */
    private function readEntry(string $path): ?array
    {
        if (! is_file($path)) {
            return null;
        }
        $raw = @file_get_contents($path);
        if ($raw === false || \strlen($raw) <= self::EXPIRY_HEADER_LENGTH) {
            return null;
        }

        $expiry = (int) substr($raw, 0, self::EXPIRY_HEADER_LENGTH);
        if ($expiry !== self::NEVER_EXPIRES && $expiry <= time()) {
            @unlink($path);
            return null;
        }

        $serialized = substr($raw, self::EXPIRY_HEADER_LENGTH + 1);
        // serialize(false) === 'b:0;' — distinguish that from a decode failure.
        if ($serialized === serialize(false)) {
            return [false];
        }
        $value = @unserialize($serialized);
        if ($value === false) {
            @unlink($path);
            return null;
        }
        return [$value];
    }

    private function resolveExpiry(int|\DateInterval|null $ttl): int
    {
        if ($ttl instanceof \DateInterval) {
            $now = new \DateTimeImmutable('now');
            return $now->add($ttl)->getTimestamp();
        }
        if ($ttl === null) {
            if ($this->defaultTtlSeconds === null) {
                return self::NEVER_EXPIRES;
            }
            return time() + $this->defaultTtlSeconds;
        }
        if ($ttl <= 0) {
            return time() - 1; // immediately expired
        }
        return time() + $ttl;
    }

    private function assertValidKey(string $key): void
    {
        if ($key === '') {
            throw new InvalidCacheKeyException('Cache key must not be empty');
        }
        foreach (self::RESERVED_KEY_CHARS as $reserved) {
            if (str_contains($key, $reserved)) {
                throw new InvalidCacheKeyException(\sprintf(
                    'Cache key "%s" contains reserved character "%s"',
                    $key,
                    $reserved,
                ));
            }
        }
    }

    private function wipeDirectory(string $directory, bool $deleteRoot): bool
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        $allOk = true;
        foreach ($iterator as $entry) {
            if (! $entry instanceof \SplFileInfo) {
                continue;
            }
            $ok = $entry->isDir()
                ? @rmdir($entry->getPathname())
                : @unlink($entry->getPathname());
            $allOk = $allOk && $ok;
        }
        if ($deleteRoot) {
            $allOk = @rmdir($directory) && $allOk;
        }
        return $allOk;
    }
}
