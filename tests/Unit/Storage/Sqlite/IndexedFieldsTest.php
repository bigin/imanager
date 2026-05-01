<?php

declare(strict_types=1);

namespace Imanager\Tests\Unit\Storage\Sqlite;

use Imanager\Domain\Category;
use Imanager\Domain\Field;
use Imanager\Domain\Item;
use Imanager\Enum\FieldType;
use Imanager\Storage\Sqlite\IndexedFields;
use Imanager\Storage\Sqlite\SqliteStorage;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(IndexedFields::class)]
#[CoversClass(SqliteStorage::class)]
final class IndexedFieldsTest extends TestCase
{
    private SqliteStorage $storage;
    private \PDO $pdo;
    private int $categoryId;

    protected function setUp(): void
    {
        $this->storage = SqliteStorageFactory::inMemory();
        // Reach into the storage via reflection just for the schema-level assertions.
        // PHPStan knows ReflectionProperty::getValue() inherits the declared
        // property type, so this is statically `\PDO` without a runtime guard.
        $this->pdo = (new \ReflectionProperty($this->storage, 'connection'))->getValue($this->storage);

        $cat = $this->storage->categories()->save(new Category(null, 'Blog', 'blog'));
        \assert($cat->id !== null);
        $this->categoryId = $cat->id;
    }

    public function testSavingAFieldWithIndexedTrueCreatesAGeneratedColumnAndIndex(): void
    {
        $this->storage->fields()->save(
            new Field(null, $this->categoryId, 'title', null, FieldType::Text, indexed: true),
        );

        self::assertTrue($this->columnExists("gen_{$this->categoryId}_title"));
        self::assertTrue($this->indexExists("idx_items_{$this->categoryId}_title"));
    }

    public function testSavingAFieldWithIndexedFalseDoesNotCreateAGeneratedColumn(): void
    {
        $this->storage->fields()->save(
            new Field(null, $this->categoryId, 'title', null, FieldType::Text, indexed: false),
        );

        self::assertFalse($this->columnExists("gen_{$this->categoryId}_title"));
        self::assertFalse($this->indexExists("idx_items_{$this->categoryId}_title"));
    }

    public function testFlippingIndexedFromFalseToTrueCreatesTheColumn(): void
    {
        $field = $this->storage->fields()->save(
            new Field(null, $this->categoryId, 'title', null, FieldType::Text, indexed: false),
        );
        \assert($field->id !== null);

        $this->storage->fields()->save(
            new Field(
                id: $field->id,
                categoryId: $this->categoryId,
                name: 'title',
                label: null,
                type: FieldType::Text,
                indexed: true,
            ),
        );

        self::assertTrue($this->columnExists("gen_{$this->categoryId}_title"));
    }

    public function testFlippingIndexedFromTrueToFalseDropsTheColumn(): void
    {
        $field = $this->storage->fields()->save(
            new Field(null, $this->categoryId, 'title', null, FieldType::Text, indexed: true),
        );
        \assert($field->id !== null);

        $this->storage->fields()->save(
            new Field(
                id: $field->id,
                categoryId: $this->categoryId,
                name: 'title',
                label: null,
                type: FieldType::Text,
                indexed: false,
            ),
        );

        self::assertFalse($this->columnExists("gen_{$this->categoryId}_title"));
        self::assertFalse($this->indexExists("idx_items_{$this->categoryId}_title"));
    }

    public function testRenamingAnIndexedFieldRebuildsTheGeneratedColumnAndIndex(): void
    {
        $field = $this->storage->fields()->save(
            new Field(null, $this->categoryId, 'title', null, FieldType::Text, indexed: true),
        );
        \assert($field->id !== null);

        $this->storage->fields()->save(
            new Field(
                id: $field->id,
                categoryId: $this->categoryId,
                name: 'headline',
                label: null,
                type: FieldType::Text,
                indexed: true,
            ),
        );

        self::assertFalse($this->columnExists("gen_{$this->categoryId}_title"));
        self::assertFalse($this->indexExists("idx_items_{$this->categoryId}_title"));
        self::assertTrue($this->columnExists("gen_{$this->categoryId}_headline"));
        self::assertTrue($this->indexExists("idx_items_{$this->categoryId}_headline"));
    }

    public function testDeletingAnIndexedFieldDropsTheGeneratedColumn(): void
    {
        $field = $this->storage->fields()->save(
            new Field(null, $this->categoryId, 'title', null, FieldType::Text, indexed: true),
        );
        \assert($field->id !== null);

        $this->storage->fields()->delete($field->id);

        self::assertFalse($this->columnExists("gen_{$this->categoryId}_title"));
        self::assertFalse($this->indexExists("idx_items_{$this->categoryId}_title"));
    }

    public function testIndexedColumnsCanBeQueriedAfterItemSave(): void
    {
        $this->storage->fields()->save(
            new Field(null, $this->categoryId, 'title', null, FieldType::Text, indexed: true),
        );

        $this->storage->items()->save(new Item(null, $this->categoryId, data: ['title' => 'Hello']));
        $this->storage->items()->save(new Item(null, $this->categoryId, data: ['title' => 'World']));

        $col = "gen_{$this->categoryId}_title";
        $stmt = $this->pdo->prepare("SELECT id FROM items WHERE category_id = :cid AND {$col} = :v");
        $stmt->execute([':cid' => $this->categoryId, ':v' => 'World']);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        self::assertCount(1, $rows);
    }

    public function testTwoCategoriesMayCarryAnIdenticalIndexedFieldName(): void
    {
        $other = $this->storage->categories()->save(new Category(null, 'News', 'news'));
        \assert($other->id !== null);

        $this->storage->fields()->save(
            new Field(null, $this->categoryId, 'title', null, FieldType::Text, indexed: true),
        );
        $this->storage->fields()->save(
            new Field(null, $other->id, 'title', null, FieldType::Text, indexed: true),
        );

        self::assertTrue($this->columnExists("gen_{$this->categoryId}_title"));
        self::assertTrue($this->columnExists("gen_{$other->id}_title"));
    }

    private function columnExists(string $name): bool
    {
        $stmt = $this->pdo->prepare(
            "SELECT 1 FROM pragma_table_xinfo('items') WHERE name = :n",
        );
        $stmt->execute([':n' => $name]);
        return $stmt->fetchColumn() !== false;
    }

    private function indexExists(string $name): bool
    {
        $stmt = $this->pdo->prepare(
            "SELECT 1 FROM sqlite_master WHERE type='index' AND name = :n",
        );
        $stmt->execute([':n' => $name]);
        return $stmt->fetchColumn() !== false;
    }
}
