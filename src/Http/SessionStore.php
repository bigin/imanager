<?php

declare(strict_types=1);

namespace Imanager\Http;

/**
 * Minimal session-storage abstraction.
 *
 * Decouples {@see Csrf} (and any future stateful HTTP component) from
 * `$_SESSION`, so the same code works under PHP-FPM, in CLI tests, in a
 * long-running worker, or behind a session driver that stores tokens in
 * Redis. Two implementations ship here:
 *
 *   - {@see NativeSessionStore} — `$_SESSION` superglobal, namespaced.
 *   - {@see ArraySessionStore}  — in-memory, used in tests.
 */
interface SessionStore
{
    public function get(string $key, mixed $default = null): mixed;

    public function set(string $key, mixed $value): void;

    public function remove(string $key): void;

    public function has(string $key): bool;
}
