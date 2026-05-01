<?php

declare(strict_types=1);

namespace Imanager\Tests\Unit\Storage;

use Imanager\Domain\Category;
use Imanager\Domain\Field;
use Imanager\Enum\FieldType;
use Imanager\Exception\NotFoundException;
use Imanager\Exception\StorageException;
use Imanager\Exception\ValidationException;
use Imanager\Storage\Storage;
use PHPUnit\Framework\TestCase;

abstract class FieldRepositoryContract extends TestCase
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
        self::assertNull($this->storage->fields()->find(999));
    }

    public function testFindByCategoryOnEmptyCategoryReturnsEmptyList(): void
    {
        self::assertSame([], $this->storage->fields()->findByCategory($this->categoryId));
    }

    public function testSaveAssignsAFreshIdWhenInputIdIsNull(): void
    {
        $saved = $this->storage->fields()->save(
            new Field(null, $this->categoryId, 'title', 'Title', FieldType::Text),
        );

        self::assertNotNull($saved->id);
        self::assertSame('title', $saved->name);
        self::assertSame(FieldType::Text, $saved->type);
    }

    public function testSavePopulatesCreatedAndUpdatedTimestamps(): void
    {
        $before = time();
        $saved = $this->storage->fields()->save(
            new Field(null, $this->categoryId, 'title', null, FieldType::Text),
        );
        $after = time();

        self::assertGreaterThanOrEqual($before, $saved->created);
        self::assertLessThanOrEqual($after, $saved->created);
        self::assertGreaterThanOrEqual($before, $saved->updated);
        self::assertLessThanOrEqual($after, $saved->updated);
    }

    public function testSaveRoundTripsConfigPayload(): void
    {
        $saved = $this->storage->fields()->save(
            new Field(
                id: null,
                categoryId: $this->categoryId,
                name: 'description',
                label: null,
                type: FieldType::LongText,
                config: ['maxLength' => 500, 'allowHtml' => false],
            ),
        );

        \assert($saved->id !== null);
        $found = $this->storage->fields()->find($saved->id);

        self::assertNotNull($found);
        self::assertSame(['maxLength' => 500, 'allowHtml' => false], $found->config);
    }

    public function testFindByNameLocatesAFieldWithinACategory(): void
    {
        $this->storage->fields()->save(
            new Field(null, $this->categoryId, 'title', null, FieldType::Text),
        );

        $found = $this->storage->fields()->findByName($this->categoryId, 'title');

        self::assertNotNull($found);
        self::assertSame('title', $found->name);
    }

    public function testFindByNameOnUnknownNameReturnsNull(): void
    {
        self::assertNull($this->storage->fields()->findByName($this->categoryId, 'nope'));
    }

    public function testFindByCategoryReturnsFieldsOrderedByPosition(): void
    {
        $a = $this->storage->fields()->save(
            new Field(null, $this->categoryId, 'a', null, FieldType::Text, position: 2),
        );
        $b = $this->storage->fields()->save(
            new Field(null, $this->categoryId, 'b', null, FieldType::Text, position: 1),
        );
        $c = $this->storage->fields()->save(
            new Field(null, $this->categoryId, 'c', null, FieldType::Text, position: 3),
        );

        $names = array_map(
            static fn(Field $f): string => $f->name,
            $this->storage->fields()->findByCategory($this->categoryId),
        );

        self::assertSame(['b', 'a', 'c'], $names);
        // Use the ids so PHPStan sees the variables as live.
        self::assertNotNull($a->id);
        self::assertNotNull($b->id);
        self::assertNotNull($c->id);
    }

    public function testSaveRejectsDuplicateNameWithinSameCategory(): void
    {
        $this->storage->fields()->save(
            new Field(null, $this->categoryId, 'title', null, FieldType::Text),
        );

        $this->expectException(ValidationException::class);
        $this->storage->fields()->save(
            new Field(null, $this->categoryId, 'title', null, FieldType::Text),
        );
    }

    public function testSaveAcceptsDuplicateNameAcrossDifferentCategories(): void
    {
        $other = $this->storage->categories()->save(new Category(null, 'News', 'news'));
        \assert($other->id !== null);

        $this->storage->fields()->save(
            new Field(null, $this->categoryId, 'title', null, FieldType::Text),
        );
        $second = $this->storage->fields()->save(
            new Field(null, $other->id, 'title', null, FieldType::Text),
        );

        self::assertNotNull($second->id);
    }

    public function testSaveRejectsAFieldForAnUnknownCategory(): void
    {
        $this->expectException(StorageException::class);
        $this->storage->fields()->save(
            new Field(null, 999, 'title', null, FieldType::Text),
        );
    }

    public function testDeleteRemovesTheField(): void
    {
        $saved = $this->storage->fields()->save(
            new Field(null, $this->categoryId, 'title', null, FieldType::Text),
        );
        \assert($saved->id !== null);

        $this->storage->fields()->delete($saved->id);

        self::assertNull($this->storage->fields()->find($saved->id));
    }

    public function testDeleteThrowsWhenFieldNotFound(): void
    {
        $this->expectException(NotFoundException::class);
        $this->storage->fields()->delete(999);
    }
}
