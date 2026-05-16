<?php

declare(strict_types=1);

namespace Imanager\Http;

/**
 * `$_SESSION`-backed implementation of {@see SessionStore}.
 *
 * All keys are stored under a single namespace prefix on the superglobal so
 * the iManager session keys can't collide with whatever the host application
 * (a host CMS admin shell, a custom embedding) writes to `$_SESSION` directly.
 *
 * The constructor does NOT call `session_start()` — that's a host-app concern
 * and starting it inside a library would surprise callers. If `$_SESSION`
 * isn't initialized when a method is called, every read returns `$default`
 * and writes silently no-op. (A logger could surface this in a future phase.)
 */
final readonly class NativeSessionStore implements SessionStore
{
    public function __construct(private string $namespace = '_imanager')
    {
        if ($namespace === '') {
            throw new \InvalidArgumentException('Session namespace must not be empty');
        }
    }

    public function get(string $key, mixed $default = null): mixed
    {
        if (! isset($_SESSION)) {
            return $default;
        }
        $bucket = $_SESSION[$this->namespace] ?? null;
        if (! \is_array($bucket)) {
            return $default;
        }
        return \array_key_exists($key, $bucket) ? $bucket[$key] : $default;
    }

    public function set(string $key, mixed $value): void
    {
        if (! isset($_SESSION)) {
            return;
        }
        if (! isset($_SESSION[$this->namespace]) || ! \is_array($_SESSION[$this->namespace])) {
            $_SESSION[$this->namespace] = [];
        }
        /** @var array<string, mixed> $bucket */
        $bucket = $_SESSION[$this->namespace];
        $bucket[$key] = $value;
        $_SESSION[$this->namespace] = $bucket;
    }

    public function remove(string $key): void
    {
        if (! isset($_SESSION)) {
            return;
        }
        if (! isset($_SESSION[$this->namespace]) || ! \is_array($_SESSION[$this->namespace])) {
            return;
        }
        /** @var array<string, mixed> $bucket */
        $bucket = $_SESSION[$this->namespace];
        unset($bucket[$key]);
        $_SESSION[$this->namespace] = $bucket;
    }

    public function has(string $key): bool
    {
        if (! isset($_SESSION)) {
            return false;
        }
        $bucket = $_SESSION[$this->namespace] ?? null;
        if (! \is_array($bucket)) {
            return false;
        }
        return \array_key_exists($key, $bucket);
    }
}
