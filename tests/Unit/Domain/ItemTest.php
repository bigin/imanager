<?php

declare(strict_types=1);

namespace Imanager\Tests\Unit\Domain;

use Imanager\Domain\Item;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Item::class)]
final class ItemTest extends TestCase
{
    public function testStoresAllConstructorArgumentsVerbatim(): void
    {
        $i = new Item(
            id: 99,
            categoryId: 1,
            name: 'first-post',
            label: 'First Post',
            position: 0,
            active: true,
            data: ['title' => 'Hello', 'tags' => ['php', 'cms']],
            created: 1700000000,
            updated: 1700000100,
        );

        self::assertSame(99, $i->id);
        self::assertSame(1, $i->categoryId);
        self::assertSame('first-post', $i->name);
        self::assertSame('First Post', $i->label);
        self::assertSame(0, $i->position);
        self::assertTrue($i->active);
        self::assertSame(['title' => 'Hello', 'tags' => ['php', 'cms']], $i->data);
    }

    public function testItemsDefaultToActiveAndEmptyData(): void
    {
        $i = new Item(null, 1);

        self::assertNull($i->id);
        self::assertNull($i->name);
        self::assertNull($i->label);
        self::assertTrue($i->active);
        self::assertSame([], $i->data);
    }

    public function testWithIdReturnsACopyCarryingTheAssignedId(): void
    {
        $original = new Item(null, 1, name: 'first', data: ['x' => 1]);
        $assigned = $original->withId(42);

        self::assertNotSame($original, $assigned);
        self::assertNull($original->id);
        self::assertSame(42, $assigned->id);
        self::assertSame('first', $assigned->name);
        self::assertSame(['x' => 1], $assigned->data);
    }
}
