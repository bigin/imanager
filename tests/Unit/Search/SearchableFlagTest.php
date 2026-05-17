<?php

declare(strict_types=1);

namespace Imanager\Tests\Unit\Search;

use Imanager\Domain\Category;
use Imanager\Domain\Field;
use Imanager\Domain\Item;
use Imanager\Events\NullEventDispatcher;
use Imanager\Search\FtsBody;
use Imanager\Search\FullTextSearch;
use Imanager\Storage\Sqlite\SqliteItemRepository;
use Imanager\Storage\Sqlite\SqliteStorage;
use Imanager\Tests\Unit\Storage\Sqlite\SqliteStorageFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * End-to-end coverage for the 2.2.0 behavior switch: the per-field
 * `searchable` flag is honored by both per-save `syncFts` and by
 * `FullTextSearch::rebuild()`.
 */
#[CoversClass(SqliteItemRepository::class)]
#[CoversClass(FullTextSearch::class)]
#[CoversClass(FtsBody::class)]
final class SearchableFlagTest extends TestCase
{
    private SqliteStorage $storage;
    private \PDO $pdo;
    private FullTextSearch $search;

    protected function setUp(): void
    {
        $this->storage = SqliteStorageFactory::inMemory();
        $this->pdo = (new \ReflectionProperty($this->storage, 'connection'))->getValue($this->storage);
        $this->search = new FullTextSearch($this->pdo);
    }

    public function testSaveExcludesNonSearchableFields(): void
    {
        $cat = $this->storage->categories()->save(new Category(null, 'Posts', 'posts'));
        \assert($cat->id !== null);

        // title is searchable, secret is not.
        $this->storage->fields()->ensure(Field::text($cat->id, 'title'));
        $this->storage->fields()->ensure(Field::text($cat->id, 'secret')->searchable(false));

        $this->storage->items()->save(new Item(
            id: null,
            categoryId: $cat->id,
            name: 'post-1',
            label: 'Post One',
            data: ['title' => 'Indexed prose', 'secret' => 'CLASSIFIED_PAYLOAD'],
        ));

        self::assertNotEmpty($this->search->search('Indexed'));
        self::assertSame([], $this->search->search('CLASSIFIED_PAYLOAD'));
    }

    public function testStructuralNameAndLabelAlwaysIndexed(): void
    {
        $cat = $this->storage->categories()->save(new Category(null, 'Notes', 'notes'));
        \assert($cat->id !== null);

        // Zero searchable fields declared. name+label must still index.
        $this->storage->fields()->ensure(Field::text($cat->id, 'body')->searchable(false));

        $this->storage->items()->save(new Item(
            id: null,
            categoryId: $cat->id,
            name: 'uniqueNameToken',
            label: 'Unique Label Token',
            data: ['body' => 'BODY_PAYLOAD_EXCLUDED'],
        ));

        self::assertNotEmpty($this->search->search('uniqueNameToken'));
        self::assertNotEmpty($this->search->search('Token'));
        self::assertSame([], $this->search->search('BODY_PAYLOAD_EXCLUDED'));
    }

    public function testUpdateRefreshesFtsWithCurrentSearchableSet(): void
    {
        $cat = $this->storage->categories()->save(new Category(null, 'Posts', 'posts'));
        \assert($cat->id !== null);
        $this->storage->fields()->ensure(Field::text($cat->id, 'tagline'));

        $created = $this->storage->items()->save(new Item(
            id: null,
            categoryId: $cat->id,
            name: 'p1',
            label: 'Post',
            data: ['tagline' => 'FIRST_VALUE'],
        ));
        \assert($created->id !== null);

        self::assertNotEmpty($this->search->search('FIRST_VALUE'));

        $this->storage->items()->save(new Item(
            id: $created->id,
            categoryId: $cat->id,
            name: $created->name,
            label: $created->label,
            data: ['tagline' => 'SECOND_VALUE'],
        ));

        self::assertSame([], $this->search->search('FIRST_VALUE'));
        self::assertNotEmpty($this->search->search('SECOND_VALUE'));
    }

    public function testRebuildRespectsSearchableFlag(): void
    {
        $cat = $this->storage->categories()->save(new Category(null, 'Posts', 'posts'));
        \assert($cat->id !== null);
        $this->storage->fields()->ensure(Field::text($cat->id, 'visible'));
        $this->storage->fields()->ensure(Field::text($cat->id, 'hidden')->searchable(false));

        $this->storage->items()->save(new Item(
            id: null,
            categoryId: $cat->id,
            name: 'p1',
            label: 'Post',
            data: ['visible' => 'VISIBLE_TOKEN', 'hidden' => 'HIDDEN_TOKEN'],
        ));

        // Wipe FTS, rebuild from scratch.
        $this->pdo->exec('DELETE FROM items_fts');
        $this->search->rebuild();

        self::assertNotEmpty($this->search->search('VISIBLE_TOKEN'));
        self::assertSame([], $this->search->search('HIDDEN_TOKEN'));
    }

    public function testRebuildHonorsFlagPerCategory(): void
    {
        $blog = $this->storage->categories()->save(new Category(null, 'Blog', 'blog'));
        $vault = $this->storage->categories()->save(new Category(null, 'Vault', 'vault'));
        \assert($blog->id !== null && $vault->id !== null);

        // Same field name, different searchable flag per category.
        $this->storage->fields()->ensure(Field::text($blog->id, 'note'));
        $this->storage->fields()->ensure(Field::text($vault->id, 'note')->searchable(false));

        $this->storage->items()->save(new Item(
            id: null,
            categoryId: $blog->id,
            name: 'blog-1',
            label: 'Blog Post',
            data: ['note' => 'CROSS_CATEGORY_TOKEN'],
        ));
        $this->storage->items()->save(new Item(
            id: null,
            categoryId: $vault->id,
            name: 'vault-1',
            label: 'Vault Entry',
            data: ['note' => 'CROSS_CATEGORY_TOKEN'],
        ));

        $this->pdo->exec('DELETE FROM items_fts');
        $this->search->rebuild();

        $hits = $this->search->search('CROSS_CATEGORY_TOKEN');
        self::assertCount(1, $hits);
        self::assertSame($blog->id, $hits[0]->categoryId);
    }

