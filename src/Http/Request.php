<?php

declare(strict_types=1);

namespace Imanager\Http;

/**
 * Immutable typed wrapper around the inbound request superglobals.
 *
 * Replaces iManager 1.x's `Input` family of magic-property classes with a
 * single, explicit object. Each access channel ($_GET, $_POST, parsed PUT
 * body, parsed PATCH body) has its own method, with the same shape:
 *
 *   $req->get('page')                    — raw `mixed` value or null
 *   $req->get('page', default: 1, cast: 'int')  — cast to int, fall back on miss
 *   $req->getInt('page')                 — typed shortcut, null becomes 0
 *
 * The `cast` parameter accepts `'int'`, `'string'`, `'bool'`, `'float'`.
 * `null` (the default) returns the value untransformed. Array values are
 * passed through unchanged regardless of cast — caller decides what to do
 * with them.
 *
 * Build production instances via {@see fromGlobals()}; tests construct the
 * record directly with whatever shape they need.
 *
 * Deliberately NOT PSR-7. PSR-7's immutable with-pattern doesn't pair
 * well with `$_POST` superglobals, and bringing `psr/http-message` plus an
 * implementation just for the editor's needs would be boilerplate. A thin
 * PSR-7 adapter can be added later when an external integration asks for it.
 */
