<?php

declare(strict_types=1);

namespace Imanager\Tests\Unit\Storage\Sqlite;

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
use Imanager\Storage\SchemaManager;
use Imanager\Storage\Sqlite\MigrationLoader;
use Imanager\Storage\Sqlite\SqliteStorage;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;

#[CoversClass(SqliteStorage::class)]
final class StorageEventEmissionTest extends TestCase
{
    private \PDO $pdo;
    private CapturingDispatcher $events;
    private SqliteStorage $storage;

    protected function setUp(): void
    {
        $this->pdo = new \PDO('sqlite::memory:');
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);
        $this->pdo->exec('PRAGMA foreign_keys = ON');
        (new SchemaManager(
            $this->pdo,
            (new MigrationLoader(\dirname(__DIR__, 4) . '/config/schema'))->load(),
        ))->migrate();

        $this->events = new CapturingDispatcher();
        $this->storage = new SqliteStorage($this->pdo, $this->events);
    }

    public function testCategoryCreateUpdateDeleteFires(): void
    {
        $cat = $this->storage->categories()->save(new Category(null, 'Pages', 'pages'));
        \assert($cat->id !== null);
        $this->storage->categories()->save(new Category($cat->id, 'Pages', 'pages', position: 5));
        $this->storage->categories()->delete($cat->id);

        self::assertSame(
            [CategoryCreated::class, CategoryUpdated::class, CategoryDeleted::class],
            $this->events->classes(),
        );
        $created = $this->events->events[0];
        self::assertInstanceOf(CategoryCreated::class, $created);
        self::assertSame('pages', $created->category->slug);
    }

    public function testFieldCreateUpdateDeleteFires(): void
    {
        $cat = $this->storage->categories()->save(new Category(null, 'Pages', 'pages'));
        \assert($cat->id !== null);
        $this->events->reset();

        $field = $this->storage->fields()->save(
            new Field(null, $cat->id, 'title', 'Title', FieldType::Text, 1, true),
        );
        \assert($field->id !== null);
        $this->storage->fields()->save(
            new Field($field->id, $cat->id, 'title', 'Headline', FieldType::Text, 1, true),
        );
        $this->storage->fields()->delete($field->id);

        self::assertSame(
            [FieldCreated::class, FieldUpdated::class, FieldDeleted::class],
            $this->events->classes(),
        );
        $deleted = $this->events->events[2];
        self::assertInstanceOf(FieldDeleted::class, $deleted);
        self::assertSame('title', $deleted->name);
        self::assertSame($field->id, $deleted->fieldId);
    }

    public function testItemCreateUpdateDeleteFires(): void
    {
        $cat = $this->storage->categories()->save(new Category(null, 'Pages', 'pages'));
        \assert($cat->id !== null);
        $this->events->reset();

        $item = $this->storage->items()->save(
            new Item(null, $cat->id, 'Hello', null, 1, true, ['slug' => 'hello']),
        );
        \assert($item->id !== null);
        $this->storage->items()->save(
            new Item($item->id, $cat->id, 'Hi there', null, 1, true, ['slug' => 'hello']),
        );
        $this->storage->items()->delete($item->id);

        self::assertSame(
            [ItemCreated::class, ItemUpdated::class, ItemDeleted::class],
            $this->events->classes(),
        );
        $update = $this->events->events[1];
        self::assertInstanceOf(ItemUpdated::class, $update);
        self::assertSame('Hello', $update->previous->name);
        self::assertSame('Hi there', $update->current->name);
    }

    public function testItemDeletedFiresBeforeRowIsGone(): void
    {
        $cat = $this->storage->categories()->save(new Category(null, 'Pages', 'pages'));
        \assert($cat->id !== null);
        $item = $this->storage->items()->save(new Item(null, $cat->id, 'Bye', null, 1, true));
        \assert($item->id !== null);

        // Listener fires before the SQL DELETE — Plan §14e contract — so the
        // row must still be readable from the listener's perspective.
        $observed = null;
        $this->events->reset();
        $repo = $this->storage->items();
        $this->events->subscribe(ItemDeleted::class, function (ItemDeleted $e) use ($repo, &$observed): void {
            $observed = $repo->find($e->itemId);
        });
        $repo->delete($item->id);

        self::assertNotNull($observed, 'Listener should still see the row before the actual DELETE.');
        self::assertNull($repo->find($item->id), 'Row must be gone after delete() returns.');
    }

    public function testCategoryEnsureFiresOnInsertButNotOnHit(): void
    {
        $this->storage->categories()->ensure(new Category(null, 'Pages', 'pages'));
        // Second call hits an existing row — must not emit a second CategoryCreated.
        $this->storage->categories()->ensure(new Category(null, 'Pages', 'pages'));

        self::assertSame([CategoryCreated::class], $this->events->classes());
    }

    public function testFieldEnsureFiresOnInsertButNotOnHit(): void
    {
        $cat = $this->storage->categories()->save(new Category(null, 'Pages', 'pages'));
        \assert($cat->id !== null);
        $this->events->reset();

        $this->storage->fields()->ensure(new Field(null, $cat->id, 'title', null, FieldType::Text));
        $this->storage->fields()->ensure(new Field(null, $cat->id, 'title', null, FieldType::Text));

        self::assertSame([FieldCreated::class], $this->events->classes());
    }
}

/**
 * Minimal dispatcher that records every event it sees and lets a test
 * subscribe an inline listener for a specific class. Avoids pulling
 * in the production SubscriberListenerProvider so the storage tests
 * stay independent of that wiring.
 */
final class CapturingDispatcher implements EventDispatcherInterface
{
    /** @var list<DomainEvent> */
    public array $events = [];

    /** @var array<class-string, list<callable>> */
    private array $listeners = [];

    public function dispatch(object $event): object
    {
        if ($event instanceof DomainEvent) {
            $this->events[] = $event;
        }
        foreach ($this->listeners as $class => $callbacks) {
            if ($event instanceof $class) {
                foreach ($callbacks as $cb) {
                    $cb($event);
                }
            }
        }
        return $event;
    }

    /**
     * @param class-string $class
     */
    public function subscribe(string $class, callable $callback): void
    {
        $this->listeners[$class][] = $callback;
    }

    public function reset(): void
    {
        $this->events = [];
        $this->listeners = [];
    }

    /**
     * @return list<class-string>
     */
    public function classes(): array
    {
        return array_map(static fn(DomainEvent $e): string => $e::class, $this->events);
    }
}
