<?php

declare(strict_types=1);

namespace Imanager\Tests\Unit\Search;

use Imanager\Search\SearchHit;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SearchHit::class)]
final class SearchHitTest extends TestCase
{
    public function testCarriesAllFieldsAsReadonlyProperties(): void
    {
        $hit = new SearchHit(
            itemId: 42,
            categoryId: 5,
            snippet: 'Hello <b>World</b>',
            rank: -1.234,
        );

        self::assertSame(42, $hit->itemId);
        self::assertSame(5, $hit->categoryId);
        self::assertSame('Hello <b>World</b>', $hit->snippet);
        self::assertSame(-1.234, $hit->rank);
    }
}
