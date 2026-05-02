<?php

declare(strict_types=1);

namespace Imanager\Http;

/**
 * Path-segment parser with optional pagination tail extraction.
 *
 * Splits a request path like `/blog/category/page2/` into the segments
 * `['blog', 'category']` and `pageNumber = 2`. The pagination tail is
 * recognised by a configurable prefix (default `page`) followed by digits;
 * anything else is left as a regular segment.
 *
 * The class is immutable: every parse produces a new instance.
 */
final readonly class UrlSegments
{
    /**
     * @param list<string> $segments
     */
    public function __construct(
        public array $segments,
        public int $pageNumber = 0,
    ) {}

    public static function fromPath(string $path, string $pageSegmentPrefix = 'page'): self
    {
        $clean = trim((string) parse_url($path, \PHP_URL_PATH), '/');
        if ($clean === '') {
            return new self([], 0);
        }

        $parts = array_values(array_filter(
            explode('/', $clean),
            static fn(string $s): bool => $s !== '',
        ));

        $page = 0;
        if ($parts !== [] && $pageSegmentPrefix !== '') {
            $last = $parts[\count($parts) - 1];
            $pattern = '/^' . preg_quote($pageSegmentPrefix, '/') . '(\d+)$/i';
            if (preg_match($pattern, $last, $m) === 1) {
                $page = (int) $m[1];
                array_pop($parts);
            }
        }

        return new self($parts, $page);
    }

    public function get(int $index): ?string
    {
        return $this->segments[$index] ?? null;
    }

    public function first(): ?string
    {
        return $this->segments[0] ?? null;
    }

    public function last(): ?string
    {
        if ($this->segments === []) {
            return null;
        }
        return $this->segments[\count($this->segments) - 1];
    }

    public function count(): int
    {
        return \count($this->segments);
    }

    public function isEmpty(): bool
    {
        return $this->segments === [];
    }

    public function path(bool $trailingSlash = true): string
    {
        if ($this->segments === []) {
            return '';
        }
        return implode('/', $this->segments) . ($trailingSlash ? '/' : '');
    }
}
