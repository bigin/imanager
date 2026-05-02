<?php

declare(strict_types=1);

namespace Imanager\Validation;

use HTMLPurifier;
use HTMLPurifier_Config;
use Parsedown;

/**
 * Clean-room replacement for the iManager 1.x Sanitizer.
 *
 * The 1.x Sanitizer carried a dozen unported `$this->wire(...)` calls from
 * ProcessWire that would fatal at runtime if the affected codepaths were
 * ever entered. This implementation drops the entire compatibility layer and
 * exposes only the methods the field-type plugins (Phase 7b/c) actually need,
 * each implemented from scratch with predictable PHP/PSR-shaped behavior.
 *
 * Markdown rendering uses `erusev/parsedown` in safe mode (raw HTML escaped);
 * HTML purification uses `ezyang/htmlpurifier` with a conservative tag
 * allowlist. Both are lazy-instantiated so the Sanitizer is cheap to inject
 * even when only the lightweight string methods are used.
 */
final class Sanitizer
{
    private ?Parsedown $parsedown;
    private ?HTMLPurifier $htmlPurifier;

    public function __construct(
        ?Parsedown $parsedown = null,
        ?HTMLPurifier $htmlPurifier = null,
    ) {
        $this->parsedown = $parsedown;
        $this->htmlPurifier = $htmlPurifier;
    }

    // ─────────────────────────────── Strings ───────────────────────────────

