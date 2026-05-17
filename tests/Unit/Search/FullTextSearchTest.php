<?php

declare(strict_types=1);

namespace Imanager\Tests\Unit\Search;

use Imanager\Domain\Category;
use Imanager\Domain\Field;
use Imanager\Domain\Item;
use Imanager\Exception\StorageException;
use Imanager\Search\FullTextSearch;
use Imanager\Search\SearchHit;
use Imanager\Storage\Sqlite\SqliteStorage;
use Imanager\Tests\Unit\Storage\Sqlite\SqliteStorageFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(FullTextSearch::class)]
final class FullTextSearchTest extends TestCase
{
    private SqliteStorage $storage;
    private \PDO $pdo;
    private FullTextSearch $search;
    private int $blogId;
    private int $newsId;

    protected function setUp(): void
    {
        $this->storage = SqliteStorageFactory::inMemory();
        $this->pdo = (new \ReflectionProperty($this->storage, 'connection'))->getValue($this->storage);
        $this->search = new FullTextSearch($this->pdo);

        $blog = $this->storage->categories()->save(new Category(null, 'Blog', 'blog'));
        $news = $this->storage->categories()->save(new Category(null, 'News', 'news'));
        \assert($blog->id !== null && $news->id !== null);
        $this->blogId = $blog->id;
        $this->newsId = $news->id;

        // Declare the `body` field on both categories — Field::longText()
        // defaults to searchable:true (2.2.0+), which is what these tests
        // need so per-save syncFts writes body content into FTS.
        $this->storage->fields()->ensure(Field::longText($this->blogId, 'body', 'Body'));
        $this->storage->fields()->ensure(Field::longText($this->newsId, 'body', 'Body'));

        $this->storage->items()->save(new Item(
            id: null,
            categoryId: $this->blogId,
            name: 'first-post',
            label: 'Hello World',
            data: ['body' => 'A friendly introduction to iManager.'],
        ));
        $this->storage->items()->save(new Item(
            id: null,
            categoryId: $this->blogId,
            name: 'php-tips',
            label: 'PHP Tips',
            data: ['body' => 'Some practical advice for working with PHP.'],
        ));
        $this->storage->items()->save(new Item(
            id: null,
            categoryId: $this->newsId,
            name: 'breaking',
            label: 'Big News',
            data: ['body' => 'Hello from the news desk.'],
        ));
    }

    public function testSearchReturnsItemsMatchingTheTerm(): void
    {
        $hits = $this->search->search('hello');

        self::assertCount(2, $hits);
        $names = $this->itemNames($hits);
        sort($names);
        self::assertSame(['breaking', 'first-post'], $names);
    }

    public function testSearchScopesToASpecificCategory(): void
    {
        $hits = $this->search->search('hello', categoryId: $this->blogId);

        self::assertCount(1, $hits);
        self::assertSame('first-post', $this->itemNames($hits)[0]);
    }

    public function testSearchHonoursLimitAndOffset(): void
    {
        // Both blog items mention "the" implicitly via body — use a term
        // that hits both: "to" appears in both intros and "for" only one,
        // so search "PHP" → only one hit; pagination is exercised more
        // robustly with a term that matches multiple.
        $hits = $this->search->search('introduction OR practical');

        self::assertCount(2, $hits);
        $page = $this->search->search('introduction OR practical', limit: 1, offset: 1);
        self::assertCount(1, $page);
    }

    public function testSearchReturnsSnippetWithBoldHighlightedTerm(): void
    {
        $hits = $this->search->search('PHP');

        self::assertNotEmpty($hits);
        self::assertStringContainsString('<b>', $hits[0]->snippet);
        self::assertStringContainsString('</b>', $hits[0]->snippet);
        self::assertStringContainsStringIgnoringCase('PHP', $hits[0]->snippet);
    }

    public function testSearchSurfacesMatchesInTheJsonDataBody(): void
    {
        // 'introduction' lives in the data->body, not in name or label.
        $hits = $this->search->search('introduction');

        self::assertCount(1, $hits);
        self::assertSame('first-post', $this->itemNames($hits)[0]);
    }

    public function testSearchReturnsSearchHitsWithStableShape(): void
    {
        $hits = $this->search->search('hello');

        self::assertNotEmpty($hits);
        foreach ($hits as $hit) {
            self::assertInstanceOf(SearchHit::class, $hit);
            self::assertGreaterThan(0, $hit->itemId);
            self::assertGreaterThan(0, $hit->categoryId);
        }
    }

    public function testSearchPicksUpUpdatedItems(): void
    {
        $first = $this->search->search('zenith');
        self::assertSame([], $first);

        $existing = $this->storage->items()->find(1);
        \assert($existing !== null);
        $this->storage->items()->save(new Item(
            id: $existing->id,
            categoryId: $existing->categoryId,
            name: $existing->name,
            label: 'Zenith ascending',
            position: $existing->position,
            active: $existing->active,
            data: $existing->data,
        ));

        $hits = $this->search->search('zenith');
        self::assertCount(1, $hits);
    }

    public function testSearchDropsHitsForDeletedItems(): void
    {
        $this->storage->items()->delete(1);

        $hits = $this->search->search('introduction');
        self::assertSame([], $hits);
    }

    public function testCountReportsTotalIndependentlyOfLimit(): void
    {
        self::assertSame(2, $this->search->count('hello'));
        self::assertSame(1, $this->search->count('hello', categoryId: $this->blogId));
    }

    public function testSearchTreatsDiacriticsAsEquivalent(): void
    {
        $this->storage->items()->save(new Item(
            id: null,
            categoryId: $this->blogId,
            name: 'naive-post',
            label: 'On Being Naïve',
            data: ['body' => 'Some thoughts.'],
        ));

        // unicode61 with `remove_diacritics 2` makes naive == naïve.
        $hits = $this->search->search('naive');

        self::assertNotEmpty($hits);
        self::assertSame('naive-post', $this->itemNames($hits)[0]);
    }

    public function testRebuildRefillsTheIndexFromScratch(): void
    {
        // Wipe the index manually so we can verify rebuild() repopulates it.
        $this->pdo->exec('DELETE FROM items_fts');
        self::assertSame(0, $this->search->count('hello'));

        $this->search->rebuild();

        self::assertSame(2, $this->search->count('hello'));
    }

    public function testMalformedQueriesSurfaceAsStorageException(): void
    {
        // FTS5 rejects parens-mismatch / unknown operators with a SQL error.
        $this->expectException(StorageException::class);
        $this->search->search('"unterminated phrase');
    }

    /**
     * @param list<SearchHit> $hits
     *
     * @return list<string>
     */
    private function itemNames(array $hits): array
    {
        $out = [];
        foreach ($hits as $hit) {
            $item = $this->storage->items()->find($hit->itemId);
            if ($item !== null) {
                $out[] = $item->name ?? '';
            }
        }
        return $out;
    }
}
