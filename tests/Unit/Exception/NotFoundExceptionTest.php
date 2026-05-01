<?php

declare(strict_types=1);

namespace Imanager\Tests\Unit\Exception;

use Imanager\Exception\NotFoundException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(NotFoundException::class)]
final class NotFoundExceptionTest extends TestCase
{
    public function testCategoryFactoryAcceptsNumericId(): void
    {
        self::assertSame(
            'Category "42" not found',
            NotFoundException::category(42)->getMessage(),
        );
    }

    public function testCategoryFactoryAcceptsSlug(): void
    {
        self::assertSame(
            'Category "blog" not found',
            NotFoundException::category('blog')->getMessage(),
        );
    }

    public function testFieldFactoryFormatsCategoryAndIdentifier(): void
    {
        self::assertSame(
            'Field "title" not found in category 7',
            NotFoundException::field(7, 'title')->getMessage(),
        );

        self::assertSame(
            'Field "3" not found in category 7',
            NotFoundException::field(7, 3)->getMessage(),
        );
    }

    public function testItemFactoryFormatsCategoryAndId(): void
    {
        self::assertSame(
            'Item 99 not found in category 5',
            NotFoundException::item(5, 99)->getMessage(),
        );
    }
}
