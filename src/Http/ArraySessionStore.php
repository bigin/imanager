<?php

declare(strict_types=1);

namespace Imanager\Http;

/**
 * In-memory {@see SessionStore} for tests.
 *
 * Pure PHP-array backing; no superglobal interaction. Each instance is
 * isolated, so test cases that wire one up don't bleed state into each
 * other.
 */
final class ArraySessionStore implements SessionStore
{
    /** @var array<string, mixed> */
    private array $bucket = [];

    public function get(string $key, mixed $default = null): mixed
    {
        return \array_key_exists($key, $this->bucket) ? $this->bucket[$key] : $default;
    }

    public function set(string $key, mixed $value): void
    {
        $this->bucket[$key] = $value;
    }

    public function remove(string $key): void
    {
        unset($this->bucket[$key]);
    }

    public function has(string $key): bool
    {
        return \array_key_exists($key, $this->bucket);
    }
}
