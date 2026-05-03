<?php

declare(strict_types=1);

namespace Imanager\Tests\Unit\Storage;

use Imanager\Domain\Category;
use Imanager\Domain\Field;
use Imanager\Domain\File;
use Imanager\Domain\Item;
use Imanager\Enum\FieldType;
use Imanager\Exception\NotFoundException;
use Imanager\Exception\StorageException;
use Imanager\Storage\Storage;
use PHPUnit\Framework\TestCase;

abstract class FileRepositoryContract extends TestCase
{
    protected Storage $storage;
    protected int $itemId;
    protected int $fieldId;
    protected int $otherFieldId;

    abstract protected function createStorage(): Storage;

    protected function setUp(): void
    {
        $this->storage = $this->createStorage();

        $cat = $this->storage->categories()->save(new Category(null, 'Blog', 'blog'));
        \assert($cat->id !== null);

        $field = $this->storage->fields()->save(new Field(
            id: null,
            categoryId: $cat->id,
            name: 'attachments',
            label: null,
            type: FieldType::Fileupload,
        ));
        \assert($field->id !== null);
        $this->fieldId = $field->id;

        $other = $this->storage->fields()->save(new Field(
            id: null,
            categoryId: $cat->id,
            name: 'gallery',
            label: null,
            type: FieldType::Imageupload,
        ));
        \assert($other->id !== null);
        $this->otherFieldId = $other->id;

        $item = $this->storage->items()->save(new Item(null, $cat->id));
        \assert($item->id !== null);
        $this->itemId = $item->id;
    }

    public function testFindReturnsNullForUnknownId(): void
    {
        self::assertNull($this->storage->files()->find(999));
    }

    public function testSaveAssignsAFreshId(): void
    {
        $saved = $this->storage->files()->save($this->newFile());

        self::assertNotNull($saved->id);
        self::assertGreaterThan(0, $saved->created);
    }

    public function testSaveRoundTripsAllFields(): void
    {
        $saved = $this->storage->files()->save(new File(
            id: null,
            itemId: $this->itemId,
            fieldId: $this->fieldId,
            name: 'photo.jpg',
            path: 'rel/photo.jpg',
            mime: 'image/jpeg',
            size: 12345,
            width: 800,
            height: 600,
            position: 2,
            title: 'Photo by [Maria Travina](https://example.com)',
        ));
        \assert($saved->id !== null);

        $found = $this->storage->files()->find($saved->id);

        self::assertNotNull($found);
        self::assertSame('photo.jpg', $found->name);
        self::assertSame('rel/photo.jpg', $found->path);
        self::assertSame('image/jpeg', $found->mime);
        self::assertSame(12345, $found->size);
        self::assertSame(800, $found->width);
        self::assertSame(600, $found->height);
        self::assertSame(2, $found->position);
        self::assertSame('Photo by [Maria Travina](https://example.com)', $found->title);
    }

    public function testSaveDefaultsTitleToEmptyString(): void
    {
        $saved = $this->storage->files()->save($this->newFile(name: 'no-title.jpg'));
        \assert($saved->id !== null);

        $found = $this->storage->files()->find($saved->id);
        self::assertNotNull($found);
        self::assertSame('', $found->title);
    }

    public function testTitleCanBeUpdatedOnExistingRow(): void
    {
        $saved = $this->storage->files()->save($this->newFile(name: 'updateable.jpg'));
        \assert($saved->id !== null);

        $updated = $this->storage->files()->save($saved->withTitle('A late caption'));
        self::assertSame('A late caption', $updated->title);

        $reloaded = $this->storage->files()->find($saved->id);
        self::assertNotNull($reloaded);
        self::assertSame('A late caption', $reloaded->title);
    }

    public function testFindByItemReturnsAllFilesOrderedByPosition(): void
    {
        $this->storage->files()->save($this->newFile(name: 'a.txt', position: 3));
        $this->storage->files()->save($this->newFile(name: 'b.txt', position: 1));
        $this->storage->files()->save($this->newFile(name: 'c.txt', position: 2));

        $names = array_map(
            static fn(File $f): string => $f->name,
            $this->storage->files()->findByItem($this->itemId),
        );

        self::assertSame(['b.txt', 'c.txt', 'a.txt'], $names);
    }

    public function testFindByItemAndFieldFilters(): void
    {
        $this->storage->files()->save($this->newFile(name: 'a.txt'));
        $this->storage->files()->save($this->newFile(
            name: 'b.png',
            fieldId: $this->otherFieldId,
        ));

        self::assertCount(
            1,
            $this->storage->files()->findByItemAndField($this->itemId, $this->fieldId),
        );
        self::assertCount(
            1,
            $this->storage->files()->findByItemAndField($this->itemId, $this->otherFieldId),
        );
    }

    public function testSaveRejectsOrphanItem(): void
    {
        $this->expectException(StorageException::class);
        $this->storage->files()->save(new File(
            id: null,
            itemId: 999,
            fieldId: $this->fieldId,
            name: 'a.txt',
            path: 'r/a.txt',
            mime: 'text/plain',
            size: 1,
        ));
    }

    public function testSaveRejectsOrphanField(): void
    {
        $this->expectException(StorageException::class);
        $this->storage->files()->save(new File(
            id: null,
            itemId: $this->itemId,
            fieldId: 999,
            name: 'a.txt',
            path: 'r/a.txt',
            mime: 'text/plain',
            size: 1,
        ));
    }

    public function testDeleteRemoves(): void
    {
        $saved = $this->storage->files()->save($this->newFile());
        \assert($saved->id !== null);

        $this->storage->files()->delete($saved->id);

        self::assertNull($this->storage->files()->find($saved->id));
    }

    public function testDeleteOnUnknownThrows(): void
    {
        $this->expectException(NotFoundException::class);
        $this->storage->files()->delete(999);
    }

    public function testDeletingItemCascadesItsFiles(): void
    {
        $saved = $this->storage->files()->save($this->newFile());
        \assert($saved->id !== null);

        $this->storage->items()->delete($this->itemId);

        self::assertNull($this->storage->files()->find($saved->id));
    }

    public function testDeletingFieldCascadesItsFiles(): void
    {
        $saved = $this->storage->files()->save($this->newFile());
        \assert($saved->id !== null);

        $this->storage->fields()->delete($this->fieldId);

        self::assertNull($this->storage->files()->find($saved->id));
    }

    private function newFile(
        string $name = 'a.txt',
        int $position = 0,
        ?int $fieldId = null,
    ): File {
        return new File(
            id: null,
            itemId: $this->itemId,
            fieldId: $fieldId ?? $this->fieldId,
            name: $name,
            path: 'rel/' . $name,
            mime: 'text/plain',
            size: 1,
            position: $position,
        );
    }
}
