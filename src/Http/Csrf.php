<?php

declare(strict_types=1);

namespace Imanager\Http;

/**
 * Token-based CSRF protection.
 *
 * Tokens are generated with `random_bytes()` and stored in a {@see SessionStore}
 * keyed by name. Multi-tab support is built in: the store keeps up to
 * `$maxTokens` tokens; once full, the oldest is evicted FIFO when a new one
 * is requested. This matches iManager 1.x's `maxNumTokens` behavior so
 * editor workflows that open several tabs at once keep working.
 *
 * Validation uses `hash_equals()` for timing safety.
 */
final readonly class Csrf
{
    private const SESSION_KEY = 'csrf_tokens';
    private const TOKEN_BYTES = 32;

    public function __construct(
        private SessionStore $session,
        private int $maxTokens = 10,
    ) {
        if ($maxTokens < 1) {
            throw new \InvalidArgumentException('maxTokens must be >= 1');
        }
    }

    /**
     * Return the existing token for `$name`, or generate and store a fresh
     * one. The same name returned across calls keeps a stable token for an
     * editor session — until {@see rotate()} is called.
     */
    public function token(string $name = 'default'): string
    {
        $tokens = $this->loadTokens();
        if (isset($tokens[$name])) {
            return $tokens[$name];
        }

        $token = bin2hex(random_bytes(self::TOKEN_BYTES));
        $tokens[$name] = $token;

        if (\count($tokens) > $this->maxTokens) {
            $tokens = \array_slice($tokens, -$this->maxTokens, null, true);
        }

        $this->session->set(self::SESSION_KEY, $tokens);
        return $token;
    }

    /**
     * Constant-time validation. Returns false for unknown names, mismatched
     * tokens, and any non-string input.
     */
    public function validate(string $name, string $token): bool
    {
        if ($token === '') {
            return false;
        }
        $tokens = $this->loadTokens();
        if (! isset($tokens[$name])) {
            return false;
        }
        return hash_equals($tokens[$name], $token);
    }

    /**
     * Drop and regenerate the token for `$name`. Use this after a successful
     * state-changing action so a copy of the page can't be replayed.
     */
    public function rotate(string $name = 'default'): string
    {
        $tokens = $this->loadTokens();
        unset($tokens[$name]);
        $this->session->set(self::SESSION_KEY, $tokens);
        return $this->token($name);
    }

    /**
     * Drop every token. Use on logout / session reset.
     */
    public function clear(): void
    {
        $this->session->remove(self::SESSION_KEY);
    }

    /**
     * @return array<string, string>
     */
    private function loadTokens(): array
    {
        $raw = $this->session->get(self::SESSION_KEY, []);
        if (! \is_array($raw)) {
            return [];
        }
        $clean = [];
        foreach ($raw as $name => $token) {
            if (\is_string($name) && \is_string($token)) {
                $clean[$name] = $token;
            }
        }
        return $clean;
    }
}
