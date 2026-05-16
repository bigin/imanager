<?php

declare(strict_types=1);

namespace Imanager\Tests\Unit\Storage;

use Imanager\Domain\Category;
use Imanager\Domain\Field;
use Imanager\Domain\Item;
use Imanager\Enum\FieldType;
use Imanager\Exception\NotFoundException;
use Imanager\Exception\ValidationException;
use Imanager\Storage\Storage;
use PHPUnit\Framework\TestCase;

/**
 * Contract test base for any {@see \Imanager\Storage\CategoryRepository}
 * implementation.
 *
 * Subclasses provide a fresh {@see Storage} via {@see createStorage()};
 * the same suite is then run against the in-memory implementation in
 * Phase 3 and against the SQLite implementation in Phase 4.
 *
 * Class is abstract on purpose so PHPUnit doesn't try to instantiate it
 * directly; the file ends in `Contract.php` (not `Test.php`) so the
 * default test discovery pattern skips it as well.
 */
abstract class CategoryRepositoryContract extends TestCase
{
    protected Storage $storage;

    abstract protected function createStorage(): Storage;

    protected function setUp(): void
    {
        $this->storage = $this->createStorage();
    }

    public function testFindReturnsNullForUnknownId(): void
    {
        self::assertNull($this->storage->categories()->find(999));
    }

    public function testFindBySlugReturnsNullForUnknownSlug(): void
    {
        self::assertNull($this->storage->categories()->findBySlug('nope'));
    }

    public function testFindAllOnEmptyStorageReturnsEmptyList(): void
    {
        self::assertSame([], $this->storage->categories()->findAll());
    }

    public function testSaveAssignsAFreshIdWhenInputIdIsNull(): void
    {
        $saved = $this->storage->categories()->save(
            new Category(null, 'Blog', 'blog'),
        );

        self::assertNotNull($saved->id);
        self::assertSame('Blog', $saved->name);
        self::assertSame('blog', $saved->slug);
    }

    public function testSavePopulatesCreatedAndUpdatedTimestamps(): void
    {
        $before = time();
        $saved = $this->storage->categories()->save(
            new Category(null, 'Blog', 'blog'),
        );
        $after = time();

        self::assertGreaterThanOrEqual($before, $saved->created);
        self::assertLessThanOrEqual($after, $saved->created);
        self::assertGreaterThanOrEqual($before, $saved->updated);
        self::assertLessThanOrEqual($after, $saved->updated);
    }

    public function testFindRetrievesPreviouslySavedCategory(): void
    {
        $saved = $this->storage->categories()->save(new Category(null, 'Blog', 'blog'));

        $found = $this->storage->categories()->find($saved->id ?? 0);

        self::assertNotNull($found);
        self::assertSame($saved->id, $found->id);
        self::assertSame('Blog', $found->name);
    }

    public function testFindBySlugRetrievesPreviouslySavedCategory(): void
    {
        $saved = $this->storage->categories()->save(new Category(null, 'Blog', 'blog'));

        $found = $this->storage->categories()->findBySlug('blog');

        self::assertNotNull($found);
        self::assertSame($saved->id, $found->id);
    }

    public function testFindAllReturnsEverySavedCategory(): void
    {
        $this->storage->categories()->save(new Category(null, 'Blog', 'blog'));
        $this->storage->categories()->save(new Category(null, 'News', 'news'));

        self::assertCount(2, $this->storage->categories()->findAll());
    }

    public function testSaveRejectsADuplicateName(): void
    {
        $this->storage->categories()->save(new Category(null, 'Blog', 'blog'));

        $this->expectException(ValidationException::class);
        $this->storage->categories()->save(new Category(null, 'Blog', 'blog-2'));
    }

    public function testSaveRejectsADuplicateSlug(): void
    {
        $this->storage->categories()->save(new Category(null, 'Blog', 'blog'));

        $this->expectException(ValidationException::class);
        $this->storage->categories()->save(new Category(null, 'News', 'blog'));
    }