    public function testFlippingFieldFromSearchableToNotRequiresRebuild(): void
    {
        // Per-save syncFts honors the CURRENT flag, but rows already in FTS
        // retain their old body. This documents that contract: flipping the
        // flag affects future writes; pre-existing rows need a rebuild.
        $cat = $this->storage->categories()->save(new Category(null, 'Posts', 'posts'));
        \assert($cat->id !== null);
        $field = $this->storage->fields()->ensure(Field::text($cat->id, 'tagline'));
        \assert($field->id !== null);

        $this->storage->items()->save(new Item(
            id: null,
            categoryId: $cat->id,
            name: 'p1',
            label: 'Post',
            data: ['tagline' => 'WILL_BE_HIDDEN'],
        ));
        self::assertNotEmpty($this->search->search('WILL_BE_HIDDEN'));

        // Flip the flag. Save() to apply the new state.
        $this->storage->fields()->save(
            (new Field(
                id: $field->id,
                categoryId: $field->categoryId,
                name: $field->name,
                label: $field->label,
                type: $field->type,
            ))->searchable(false),
        );

        // Untouched: pre-existing FTS row still has the old body.
        self::assertNotEmpty($this->search->search('WILL_BE_HIDDEN'));

        // Rebuild reconciles it.
        $this->search->rebuild();
        self::assertSame([], $this->search->search('WILL_BE_HIDDEN'));
    }

    /**
     * Verifies migration 0005: existing fields rows with `searchable = 0`
     * get promoted to `searchable = 1` iff their type is prose-typed.
     * Other types stay at 0. Anything already at 1 is left alone.
     */
    public function testMigration0005PromotesProseTypedRowsOnly(): void
    {
        // SqliteStorageFactory has already applied 0005. The migration is
        // a one-time UPDATE; re-running it directly is idempotent, which
        // is what we want: insert seed rows with searchable=0 directly
        // (bypassing the factory smart defaults), re-execute the file,
        // assert the promotion happened.
        $cat = $this->storage->categories()->save(new Category(null, 'Mixed', 'mixed'));
        \assert($cat->id !== null);

        $insert = $this->pdo->prepare(
            'INSERT INTO fields (category_id, name, label, type, position, '
            . 'required, indexed, searchable, config, created, updated) '
            . 'VALUES (:cid, :name, :label, :type, 0, 0, 0, 0, \'{}\', 0, 0)',
        );
        $cases = [
            ['text', 'a_text', 'A'],
            ['longtext', 'a_long', 'B'],
            ['editor', 'a_editor', 'C'],
            ['slug', 'a_slug', 'D'],
            ['password', 'a_pw', 'E'],
            ['integer', 'a_int', 'F'],
            ['fileupload', 'a_file', 'G'],
            ['datepicker', 'a_date', 'H'],
        ];
        foreach ($cases as [$type, $name, $label]) {
            $insert->execute([
                ':cid' => $cat->id, ':name' => $name, ':label' => $label, ':type' => $type,
            ]);
        }

        $sql = (string) file_get_contents(
            SqliteStorageFactory::schemaDir() . '/0005_searchable_defaults.sql',
        );
        $this->pdo->exec($sql);

        $rows = $this->pdo->query(
            'SELECT name, type, searchable FROM fields WHERE category_id = ' . $cat->id . ' ORDER BY name',
        );
        \assert($rows !== false);

        $byName = [];
        foreach ($rows->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $byName[(string) $row['name']] = (int) $row['searchable'];
        }

        self::assertSame(1, $byName['a_text']);
        self::assertSame(1, $byName['a_long']);
        self::assertSame(1, $byName['a_editor']);
        self::assertSame(1, $byName['a_slug']);
        self::assertSame(0, $byName['a_pw']);
        self::assertSame(0, $byName['a_int']);
        self::assertSame(0, $byName['a_file']);
        self::assertSame(0, $byName['a_date']);
    }

    public function testLegacyConstructorWithoutFieldRepoIndexesEverything(): void
    {
        // 2.0/2.1 callers that constructed SqliteItemRepository directly
        // with the 2-arg signature must keep getting "index everything"
        // behavior, plus a deprecation notice once per process.
        $items = new SqliteItemRepository($this->pdo, new NullEventDispatcher());

        $cat = $this->storage->categories()->save(new Category(null, 'Legacy', 'legacy'));
        \assert($cat->id !== null);
        // No fields declared; legacy code path indexes the raw data blob.

        $deprecationSeen = false;
        $previousHandler = set_error_handler(
            static function (int $errno) use (&$deprecationSeen): bool {
                if ($errno === \E_USER_DEPRECATED) {
                    $deprecationSeen = true;
                    return true;
                }
                return false;
            },
            \E_USER_DEPRECATED,
        );

        try {
            $items->save(new Item(
                id: null,
                categoryId: $cat->id,
                name: 'legacy-1',
                label: 'Legacy',
                data: ['undeclared' => 'LEGACY_TOKEN'],
            ));
        } finally {
            restore_error_handler();
            unset($previousHandler);
        }

        self::assertTrue($deprecationSeen, 'expected E_USER_DEPRECATED notice');
        self::assertNotEmpty($this->search->search('LEGACY_TOKEN'));
    }
}