    /**
     * Single-line text: strip control characters, collapse all whitespace
     * (incl. newlines and tabs) to single spaces, trim, truncate by Unicode
     * character count.
     */
    public function text(string $value, int $maxLength = 255): string
    {
        $value = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]+/', '', $value);
        $value = (string) preg_replace('/\s+/', ' ', $value);
        $value = trim($value);
        if (mb_strlen($value, 'UTF-8') > $maxLength) {
            $value = mb_substr($value, 0, $maxLength, 'UTF-8');
        }
        return $value;
    }

    /**
     * Multi-line text: strip control characters except CR / LF / Tab,
     * normalize CRLF + CR line endings to LF, trim, truncate.
     */
    public function multiline(string $value, int $maxLength = 65535): string
    {
        $value = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]+/', '', $value);
        $value = str_replace(["\r\n", "\r"], "\n", $value);
        $value = trim($value);
        if (mb_strlen($value, 'UTF-8') > $maxLength) {
            $value = mb_substr($value, 0, $maxLength, 'UTF-8');
        }
        return $value;
    }

    /**
     * URL-safe slug: ASCII-transliterate, lowercase, replace runs of
     * non-`[a-z0-9]` with a single dash, trim leading / trailing dashes.
     */
    public function slug(string $value, int $maxLength = 128): string
    {
        $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        if ($ascii === false) {
            $ascii = $value;
        }
        $value = strtolower($ascii);
        $value = (string) preg_replace('/[^a-z0-9]+/', '-', $value);
        $value = (string) preg_replace('/-+/', '-', $value);
        $value = trim($value, '-');
        if (\strlen($value) > $maxLength) {
            $value = substr($value, 0, $maxLength);
            $value = trim($value, '-');
        }
        return $value;
    }

    /**
     * PHP-style identifier: `[A-Za-z_][A-Za-z0-9_]*` with non-matching runs
     * collapsed to a single underscore. Names that would otherwise start with
     * a digit get a leading underscore prepended so the result is always a
     * valid identifier (or empty if the input had nothing usable).
     */
    public function identifier(string $value, int $maxLength = 30): string
    {
        $value = (string) preg_replace('/[^A-Za-z0-9_]+/', '_', $value);
        $value = (string) preg_replace('/_+/', '_', $value);
        $value = trim($value, '_');
        if ($value !== '' && preg_match('/^[A-Za-z_]/', $value) !== 1) {
            $value = '_' . $value;
        }
        if (\strlen($value) > $maxLength) {
            $value = substr($value, 0, $maxLength);
        }
        return $value;
    }

    /**
     * Filename without any directory component. Strips control characters and
     * any path separator; truncates to `$maxLength` bytes (filesystem-safe).
     */
    public function filename(string $value, int $maxLength = 128): string
    {
        $value = basename($value);
        $value = (string) preg_replace('/[\x00-\x1F\x7F]+/', '', $value);
        $value = str_replace(['/', '\\'], '', $value);
        if (\strlen($value) > $maxLength) {
            $value = substr($value, 0, $maxLength);
        }
        return $value;
    }

    // ──────────────────────────── Validated forms ──────────────────────────

    /**
     * @return string|null Email if valid (per `FILTER_VALIDATE_EMAIL`), else null.
     */
    public function email(string $value): ?string
    {
        $value = trim($value);
        $filtered = filter_var($value, FILTER_VALIDATE_EMAIL);
        return \is_string($filtered) ? $filtered : null;
    }

    /**
     * @return string|null Absolute http(s) URL if valid, else null.
     */
    public function url(string $value): ?string
    {
        $value = trim($value);
        $filtered = filter_var($value, FILTER_VALIDATE_URL);
        if (! \is_string($filtered)) {
            return null;
        }
        $scheme = parse_url($filtered, PHP_URL_SCHEME);
        if ($scheme !== 'http' && $scheme !== 'https') {
            return null;
        }
        return $filtered;
    }

    // ────────────────────────────── Scalars ────────────────────────────────

    /**
     * Coerce any input to int and optionally clamp to `[$min, $max]`.
     * Non-numeric input becomes `0` — caller decides whether `0` is acceptable.
     */
    public function int(mixed $value, ?int $min = null, ?int $max = null): int
    {
        if (\is_int($value)) {
            $n = $value;
        } elseif (\is_bool($value)) {
            $n = $value ? 1 : 0;
        } elseif (\is_float($value)) {
            $n = (int) $value;
        } elseif (\is_string($value) && is_numeric($value)) {
            $n = (int) $value;
        } else {
            $n = 0;
        }
        if ($min !== null && $n < $min) {
            $n = $min;
        }
        if ($max !== null && $n > $max) {
            $n = $max;
        }
        return $n;
    }

    public function float(mixed $value, ?float $min = null, ?float $max = null): float
    {
        if (\is_float($value)) {
            $n = $value;
        } elseif (\is_int($value)) {
            $n = (float) $value;
        } elseif (\is_bool($value)) {
            $n = $value ? 1.0 : 0.0;
        } elseif (\is_string($value) && is_numeric($value)) {
            $n = (float) $value;
        } else {
            $n = 0.0;
        }
        if ($min !== null && $n < $min) {
            $n = $min;
        }
        if ($max !== null && $n > $max) {
            $n = $max;
        }
        return $n;
    }

    /**
     * Boolean coercion using `FILTER_VALIDATE_BOOLEAN`: true / "true" / "yes"
     * / "1" / "on" → true; false / "false" / "no" / "0" / "off" → false;
     * everything else → false.
     */
    public function bool(mixed $value): bool
    {
        $filtered = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        return $filtered ?? false;
    }

    // ─────────────────────────────── HTML ──────────────────────────────────

    /**
     * `htmlspecialchars` with safe defaults (UTF-8, HTML5, both quote styles
     * encoded, invalid sequences replaced).
     */
    public function entities(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }

    /**
     * Render Markdown to HTML in safe mode (raw HTML in the input is escaped).
     * For pipelines that need to *allow* user-supplied HTML, run the result
     * through {@see html()} afterwards.
     */
    public function markdown(string $value): string
    {
        return $this->parsedown()->text($value);
    }

    /**
     * Run user-supplied HTML through HTMLPurifier with a conservative tag
     * allowlist. Pass a custom `HTMLPurifier` to the constructor to override
     * the policy.
     */
    public function html(string $value): string
    {
        return $this->purifier()->purify($value);
    }

    private function parsedown(): Parsedown
    {
        if ($this->parsedown === null) {
            $this->parsedown = new Parsedown();
            $this->parsedown->setSafeMode(true);
        }
        return $this->parsedown;
    }

    private function purifier(): HTMLPurifier
    {
        if ($this->htmlPurifier === null) {
            $config = HTMLPurifier_Config::createDefault();
            // No on-disk cache; some hosts disallow writes outside the project root.
            $config->set('Cache.DefinitionImpl', null);
            $config->set(
                'HTML.Allowed',
                'p,br,strong,em,u,s,a[href|title],h1,h2,h3,h4,h5,h6,ul,ol,li,'
                    . 'blockquote,pre,code,img[src|alt|title],hr,'
                    . 'table,thead,tbody,tr,th,td',
            );
            $config->set('AutoFormat.AutoParagraph', false);
            $config->set('AutoFormat.RemoveEmpty', true);
            $this->htmlPurifier = new HTMLPurifier($config);
        }
        return $this->htmlPurifier;
    }
}
