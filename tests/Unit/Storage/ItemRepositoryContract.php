<?php

declare(strict_types=1);

namespace Imanager\Tests\Unit\Storage;

use Imanager\Domain\Category;
use Imanager\Domain\Item;
use Imanager\Exception\NotFoundException;
use Imanager\Exception\StorageException;
use Imanager\Storage\Storage;
use PHPUnit\Framework\TestCase;

abstract class ItemRepositoryContract extends TestCase
{
    protected Storage $storage;
    protected int $categoryId;

    abstract protected function createStorage(): Storage;

    protected function setUp(): void
    {
        $this->storage = $this->createStorage();
        $cat = $this->storage->categories()->save(new Category(null, 'Blog', 'blog'));
        \assert($cat->id !== null);
        $this->categoryId = $cat->id;
    }

    public function testFindReturnsNullForUnknownId(): void
    {
        self::assertNull($this->storage->items()->find(999));
    }

    public function testFindByCategoryOnEmptyCategoryReturnsEmptyList(): void
    {
        self::assertSame([], $this->storage->items()->findByCategory($this->categoryId));
    }

    public function testCountByCategoryStartsAtZero(): void
    {
        self::assertSame(0, $this->storage->items()->countByCategory($this->categoryId));
    }

    public function testSaveAssignsAFreshIdAndPopulatesTimestamps(): void
    {
        $before = time();
        $saved = $this->storage->items()->save(
            new Item(null, $this->categoryId, 'first-post', 'First Post', data: ['title' => 'Hello']),
        );
        $after = time();

        self::assertNotNull($saved->id);
        self::assertSame(['title' => 'Hello'], $saved->data);
        self::assertGreaterThanOrEqual($before, $saved->created);
        self::assertLessThanOrEqual($after, $saved->created);
    }

    public function testSaveRoundTripsArbitraryDataPayload(): void
    {
        $payload = [
            'title' => 'Hello',
            'tags' => ['php', 'cms'],
            'meta' => ['views' => 0, 'pinned' => true],
        ];

        $saved = $this->storage->items()->save(new Item(null, $this->categoryId, data: $payload));
        \assert($saved->id !== null);

        $found = $this->storage->items()->find($saved->id);

        self::assertNotNull($found);
        self::assertSame($payload, $found->data);
    }

    public function testFindByCategoryReturnsItemsOrderedByPosition(): void
    {
        $this->storage->items()->save(new Item(null, $this->categoryId, 'a', position: 3));
        $this->storage->items()->save(new Item(null, $this->categoryId, 'b', position: 1));
        $this->storage->items()->save(new Item(null, $this->categoryId, 'c', position: 2));

        $names = array_map(
            static fn(Item $i): ?string => $i->name,
            $this->storage->items()->findByCategory($this->categoryId),
        );

        self::assertSame(['b', 'c', 'a'], $names);
    }

    public function testFindByCategoryHonoursOffsetAndLimit(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->storage->items()->save(
                new Item(null, $this->categoryId, "i{$i}", position: $i),
            );
        }

        $page = $this->storage->items()->findByCategory($this->categoryId, offset: 1, limit: 2);

        $names = array_map(static fn(Item $i): ?string => $i->name, $page);
        self::assertSame(['i2', 'i3'], $names);
    }

    public function testCountByCategoryReflectsTotalNumberOfItems(): void
    {
        for ($i = 1; $i <= 4; $i++) {
            $this->storage->items()->save(new Item(null, $this->categoryId, "i{$i}"));
        }

        self::assertSame(4, $this->storage->items()->countByCategory($this->categoryId));
    }

    public function testSaveRejectsAnItemForAnUnknownCategory(): void
    {
        $this->expectException(StorageException::class);
        $this->storage->items()->save(new Item(null, 999));
    }

    public function testDeleteRemovesTheItem(): void
    {
        $saved = $this->storage->items()->save(new Item(null, $this->categoryId));
        \assert($saved->id !== null);

        $this->storage->items()->delete($saved->id);

        self::assertNull($this->storage->items()->find($saved->id));
    }

    public function testDeleteThrowsWhenItemNotFound(): void
    {
        $this->expectException(NotFoundException::class);
        $this->storage->items()->delete(999);
    }
}
