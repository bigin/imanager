<?php

declare(strict_types=1);

namespace Imanager;

use Imanager\Cache\FilesystemCache;
use Imanager\Events\SubscriberListenerProvider;
use Imanager\Events\SyncEventDispatcher;
use Imanager\Field\FieldTypeRegistry;
use Imanager\Field\Types\ArrayListFieldType;
use Imanager\Field\Types\CheckboxFieldType;
use Imanager\Field\Types\DatepickerFieldType;
use Imanager\Field\Types\DecimalFieldType;
use Imanager\Field\Types\DropdownFieldType;
use Imanager\Field\Types\EditorFieldType;
use Imanager\Field\Types\FilepickerFieldType;
use Imanager\Field\Types\FileuploadFieldType;
use Imanager\Field\Types\HiddenFieldType;
use Imanager\Field\Types\ImageuploadFieldType;
use Imanager\Field\Types\IntegerFieldType;
use Imanager\Field\Types\LongTextFieldType;
use Imanager\Field\Types\MoneyFieldType;
use Imanager\Field\Types\PasswordFieldType;
use Imanager\Field\Types\SlugFieldType;
use Imanager\Field\Types\TextFieldType;
use Imanager\Files\FileStorage;
use Imanager\Files\ImageProcessor;
use Imanager\Files\LocalFileStorage;
use Imanager\Http\Csrf;
use Imanager\Http\NativeSessionStore;
use Imanager\Http\SessionStore;
use Imanager\Search\FullTextSearch;
use Imanager\Storage\CategoryRepository;
use Imanager\Storage\FieldRepository;
use Imanager\Storage\FileRepository;
use Imanager\Storage\ItemRepository;
use Imanager\Storage\SchemaManager;
use Imanager\Storage\Sqlite\ConnectionFactory;
use Imanager\Storage\Sqlite\MigrationLoader;
use Imanager\Storage\Sqlite\SqliteStorage;
use Imanager\Storage\Storage;
use Imanager\Validation\Sanitizer;
use League\Container\Container;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\EventDispatcher\ListenerProviderInterface;

/**
 * Production-ready DI container for embedding iManager 2.0.
 *
 * `Bootstrap::boot()` registers only Config and a PSR-3 logger; everything
 * else (PDO, repositories, field types, file storage, session store, …) is
 * left to the consumer so a host application can swap any layer. For the
 * common case — SQLite + LocalFileStorage + native PHP sessions + all 16
 * built-in field types — that DIY wiring runs to ~50 lines, which is a
 * needless adoption hurdle.
 *
 * `DefaultBootstrap::boot()` is that wiring as a single factory call. It
 * starts from `Bootstrap::boot()` and then registers the full standard
 * service graph onto the same {@see Container}, returning it. Consumers
 * who need to override any service can call `addShared()` afterwards —
 * League's container honours the last registration.
 *
 * Returned services (all `addShared`, i.e. singleton-per-container):
 *
 * - `Imanager\Config`, `Psr\Log\LoggerInterface` — via {@see Bootstrap::boot()}
 * - `PDO` — initialised via {@see ConnectionFactory},
 *   {@see SchemaManager} runs pending migrations from the library's
 *   `config/schema/` directory before the connection is returned.
 * - `Imanager\Validation\Sanitizer`
 * - PSR-14 trio: `SubscriberListenerProvider`, `ListenerProviderInterface`,
 *   `EventDispatcherInterface` (the same `SubscriberListenerProvider`
 *   instance is reachable through both bindings).
 * - Storage: `SqliteStorage`, `Storage` (alias), the four repositories
 *   (`CategoryRepository`, `FieldRepository`, `ItemRepository`,
 *   `FileRepository`).
 * - `FullTextSearch`, `FilesystemCache`.
 * - `LocalFileStorage`, `FileStorage` (alias), `ImageProcessor` (default).
 * - `SessionStore` (a `NativeSessionStore`), `Csrf`.
 * - `FieldTypeRegistry` pre-populated with all 16 built-in field types.
 *
 * Domain-event listeners are intentionally **not** wired here — they are
 * application-specific and must be subscribed by the host after boot.
 *
 * @phpstan-import-type ConfigArray from Config
 */
