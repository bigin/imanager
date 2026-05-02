<?php

declare(strict_types=1);

namespace Imanager\Tests\Unit\Query;

use Imanager\Query\Pagination;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Pagination::class)]
final class PaginationTest extends TestCase
{
    public function testReadsBackTheConstructorArguments(): void
    {
        $p = new Pagination(page: 2, perPage: 10, total: 47);

        self::assertSame(2, $p->page);
        self::assertSame(10, $p->perPage);
        self::assertSame(47, $p->total);
    }

    public function testDerivedHelpers(): void
    {
        $p = new Pagination(page: 2, perPage: 10, total: 47);

        self::assertSame(5, $p->lastPage());
        self::assertSame(10, $p->offset());
        self::assertTrue($p->hasMore());
        self::assertFalse($p->isFirstPage());
        self::assertFalse($p->isLastPage());
    }

    public function testFirstPageMarkers(): void
    {
        $p = new Pagination(page: 1, perPage: 10, total: 47);

        self::assertTrue($p->isFirstPage());
        self::assertFalse($p->isLastPage());
        self::assertSame(0, $p->offset());
    }

    public function testLastPageMarkers(): void
    {
        $p = new Pagination(page: 5, perPage: 10, total: 47);

        self::assertFalse($p->hasMore());
        self::assertTrue($p->isLastPage());
        self::assertSame(40, $p->offset());
    }

    public function testEmptyResultSetReportsOneLastPageAndNoMore(): void
    {
        $p = new Pagination(page: 1, perPage: 10, total: 0);

        self::assertSame(1, $p->lastPage());
        self::assertFalse($p->hasMore());
        self::assertTrue($p->isFirstPage());
        self::assertTrue($p->isLastPage());
    }

    public function testRejectsZeroOrNegativePage(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Pagination(page: 0, perPage: 10, total: 5);
    }

    public function testRejectsZeroOrNegativePerPage(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Pagination(page: 1, perPage: 0, total: 5);
    }

    public function testRejectsNegativeTotal(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Pagination(page: 1, perPage: 10, total: -1);
    }
}
