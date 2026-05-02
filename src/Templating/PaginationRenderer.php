<?php

declare(strict_types=1);

namespace Imanager\Templating;

use Imanager\Query\Pagination;

/**
 * Renders a {@see Pagination} value-object into pagination HTML.
 *
 * The 1.x `TemplateParser::renderPagination()` was a 100-line monolith
 * with a `$counter` variable referenced after the surrounding `for` loop
 * exited (Phase-1 analysis bug B8). The new renderer is a clean rewrite:
 *
 *   - Three explicit branches for "few pages" / "near start" / "in the
 *     middle" / "near end" (no shared loop bleeding state).
 *   - Pure string composition through {@see TemplateRenderer}; the per-
 *     element templates are constructor-overridable so themes can supply
 *     their own markup without subclassing.
 *
 * `$hrefPattern` is a `sprintf`-style template applied to each page link.
 * `'/blog/page%d/'` produces `/blog/page1/`, `/blog/page2/`, etc. — host
 * apps that want page 1 to live at `/blog/` (without `page1/`) handle the
 * redirect at the routing layer; this renderer stays URL-agnostic.
 */
final readonly class PaginationRenderer
{
    /** @var array<string, string> */
    private array $templates;

    /**
     * @param array<string, string> $overrides Override individual template fragments
     */
    public function __construct(
        private TemplateRenderer $renderer = new TemplateRenderer(),
        array $overrides = [],
    ) {
        $this->templates = [...self::defaultTemplates(), ...$overrides];
    }

    public function render(
        Pagination $pagination,
        string $hrefPattern = 'page%d/',
        int $adjacents = 3,
    ): string {
        if ($adjacents < 0) {
            throw new \InvalidArgumentException('adjacents must be >= 0');
        }

        $lastPage = $pagination->lastPage();
        if ($lastPage <= 1) {
            return $this->wrap('');
        }

        $body = $this->renderPrev($pagination, $hrefPattern)
            . $this->renderPageNumbers($pagination, $hrefPattern, $adjacents)
            . $this->renderNext($pagination, $hrefPattern);

        return $this->wrap($body);
    }

    private function renderPrev(Pagination $p, string $hrefPattern): string
    {
        if ($p->isFirstPage()) {
            return $this->fragment('prev_inactive', []);
        }
        return $this->fragment('prev', ['href' => self::href($hrefPattern, $p->page - 1)]);
    }

    private function renderNext(Pagination $p, string $hrefPattern): string
    {
        if ($p->isLastPage()) {
            return $this->fragment('next_inactive', []);
        }
        return $this->fragment('next', ['href' => self::href($hrefPattern, $p->page + 1)]);
    }

    private function renderPageNumbers(Pagination $p, string $hrefPattern, int $adjacents): string
    {
        $current = $p->page;
        $last = $p->lastPage();

        // Few pages → emit them all, no ellipsis logic.
        $threshold = 7 + ($adjacents * 2);
        if ($last <= $threshold) {
            return $this->renderRange(1, $last, $current, $hrefPattern);
        }

        $output = '';
        $endOfHead = 3 + ($adjacents * 2);
        $startOfTail = $last - (2 + ($adjacents * 2));

        if ($current < 1 + ($adjacents * 2)) {
            // Near the beginning — show 1..endOfHead, ellipsis, last-1, last.
            $output .= $this->renderRange(1, $endOfHead, $current, $hrefPattern);
            $output .= $this->fragment('ellipsis', []);
            $output .= $this->renderRange($last - 1, $last, $current, $hrefPattern);
        } elseif ($current > $startOfTail) {
            // Near the end — show 1, 2, ellipsis, startOfTail..last.
            $output .= $this->renderRange(1, 2, $current, $hrefPattern);
            $output .= $this->fragment('ellipsis', []);
            $output .= $this->renderRange($startOfTail, $last, $current, $hrefPattern);
        } else {
            // Somewhere in the middle — show 1, 2, ellipsis, window, ellipsis, last-1, last.
            $output .= $this->renderRange(1, 2, $current, $hrefPattern);
            $output .= $this->fragment('ellipsis', []);
            $output .= $this->renderRange(
                $current - $adjacents,
                $current + $adjacents,
                $current,
                $hrefPattern,
            );
            $output .= $this->fragment('ellipsis', []);
            $output .= $this->renderRange($last - 1, $last, $current, $hrefPattern);
        }

        return $output;
    }

    private function renderRange(int $from, int $to, int $current, string $hrefPattern): string
    {
        $output = '';
        for ($i = $from; $i <= $to; $i++) {
            $output .= $this->renderPageLink($i, $current, $hrefPattern);
        }
        return $output;
    }

    private function renderPageLink(int $page, int $current, string $hrefPattern): string
    {
        if ($page === $current) {
            return $this->fragment('current', ['counter' => (string) $page]);
        }
        return $this->fragment('link', [
            'href' => self::href($hrefPattern, $page),
            'counter' => (string) $page,
        ]);
    }

    private function wrap(string $body): string
    {
        return $this->renderer->render($this->templates['wrapper'], ['value' => $body]);
    }

    /**
     * @param array<string, string|int|float|bool|\Stringable|null> $vars
     */
    private function fragment(string $name, array $vars): string
    {
        return $this->renderer->render($this->templates[$name], $vars);
    }

    private static function href(string $pattern, int $page): string
    {
        return \sprintf($pattern, $page);
    }

    /**
     * @return array<string, string>
     */
    private static function defaultTemplates(): array
    {
        return [
            'wrapper'        => '<nav class="pagination">{{value}}</nav>',
            'prev'           => '<a class="prev" href="{{href}}" rel="prev">&laquo;</a>',
            'prev_inactive'  => '<span class="prev disabled" aria-disabled="true">&laquo;</span>',
            'next'           => '<a class="next" href="{{href}}" rel="next">&raquo;</a>',
            'next_inactive'  => '<span class="next disabled" aria-disabled="true">&raquo;</span>',
            'link'           => '<a href="{{href}}">{{counter}}</a>',
            'current'        => '<span class="current" aria-current="page">{{counter}}</span>',
            'ellipsis'       => '<span class="ellipsis" aria-hidden="true">&hellip;</span>',
        ];
    }
}