final class DefaultBootstrap
{
    /**
     * @param array{
     *     configOverrides?: ConfigArray,
     *     sessionName?: string,
     *     csrfMaxTokens?: int,
     *     schemaDir?: string,
     * } $options
     */
    public static function boot(
        string $databasePath,
        string $uploadsPath,
        string $uploadsUrl,
        string $cachePath,
        array $options = [],
    ): Container {
        $configOverrides = $options['configOverrides'] ?? [];
        $sessionName     = $options['sessionName']     ?? 'imanager';
        $csrfMaxTokens   = $options['csrfMaxTokens']   ?? 10;
        $schemaDir       = $options['schemaDir']       ?? \dirname(__DIR__) . '/config/schema';

        // Ensure the directories the standard services write to exist —
        // SQLite creates the .db file but not its parent, and the cache
        // and upload stores assume their roots are already there. Hosts
        // that hand-wire via Bootstrap::boot() keep full control of dir
        // lifecycle; this convenience is specific to the copy-paste
        // factory.
        self::ensureDirectory(\dirname($databasePath), 'database parent');
        self::ensureDirectory($uploadsPath, 'uploads');
        self::ensureDirectory($cachePath, 'cache');

        $container = Bootstrap::boot($configOverrides);

        $container->addShared(\PDO::class, static function () use ($databasePath, $schemaDir): \PDO {
            $pdo = (new ConnectionFactory($databasePath))->create();
            $loader = new MigrationLoader($schemaDir);
            (new SchemaManager($pdo, $loader->load()))->migrate();
            return $pdo;
        });

        $container->addShared(Sanitizer::class, static fn(): Sanitizer => new Sanitizer());

        $container->addShared(SubscriberListenerProvider::class, static fn(): SubscriberListenerProvider
            => new SubscriberListenerProvider());
        $container->addShared(ListenerProviderInterface::class, static fn(): ListenerProviderInterface
            => $container->get(SubscriberListenerProvider::class));
        $container->addShared(EventDispatcherInterface::class, static fn(): EventDispatcherInterface
            => new SyncEventDispatcher($container->get(ListenerProviderInterface::class)));

        $container->addShared(SqliteStorage::class, static fn(): SqliteStorage
            => new SqliteStorage(
                $container->get(\PDO::class),
                $container->get(EventDispatcherInterface::class),
            ));
        $container->addShared(Storage::class, static fn(): Storage
            => $container->get(SqliteStorage::class));

        $container->addShared(CategoryRepository::class, static fn(): CategoryRepository
            => $container->get(SqliteStorage::class)->categories());
        $container->addShared(FieldRepository::class, static fn(): FieldRepository
            => $container->get(SqliteStorage::class)->fields());
        $container->addShared(ItemRepository::class, static fn(): ItemRepository
            => $container->get(SqliteStorage::class)->items());
        $container->addShared(FileRepository::class, static fn(): FileRepository
            => $container->get(SqliteStorage::class)->files());

        $container->addShared(FullTextSearch::class, static fn(): FullTextSearch
            => new FullTextSearch($container->get(\PDO::class)));

        $container->addShared(FilesystemCache::class, static fn(): FilesystemCache
            => new FilesystemCache($cachePath));

        $container->addShared(LocalFileStorage::class, static fn(): LocalFileStorage
            => new LocalFileStorage($uploadsPath, $uploadsUrl));
        $container->addShared(FileStorage::class, static fn(): FileStorage
            => $container->get(LocalFileStorage::class));

        $container->addShared(ImageProcessor::class, static fn(): ImageProcessor
            => ImageProcessor::default());

        $container->addShared(SessionStore::class, static fn(): SessionStore
            => new NativeSessionStore($sessionName));
        $container->addShared(Csrf::class, static fn(): Csrf
            => new Csrf($container->get(SessionStore::class), maxTokens: $csrfMaxTokens));

        $container->addShared(FieldTypeRegistry::class, static function () use ($container): FieldTypeRegistry {
            $registry = new FieldTypeRegistry();
            $sanitizer = $container->get(Sanitizer::class);
            $registry->register(new TextFieldType($sanitizer));
            $registry->register(new LongTextFieldType($sanitizer));
            $registry->register(new EditorFieldType($sanitizer));
            $registry->register(new SlugFieldType($sanitizer));
            $registry->register(new PasswordFieldType($sanitizer));
            $registry->register(new IntegerFieldType($sanitizer));
            $registry->register(new DecimalFieldType($sanitizer));
            $registry->register(new MoneyFieldType($sanitizer));
            $registry->register(new CheckboxFieldType($sanitizer));
            $registry->register(new DropdownFieldType($sanitizer));
            $registry->register(new DatepickerFieldType($sanitizer));
            $registry->register(new HiddenFieldType($sanitizer));
            $registry->register(new ArrayListFieldType($sanitizer));
            $registry->register(new FileuploadFieldType($sanitizer));
            $registry->register(new ImageuploadFieldType($sanitizer));
            $registry->register(new FilepickerFieldType($sanitizer));
            return $registry;
        });

        return $container;
    }

    private function __construct() {}

    private static function ensureDirectory(string $path, string $purpose): void
    {
        // `:memory:` short-circuit, plus the cwd-shaped paths dirname()
        // hands back for bare filenames — those need no mkdir.
        if ($path === '' || $path === '.' || $path === ':memory:') {
            return;
        }
        if (is_dir($path)) {
            return;
        }
        if (! @mkdir($path, 0775, true) && ! is_dir($path)) {
            throw new \RuntimeException(\sprintf(
                'DefaultBootstrap could not create the %s directory at %s. Create it manually, fix the parent permissions, or wire services via Imanager\\Bootstrap::boot() (which leaves directory lifecycle to the host).',
                $purpose,
                $path,
            ));
        }
    }
}
