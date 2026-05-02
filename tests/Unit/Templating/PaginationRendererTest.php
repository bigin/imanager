<?php

declare(strict_types=1);

namespace Imanager\Tests\Unit\Templating;

use Imanager\Query\Pagination;
use Imanager\Templating\PaginationRenderer;
use Imanager\Templating\TemplateRenderer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PaginationRenderer::class)]
final class PaginationRendererTest extends TestCase
{
    private PaginationRenderer $renderer;

    protected function setUp(): void
    {
        $this->renderer = new PaginationRenderer(new TemplateRenderer());
    }

    public function testEmptyWrapperWhenOnlyOnePage(): void
    {
        $html = $this->renderer->render(new Pagination(page: 1, perPage: 10, total: 5));

        self::assertSame('<nav class="pagination"></nav>', $html);
    }

    public function testEmptyWrapperWhenZeroResults(): void
    {
        $html = $this->renderer->render(new Pagination(page: 1, perPage: 10, total: 0));

        self::assertSame('<nav class="pagination"></nav>', $html);
    }

    public function testFewPagesEmitsAllLinksWithoutEllipsis(): void
    {
        // 3 pages → no ellipsis, all numbers shown.
        $html = $this->renderer->render(new Pagination(page: 2, perPage: 10, total: 25));

        self::assertStringNotContainsString('ellipsis', $html);
        self::assertStringContainsString('>1<', $html);
        self::assertStringContainsString('>2<', $html);
        self::assertStringContainsString('>3<', $html);
    }

    public function testCurrentPageIsRenderedAsSpanNotLink(): void
    {
        $html = $this->renderer->render(new Pagination(page: 2, perPage: 10, total: 25));

        self::assertStringContainsString(
            '<span class="current" aria-current="page">2</span>',
            $html,
        );
    }

    public function testPrevDisabledOnFirstPage(): void
    {
        $html = $this->renderer->render(new Pagination(page: 1, perPage: 10, total: 25));

        self::assertStringContainsString('class="prev disabled"', $html);
        self::assertStringNotContainsString('class="prev" href=', $html);
    }

    public function testNextDisabledOnLastPage(): void
    {
        $html = $this->renderer->render(new Pagination(page: 3, perPage: 10, total: 25));

        self::assertStringContainsString('class="next disabled"', $html);
        self::assertStringNotContainsString('class="next" href=', $html);
    }

    public function testPrevAndNextLinksTargetCorrectPages(): void
    {
        $html = $this->renderer->render(
            new Pagination(page: 4, perPage: 10, total: 100),
            hrefPattern: '/blog/page%d/',
        );

        self::assertStringContainsString('href="/blog/page3/"', $html);
        self::assertStringContainsString('href="/blog/page5/"', $html);
    }

    public function testManyPagesEarlyShowsEllipsisOnRightOnly(): void
    {
        // 100 pages, current near beginning → ...last-1 last on right.
        $html = $this->renderer->render(
            new Pagination(page: 2, perPage: 10, total: 1000),
            hrefPattern: '/blog/page%d/',
        );

        self::assertSame(1, substr_count($html, 'class="ellipsis"'));
        self::assertStringContainsString('>99</a>', $html);
        self::assertStringContainsString('>100</a>', $html);
        self::assertStringNotContainsString('>50<', $html);
    }

    public function testManyPagesNearEndShowsEllipsisOnLeftOnly(): void
    {
        $html = $this->renderer->render(
            new Pagination(page: 99, perPage: 10, total: 1000),
            hrefPattern: '/blog/page%d/',
        );

        self::assertSame(1, substr_count($html, 'class="ellipsis"'));
        self::assertStringContainsString('>1</a>', $html);
        self::assertStringContainsString('>2</a>', $html);
        self::assertStringContainsString('>100</a>', $html);
        self::assertStringNotContainsString('>50<', $html);
    }

    public function testManyPagesInTheMiddleShowsTwoEllipses(): void
    {
        $html = $this->renderer->render(
            new Pagination(page: 50, perPage: 10, total: 1000),
            hrefPattern: '/blog/page%d/',
            adjacents: 2,
        );

        self::assertSame(2, substr_count($html, 'class="ellipsis"'));
        self::assertStringContainsString('>1</a>', $html);
        self::assertStringContainsString('>2</a>', $html);
        self::assertStringContainsString('>99</a>', $html);
        self::assertStringContainsString('>100</a>', $html);
        // Adjacents window around 50.
        self::assertStringContainsString('>48</a>', $html);
        self::assertStringContainsString('>52</a>', $html);
    }

    public function testCustomTemplatesOverrideDefaults(): void
    {
        $renderer = new PaginationRenderer(
            new TemplateRenderer(),
            overrides: [
                'wrapper' => '<ul>{{value}}</ul>',
                'link' => '<li><a href="{{href}}">{{counter}}</a></li>',
                'current' => '<li class="active">{{counter}}</li>',
            ],
        );

        $html = $renderer->render(new Pagination(page: 2, perPage: 10, total: 25));

        self::assertStringStartsWith('<ul>', $html);
        self::assertStringContainsString('<li class="active">2</li>', $html);
        self::assertStringContainsString('<li><a href="page1/">1</a></li>', $html);
    }

    public function testRejectsNegativeAdjacents(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->renderer->render(
            new Pagination(page: 1, perPage: 10, total: 100),
            adjacents: -1,
        );
    }

    /**
     * Regression for Phase-1 bug B8 — the 1.x `$counter` referenced after
     * the surrounding loop. We exercise every branch of the page-numbers
     * dispatcher by sweeping `current` from 1 to last across a wide pagination.
     */
    public function testNoBranchOmitsTheCurrentPageMarker(): void
    {
        $total = 1000;
        $perPage = 10;
        $last = (int) ceil($total / $perPage);

        for ($page = 1; $page <= $last; $page++) {
            $html = $this->renderer->render(new Pagination($page, $perPage, $total));
            self::assertStringContainsString(
                '<span class="current" aria-current="page">' . $page . '</span>',
                $html,
                "Current-page marker missing at page={$page}",
            );
        }
    }
}