final readonly class Request
{
    /** @var array<string, mixed> */
    private array $getData;

    /** @var array<string, mixed> */
    private array $postData;

    /** @var array<string, mixed> */
    private array $putData;

    /** @var array<string, mixed> */
    private array $patchData;

    /** @var array<string, mixed> */
    private array $cookies;

    /** @var array<string, mixed> */
    private array $files;

    /** @var array<string, mixed> */
    private array $server;

    /**
     * @param array<string, mixed> $get
     * @param array<string, mixed> $post
     * @param array<string, mixed> $put
     * @param array<string, mixed> $patch
     * @param array<string, mixed> $cookies
     * @param array<string, mixed> $files
     * @param array<string, mixed> $server
     */
    public function __construct(
        array $get = [],
        array $post = [],
        array $put = [],
        array $patch = [],
        array $cookies = [],
        array $files = [],
        array $server = [],
        public string $method = 'GET',
        public string $scheme = 'http',
        public string $host = 'localhost',
        public string $uri = '/',
    ) {
        $this->getData = $get;
        $this->postData = $post;
        $this->putData = $put;
        $this->patchData = $patch;
        $this->cookies = $cookies;
        $this->files = $files;
        $this->server = $server;
    }

    public static function fromGlobals(): self
    {
        /** @var array<string, mixed> $get */
        $get = $_GET;
        /** @var array<string, mixed> $post */
        $post = $_POST;
        /** @var array<string, mixed> $cookies */
        $cookies = $_COOKIE;
        /** @var array<string, mixed> $files */
        $files = $_FILES;
        /** @var array<string, mixed> $server */
        $server = $_SERVER;

        $methodRaw = isset($server['REQUEST_METHOD']) && \is_string($server['REQUEST_METHOD'])
            ? $server['REQUEST_METHOD']
            : 'GET';
        $method = strtoupper($methodRaw);

        $put = [];
        $patch = [];

        if ($method === 'PUT' || $method === 'PATCH') {
            $body = file_get_contents('php://input') ?: '';
            $contentType = isset($server['CONTENT_TYPE']) && \is_string($server['CONTENT_TYPE'])
                ? $server['CONTENT_TYPE']
                : '';
            $parsed = self::parseBody($body, $contentType);

            if ($method === 'PUT') {
                $put = $parsed;
            } else {
                $patch = $parsed;
            }
        }

        $scheme = self::detectScheme($server);
        $host = self::sanitizeHost($server);
        $uri = isset($server['REQUEST_URI']) && \is_string($server['REQUEST_URI'])
            ? $server['REQUEST_URI']
            : '/';

        return new self(
            get: $get,
            post: $post,
            put: $put,
            patch: $patch,
            cookies: $cookies,
            files: $files,
            server: $server,
            method: $method,
            scheme: $scheme,
            host: $host,
            uri: $uri,
        );
    }

    // ─────────────────────────── access channels ───────────────────────────

    public function get(string $key, mixed $default = null, ?string $cast = null): mixed
    {
        return self::pluck($this->getData, $key, $default, $cast);
    }

    public function post(string $key, mixed $default = null, ?string $cast = null): mixed
    {
        return self::pluck($this->postData, $key, $default, $cast);
    }

    public function put(string $key, mixed $default = null, ?string $cast = null): mixed
    {
        return self::pluck($this->putData, $key, $default, $cast);
    }

    public function patch(string $key, mixed $default = null, ?string $cast = null): mixed
    {
        return self::pluck($this->patchData, $key, $default, $cast);
    }

    public function cookie(string $key, ?string $default = null): ?string
    {
        if (! \array_key_exists($key, $this->cookies)) {
            return $default;
        }
        $v = $this->cookies[$key];
        return \is_string($v) ? $v : $default;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function file(string $key): ?array
    {
        if (! \array_key_exists($key, $this->files)) {
            return null;
        }
        $v = $this->files[$key];
        return \is_array($v) ? $v : null;
    }

    // ────────────────────────── typed shortcuts ────────────────────────────

    public function getInt(string $key, int $default = 0): int
    {
        $v = $this->get($key);
        if ($v === null || \is_array($v)) {
            return $default;
        }
        return is_numeric($v) || \is_bool($v) ? (int) $v : $default;
    }

    public function getString(string $key, string $default = ''): string
    {
        $v = $this->get($key);
        if ($v === null || \is_array($v)) {
            return $default;
        }
        return (string) $v;
    }

    public function getBool(string $key, bool $default = false): bool
    {
        $v = $this->get($key);
        if ($v === null || \is_array($v)) {
            return $default;
        }
        return filter_var($v, \FILTER_VALIDATE_BOOLEAN, \FILTER_NULL_ON_FAILURE) ?? $default;
    }

    public function postInt(string $key, int $default = 0): int
    {
        $v = $this->post($key);
        if ($v === null || \is_array($v)) {
            return $default;
        }
        return is_numeric($v) || \is_bool($v) ? (int) $v : $default;
    }

    public function postString(string $key, string $default = ''): string
    {
        $v = $this->post($key);
        if ($v === null || \is_array($v)) {
            return $default;
        }
        return (string) $v;
    }

    public function postBool(string $key, bool $default = false): bool
    {
        $v = $this->post($key);
        if ($v === null || \is_array($v)) {
            return $default;
        }
        return filter_var($v, \FILTER_VALIDATE_BOOLEAN, \FILTER_NULL_ON_FAILURE) ?? $default;
    }

    // ──────────────────────────── meta helpers ─────────────────────────────

    public function isMethod(string $method): bool
    {
        return $this->method === strtoupper($method);
    }

    public function isSecure(): bool
    {
        return $this->scheme === 'https';
    }

    public function header(string $name): ?string
    {
        // PHP exposes headers via $_SERVER as `HTTP_<UPPERCASE_UNDERSCORED>`.
        $key = 'HTTP_' . str_replace('-', '_', strtoupper($name));
        if (! \array_key_exists($key, $this->server)) {
            // Content-Type and Content-Length live without the HTTP_ prefix.
            $alt = str_replace('-', '_', strtoupper($name));
            if (\array_key_exists($alt, $this->server) && \is_string($this->server[$alt])) {
                return $this->server[$alt];
            }
            return null;
        }
        $v = $this->server[$key];
        return \is_string($v) ? $v : null;
    }

    /**
     * @param array<string, mixed> $bag
     */
    private static function pluck(array $bag, string $key, mixed $default, ?string $cast): mixed
    {
        if (! \array_key_exists($key, $bag)) {
            return $default;
        }
        return self::applyCast($bag[$key], $cast);
    }

    private static function applyCast(mixed $value, ?string $cast): mixed
    {
        if ($cast === null) {
            return $value;
        }
        if ($value === null || \is_array($value)) {
            return $value;
        }
        return match ($cast) {
            'int' => (int) $value,
            'string' => (string) $value,
            'float' => (float) $value,
            'bool' => filter_var($value, \FILTER_VALIDATE_BOOLEAN, \FILTER_NULL_ON_FAILURE) ?? false,
            default => throw new \InvalidArgumentException(
                \sprintf('Unknown cast type "%s"', $cast),
            ),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private static function parseBody(string $body, string $contentType): array
    {
        if ($body === '') {
            return [];
        }
        $raw = [];
        if (str_starts_with($contentType, 'application/json')) {
            $decoded = json_decode($body, true);
            if (\is_array($decoded)) {
                /** @var array<int|string, mixed> $decoded */
                $raw = $decoded;
            }
        } else {
            $parsed = [];
            parse_str($body, $parsed);
            $raw = $parsed;
        }

        // parse_str / json_decode can produce int keys via `key[]=v` form
        // syntax. Coerce every top-level key to string so the bag stays
        // shape-stable for downstream consumers.
        $out = [];
        foreach ($raw as $k => $v) {
            $out[(string) $k] = $v;
        }
        return $out;
    }

    /**
     * @param array<string, mixed> $server
     */
    private static function detectScheme(array $server): string
    {
        $https = $server['HTTPS'] ?? '';
        if (\is_string($https) && $https !== '' && strtolower($https) !== 'off') {
            return 'https';
        }
        if (($server['SERVER_PORT'] ?? null) === 443) {
            return 'https';
        }
        $forwarded = $server['HTTP_X_FORWARDED_PROTO'] ?? '';
        if (\is_string($forwarded) && strtolower($forwarded) === 'https') {
            return 'https';
        }
        return 'http';
    }

    /**
     * @param array<string, mixed> $server
     */
    private static function sanitizeHost(array $server): string
    {
        $candidate = $server['HTTP_HOST'] ?? $server['SERVER_NAME'] ?? '';
        if (! \is_string($candidate) || $candidate === '') {
            return 'localhost';
        }
        // Allow letters, digits, dots, hyphens, colons (for port).
        if (preg_match('/^[A-Za-z0-9.\-:]+$/D', $candidate) !== 1) {
            return 'localhost';
        }
        return $candidate;
    }
}