    public function testSaveUpdatesAnExistingCategoryWhenIdIsSet(): void
    {
        $first = $this->storage->categories()->save(new Category(null, 'Blog', 'blog'));
        \assert($first->id !== null);

        $updated = $this->storage->categories()->save(
            new Category(id: $first->id, name: 'Weblog', slug: 'weblog'),
        );

        self::assertSame($first->id, $updated->id);
        self::assertSame('Weblog', $updated->name);
        self::assertSame('weblog', $updated->slug);
        self::assertCount(1, $this->storage->categories()->findAll());
    }

    public function testSaveOnAnUnknownIdThrowsNotFound(): void
    {
        $this->expectException(NotFoundException::class);
        $this->storage->categories()->save(
            new Category(id: 999, name: 'Ghost', slug: 'ghost'),
        );
    }

    public function testDeleteRemovesTheCategory(): void
    {
        $saved = $this->storage->categories()->save(new Category(null, 'Blog', 'blog'));
        \assert($saved->id !== null);

        $this->storage->categories()->delete($saved->id);

        self::assertNull($this->storage->categories()->find($saved->id));
    }

    public function testDeleteThrowsWhenCategoryNotFound(): void
    {
        $this->expectException(NotFoundException::class);
        $this->storage->categories()->delete(999);
    }

    public function testDeleteCascadesToFieldsAndItems(): void
    {
        $cat = $this->storage->categories()->save(new Category(null, 'Blog', 'blog'));
        \assert($cat->id !== null);

        $field = $this->storage->fields()->save(
            new Field(null, $cat->id, 'title', null, FieldType::Text),
        );
        $item = $this->storage->items()->save(new Item(null, $cat->id));

        $this->storage->categories()->delete($cat->id);

        \assert($field->id !== null);
        \assert($item->id !== null);
        self::assertNull($this->storage->fields()->find($field->id));
        self::assertNull($this->storage->items()->find($item->id));
    }

    // -----------------------------------------------------------------
    // ensure() — upsert by natural key (slug).
    // -----------------------------------------------------------------

    public function testEnsureInsertsWhenSlugIsAbsent(): void
    {
        $inserted = $this->storage->categories()->ensure(
            new Category(null, 'Blog', 'blog'),
        );

        self::assertNotNull($inserted->id);
        self::assertSame('Blog', $inserted->name);
        self::assertSame('blog', $inserted->slug);
    }

    public function testEnsureReturnsExistingWhenSlugIsPresent(): void
    {
        $first = $this->storage->categories()->ensure(
            new Category(null, 'Blog', 'blog'),
        );

        $second = $this->storage->categories()->ensure(
            new Category(null, 'Blog', 'blog'),
        );

        self::assertSame($first->id, $second->id);
        self::assertSame($first->created, $second->created);
    }

    public function testEnsureDoesNotUpdateOnHit(): void
    {
        $original = $this->storage->categories()->ensure(
            new Category(null, 'Blog', 'blog', position: 0),
        );

        // Caller hands in a different name + position; ensure() must NOT
        // apply them — that's the safety promise vs an implicit upsert.
        $second = $this->storage->categories()->ensure(
            new Category(null, 'Different Name', 'blog', position: 9),
        );

        self::assertSame($original->id, $second->id);
        self::assertSame('Blog', $second->name);          // unchanged
        self::assertSame(0, $second->position);           // unchanged
    }

    public function testEnsureBehavesAsSaveWhenIdIsSet(): void
    {
        $cat = $this->storage->categories()->save(
            new Category(null, 'Blog', 'blog'),
        );
        \assert($cat->id !== null);

        // id !== null → routes through save(), which IS an update.
        $updated = $this->storage->categories()->ensure(
            new Category($cat->id, 'Renamed', 'blog'),
        );

        self::assertSame($cat->id, $updated->id);
        self::assertSame('Renamed', $updated->name);
    }

    public function testEnsureIsSafeToRunRepeatedly(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->storage->categories()->ensure(
                new Category(null, 'Blog', 'blog'),
            );
        }

        self::assertCount(1, $this->storage->categories()->findAll());
    }
}
