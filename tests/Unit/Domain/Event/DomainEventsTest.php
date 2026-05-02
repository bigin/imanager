<?php

declare(strict_types=1);

namespace Imanager\Tests\Unit\Domain\Event;

use Imanager\Domain\Category;
use Imanager\Domain\Event\CategoryCreated;
use Imanager\Domain\Event\CategoryDeleted;
use Imanager\Domain\Event\CategoryUpdated;
use Imanager\Domain\Event\DomainEvent;
use Imanager\Domain\Event\FieldCreated;
use Imanager\Domain\Event\FieldDeleted;
use Imanager\Domain\Event\FieldUpdated;
use Imanager\Domain\Event\ItemCreated;
use Imanager\Domain\Event\ItemDeleted;
use Imanager\Domain\Event\ItemUpdated;
use Imanager\Domain\Field;
use Imanager\Domain\Item;
use Imanager\Enum\FieldType;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(CategoryCreated::class)]
#[CoversClass(CategoryUpdated::class)]
#[CoversClass(CategoryDeleted::class)]
#[CoversClass(FieldCreated::class)]
#[CoversClass(FieldUpdated::class)]
#[CoversClass(FieldDeleted::class)]
#[CoversClass(ItemCreated::class)]
#[CoversClass(ItemUpdated::class)]
#[CoversClass(ItemDeleted::class)]
final class DomainEventsTest extends TestCase
{
    /**
     * @return iterable<string, array{0: DomainEvent}>
     */
    public static function events(): iterable
    {
        $cat = new Category(1, 'Blog', 'blog');
        $field = new Field(1, 1, 'title', null, FieldType::Text);
        $item = new Item(1, 1);

        yield 'CategoryCreated' => [new CategoryCreated($cat, 1700000000)];
        yield 'CategoryUpdated' => [new CategoryUpdated($cat, $cat, 1700000001)];
        yield 'CategoryDeleted' => [new CategoryDeleted(1, 1700000002)];
        yield 'FieldCreated'    => [new FieldCreated($field, 1700000003)];
        yield 'FieldUpdated'    => [new FieldUpdated($field, $field, 1700000004)];
        yield 'FieldDeleted'    => [new FieldDeleted(1, 1, 'title', 1700000005)];
        yield 'ItemCreated'     => [new ItemCreated($item, 1700000006)];
        yield 'ItemUpdated'     => [new ItemUpdated($item, $item, 1700000007)];
        yield 'ItemDeleted'     => [new ItemDeleted(1, 1, 1700000008)];
    }

    #[DataProvider('events')]
    public function testEveryConcreteEventImplementsDomainEvent(DomainEvent $event): void
    {
        self::assertInstanceOf(DomainEvent::class, $event);
        self::assertGreaterThan(0, $event->occurredAt());
    }

    public function testCategoryCreatedCarriesTheCategoryAndTimestamp(): void
    {
        $cat = new Category(1, 'Blog', 'blog');
        $event = new CategoryCreated($cat, 1700000000);

        self::assertSame($cat, $event->category);
        self::assertSame(1700000000, $event->occurredAt());
    }

    public function testItemUpdatedCarriesPreviousAndCurrentItems(): void
    {
        $previous = new Item(1, 1, name: 'old');
        $current = new Item(1, 1, name: 'new');
        $event = new ItemUpdated($previous, $current, 1700000000);

        self::assertSame($previous, $event->previous);
        self::assertSame($current, $event->current);
        self::assertNotSame($event->previous, $event->current);
    }

    public function testFieldDeletedCarriesEnoughContextForListenersToReact(): void
    {
        $event = new FieldDeleted(fieldId: 7, categoryId: 5, name: 'title', occurredAt: 1700000000);

        self::assertSame(7, $event->fieldId);
        self::assertSame(5, $event->categoryId);
        self::assertSame('title', $event->name);
    }
}
