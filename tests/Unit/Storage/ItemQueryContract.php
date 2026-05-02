<?php

declare(strict_types=1);

namespace Imanager\Tests\Unit\Storage;

use Imanager\Domain\Category;
use Imanager\Domain\Item;
use Imanager\Query\Operator;
use Imanager\Query\Query;
use Imanager\Storage\Storage;
use PHPUnit\Framework\TestCase;

/**
 * Contract for `ItemRepository::query()` / `count()`. Each implementation
 * (in-memory, SQLite) plugs in via {@see createStorage()} and the suite
 * exercises both the structural-column path and the JSON-data path against
 * the full operator matrix.
 */
abstract class ItemQueryContract extends TestCase
{
    protected Storage $storage;
    protected int $blogId;
    protected int $newsId;

    abstract protected function createStorage(): Storage;

    protected function setUp(): void
    {
        $this->storage = $this->createStorage();

        $blog = $this->storage->categories()->save(new Category(null, 'Blog', 'blog'));
        $news = $this->storage->categories()->save(new Category(null, 'News', 'news'));
        \assert($blog->id !== null);
        \assert($news->id !== null);
        $this->blogId = $blog->id;
        $this->newsId = $news->id;

        // Blog: 5 items with varied positions, names and JSON tags.
        $blogSeed = [
            ['name' => 'alpha',    'position' => 5, 'active' => true,  'tags' => ['php']],
            ['name' => 'bravo',    'position' => 1, 'active' => true,  'tags' => ['php', 'cms']],
            ['name' => 'charlie',  'position' => 4, 'active' => false, 'tags' => ['cms']],
            ['name' => 'delta',    'position' => 2, 'active' => true,  'tags' => []],
            ['name' => 'echo',     'position' => 3, 'active' => true,  'tags' => ['php']],
        ];
        foreach ($blogSeed as $row) {
            $this->storage->items()->save(new Item(
                id: null,
                categoryId: $this->blogId,
                name: $row['name'],
                position: $row['position'],
                active: $row['active'],
                data: ['tags' => $row['tags']],
            ));
        }

        // News: a single item, used to verify category scoping.
        $this->storage->items()->save(new Item(
            id: null,
            categoryId: $this->newsId,
            name: 'breaking',
            position: 1,
        ));
    }

    public function testInCategoryRestrictsResultsToThatCategory(): void
    {
        $items = $this->storage->items()->query((new Query())->inCategory($this->blogId));

        self::assertCount(5, $items);
        foreach ($items as $item) {
            self::assertSame($this->blogId, $item->categoryId);
        }
    }

    public function testWithoutACategoryScopeAllItemsAreReturned(): void
    {
        $items = $this->storage->items()->query(new Query());
        self::assertCount(6, $items);
    }

    public function testEqualityOnAStructuralColumn(): void
    {
        $items = $this->storage->items()->query(
            (new Query())->inCategory($this->blogId)->where('active', '=', true),
        );
        self::assertCount(4, $items);
    }

    public function testInequalityOnAStructuralColumn(): void
    {
        $items = $this->storage->items()->query(
            (new Query())->inCategory($this->blogId)->where('name', '!=', 'alpha'),
        );

        $names = self::names($items);
        self::assertNotContains('alpha', $names);
        self::assertCount(4, $items);
    }

    public function testGreaterThanOrEqualOnPosition(): void
    {
        $items = $this->storage->items()->query(
            (new Query())->inCategory($this->blogId)->where('position', '>=', 3),
        );

        self::assertSame(['echo', 'charlie', 'alpha'], self::names($items));
    }

    public function testLessThanOnPosition(): void
    {
        $items = $this->storage->items()->query(
            (new Query())->inCategory($this->blogId)->where('position', '<', 3),
        );

        self::assertSame(['bravo', 'delta'], self::names($items));
    }

    public function testWildcardMatchOnName(): void
    {
        $items = $this->storage->items()->query(
            (new Query())->inCategory($this->blogId)->where('name', Operator::Like, 'a%'),
        );

        self::assertSame(['alpha'], self::names($items));
    }

    public function testContainsWildcardOnName(): void
    {
        $items = $this->storage->items()->query(
            (new Query())->inCategory($this->blogId)->where('name', Operator::Like, '%a%'),
        );

        $names = self::names($items);
        sort($names);
        self::assertSame(['alpha', 'bravo', 'charlie', 'delta'], $names);
    }

    public function testEqualityOnAJsonField(): void
    {
        $items = $this->storage->items()->query(
            (new Query())
                ->inCategory($this->blogId)
                ->where('missing', '=', 'whatever'),
        );

        // None of the seed items carries a `missing` JSON key.
        self::assertSame([], $items);
    }

    public function testWildcardOnAJsonField(): void
    {
        // Seed an item whose data carries a `title` field.
        $this->storage->items()->save(new Item(
            id: null,
            categoryId: $this->blogId,
            name: 'titled',
            position: 99,
            data: ['title' => 'Hello, World'],
        ));

        $items = $this->storage->items()->query(
            (new Query())
                ->inCategory($this->blogId)
                ->where('title', Operator::Like, '%World%'),
        );

        self::assertSame(['titled'], self::names($items));
    }

    public function testOrderByDescendingOnAStructuralColumn(): void
    {
        $items = $this->storage->items()->query(
            (new Query())->inCategory($this->blogId)->orderBy('position', 'desc'),
        );

        self::assertSame(['alpha', 'charlie', 'echo', 'delta', 'bravo'], self::names($items));
    }

    public function testDefaultOrderingIsByPositionAscThenId(): void
    {
        $items = $this->storage->items()->query(
            (new Query())->inCategory($this->blogId),
        );

        self::assertSame(['bravo', 'delta', 'echo', 'charlie', 'alpha'], self::names($items));
    }

    public function testLimitAndOffsetPaginate(): void
    {
        $items = $this->storage->items()->query(
            (new Query())->inCategory($this->blogId)->limit(2)->offset(1),
        );

        self::assertSame(['delta', 'echo'], self::names($items));
    }

    public function testCountReflectsTheFullMatchSetIgnoringLimit(): void
    {
        $count = $this->storage->items()->count(
            (new Query())->inCategory($this->blogId)->where('active', '=', true)->limit(2),
        );

        self::assertSame(4, $count);
    }

    public function testCombinedClausesAreAndedTogether(): void
    {
        $items = $this->storage->items()->query(
            (new Query())
                ->inCategory($this->blogId)
                ->where('active', '=', true)
                ->where('position', '>=', 3),
        );

        self::assertSame(['echo', 'alpha'], self::names($items));
    }

    /**
     * @param list<Item> $items
     *
     * @return list<string>
     */
    private static function names(array $items): array
    {
        return array_map(
            static fn(Item $i): string => $i->name ?? '',
            $items,
        );
    }
}
