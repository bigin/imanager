<?php

declare(strict_types=1);

namespace Imanager\Tests\Unit\Domain;

use Imanager\Domain\Category;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Category::class)]
final class CategoryTest extends TestCase
{
    public function testStoresAllConstructorArgumentsVerbatim(): void
    {
        $c = new Category(
            id: 1,
            name: 'Blog',
            slug: 'blog',
            position: 3,
            created: 1700000000,
            updated: 1700000100,
        );

        self::assertSame(1, $c->id);
        self::assertSame('Blog', $c->name);
        self::assertSame('blog', $c->slug);
        self::assertSame(3, $c->position);
        self::assertSame(1700000000, $c->created);
        self::assertSame(1700000100, $c->updated);
    }

    public function testIdIsNullableForFreshlyConstructedCategories(): void
    {
        $c = new Category(id: null, name: 'Blog', slug: 'blog');

        self::assertNull($c->id);
        self::assertSame(0, $c->position);
        self::assertSame(0, $c->created);
        self::assertSame(0, $c->updated);
    }

    public function testWithIdReturnsACopyCarryingTheAssignedId(): void
    {
        $original = new Category(null, 'Blog', 'blog', 5, 1700000000, 1700000100);
        $assigned = $original->withId(42);

        self::assertNotSame($original, $assigned);
        self::assertNull($original->id);
        self::assertSame(42, $assigned->id);
        self::assertSame('Blog', $assigned->name);
        self::assertSame('blog', $assigned->slug);
        self::assertSame(5, $assigned->position);
        self::assertSame(1700000000, $assigned->created);
        self::assertSame(1700000100, $assigned->updated);
    }

    public function testRejectsZeroOrNegativeIdWhenSet(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Category id');
        new Category(0, 'Blog', 'blog');
    }

    public function testRejectsEmptyName(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Category name');
        new Category(null, '   ', 'blog');
    }

    public function testRejectsEmptySlug(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Category slug');
        new Category(null, 'Blog', '');
    }

    public function testRejectsNegativePosition(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('position');
        new Category(null, 'Blog', 'blog', -1);
    }

    public function testRejectsNegativeTimestamps(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('timestamps');
        new Category(null, 'Blog', 'blog', 0, -1);
    }
}
