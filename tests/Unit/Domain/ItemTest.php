<?php

declare(strict_types=1);

namespace Imanager\Tests\Unit\Domain;

use Imanager\Domain\FieldValueBag;
use Imanager\Domain\Item;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Item::class)]
#[CoversClass(FieldValueBag::class)]
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
        self::assertSame(['title' => 'Hello', 'tags' => ['php', 'cms']], $i->data->toArray());
    }

    public function testItemsDefaultToActiveAndEmptyData(): void
    {
        $i = new Item(null, 1);

        self::assertNull($i->id);
        self::assertNull($i->name);
        self::assertNull($i->label);
        self::assertTrue($i->active);
        self::assertTrue($i->data->isEmpty());
    }

    public function testConstructorAcceptsAFieldValueBagDirectly(): void
    {
        $bag = new FieldValueBag(['title' => 'Hello']);
        $i = new Item(null, 1, data: $bag);

        self::assertSame($bag, $i->data);
    }

    public function testConstructorWrapsArrayDataIntoAFieldValueBag(): void
    {
        $i = new Item(null, 1, data: ['title' => 'Hello']);

        self::assertInstanceOf(FieldValueBag::class, $i->data);
        self::assertSame('Hello', $i->data->get('title'));
    }

    public function testWithIdReturnsACopyCarryingTheAssignedId(): void
    {
        $original = new Item(null, 1, name: 'first', data: ['x' => 1]);
        $assigned = $original->withId(42);

        self::assertNotSame($original, $assigned);
        self::assertNull($original->id);
        self::assertSame(42, $assigned->id);
        self::assertSame('first', $assigned->name);
        self::assertSame(['x' => 1], $assigned->data->toArray());
    }

    public function testRejectsZeroOrNegativeIdWhenSet(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Item id');
        new Item(0, 1);
    }

    public function testRejectsZeroOrNegativeCategoryId(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Item categoryId');
        new Item(null, 0);
    }

    public function testRejectsNegativePosition(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('position');
        new Item(null, 1, position: -1);
    }
}
