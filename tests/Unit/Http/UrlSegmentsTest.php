<?php

declare(strict_types=1);

namespace Imanager\Tests\Unit\Http;

use Imanager\Http\UrlSegments;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(UrlSegments::class)]
final class UrlSegmentsTest extends TestCase
{
    /**
     * @return iterable<string, array{0: string, 1: list<string>, 2: int}>
     */
    public static function paths(): iterable
    {
        yield 'empty path'              => ['/',                 [],                          0];
        yield 'single segment'          => ['/blog',             ['blog'],                    0];
        yield 'multiple segments'       => ['/blog/category',    ['blog', 'category'],        0];
        yield 'trailing slash'          => ['/blog/category/',   ['blog', 'category'],        0];
        yield 'duplicate slashes'       => ['/blog//category//', ['blog', 'category'],        0];
        yield 'paginated tail'          => ['/blog/page2',       ['blog'],                    2];
        yield 'paginated tail nested'   => ['/blog/category/page3/', ['blog', 'category'],    3];
        yield 'page-like segment kept'  => ['/blog/page',        ['blog', 'page'],            0]; // no digits → not pagination
        yield 'query string ignored'    => ['/blog?foo=bar',     ['blog'],                    0];
    }

    /**
     * @param list<string> $expected
     */
    #[DataProvider('paths')]
    public function testFromPath(string $input, array $expected, int $page): void
    {
        $segments = UrlSegments::fromPath($input);

        self::assertSame($expected, $segments->segments);
        self::assertSame($page, $segments->pageNumber);
    }

    public function testGetReturnsSegmentByIndex(): void
    {
        $segments = UrlSegments::fromPath('/blog/category/post');

        self::assertSame('blog', $segments->get(0));
        self::assertSame('category', $segments->get(1));
        self::assertSame('post', $segments->get(2));
        self::assertNull($segments->get(99));
    }

    public function testFirstAndLast(): void
    {
        $segments = UrlSegments::fromPath('/a/b/c');

        self::assertSame('a', $segments->first());
        self::assertSame('c', $segments->last());
    }

    public function testFirstAndLastOnEmptyArrayReturnNull(): void
    {
        $segments = UrlSegments::fromPath('/');

        self::assertNull($segments->first());
        self::assertNull($segments->last());
        self::assertTrue($segments->isEmpty());
    }

    public function testPathRoundTripsWithTrailingSlash(): void
    {
        $segments = UrlSegments::fromPath('/blog/category');

        self::assertSame('blog/category/', $segments->path());
        self::assertSame('blog/category', $segments->path(trailingSlash: false));
    }

    public function testPathOnEmptyReturnsEmptyString(): void
    {
        self::assertSame('', UrlSegments::fromPath('/')->path());
    }

    public function testCustomPageSegmentPrefix(): void
    {
        $segments = UrlSegments::fromPath('/blog/seite4/', pageSegmentPrefix: 'seite');

        self::assertSame(['blog'], $segments->segments);
        self::assertSame(4, $segments->pageNumber);
    }

    public function testEmptyPaginationPrefixDisablesDetection(): void
    {
        $segments = UrlSegments::fromPath('/blog/page2', pageSegmentPrefix: '');

        self::assertSame(['blog', 'page2'], $segments->segments);
        self::assertSame(0, $segments->pageNumber);
    }
}
