<?php

declare(strict_types=1);

namespace Imanager\Tests\Unit;

use Imanager\Cache\FilesystemCache;
use Imanager\Config;
use Imanager\DefaultBootstrap;
use Imanager\Events\SubscriberListenerProvider;
use Imanager\Field\FieldTypeRegistry;
use Imanager\Files\FileStorage;
use Imanager\Files\ImageProcessor;
use Imanager\Files\LocalFileStorage;
use Imanager\Http\Csrf;
use Imanager\Http\SessionStore;
use Imanager\Search\FullTextSearch;
use Imanager\Storage\CategoryRepository;
use Imanager\Storage\FieldRepository;
use Imanager\Storage\FileRepository;
use Imanager\Storage\ItemRepository;
use Imanager\Storage\Sqlite\SqliteStorage;
use Imanager\Storage\Storage;
use Imanager\Validation\Sanitizer;
use League\Container\Container;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\EventDispatcher\ListenerProviderInterface;

#[CoversClass(DefaultBootstrap::class)]
final class DefaultBootstrapTest extends TestCase
{
    private string $databasePath;
    private string $uploadsPath;
    private string $cachePath;

    protected function setUp(): void
    {
        $tmp = sys_get_temp_dir() . '/imanager-default-bootstrap-' . bin2hex(random_bytes(4));
        $this->databasePath = $tmp . '/imanager.db';
        $this->uploadsPath  = $tmp . '/uploads';
        $this->cachePath    = $tmp . '/cache';
        mkdir($tmp, 0777, true);
        mkdir($this->uploadsPath, 0777, true);
        mkdir($this->cachePath, 0777, true);
    }

    protected function tearDown(): void
    {
        $dir = \dirname($this->databasePath);
        if (! is_dir($dir)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($it as $entry) {
            $path = (string) $entry;
            is_dir($path) ? rmdir($path) : unlink($path);
        }
        rmdir($dir);
    }

    public function testBootReturnsAContainer(): void
    {
        $container = DefaultBootstrap::boot(
            $this->databasePath,
            $this->uploadsPath,
            '/uploads',
            $this->cachePath,
        );
        self::assertInstanceOf(Container::class, $container);
    }

    public function testContainerExposesAllStandardServices(): void
    {
        $container = DefaultBootstrap::boot(
            $this->databasePath,
            $this->uploadsPath,
            '/uploads',
            $this->cachePath,
        );

        // Core
        self::assertInstanceOf(Config::class, $container->get(Config::class));
        self::assertInstanceOf(\PDO::class, $container->get(\PDO::class));
        self::assertInstanceOf(Sanitizer::class, $container->get(Sanitizer::class));

        // PSR-14 trio shares one provider instance
        $provider     = $container->get(SubscriberListenerProvider::class);
        $providerIntf = $container->get(ListenerProviderInterface::class);
        self::assertSame($provider, $providerIntf);
        self::assertInstanceOf(EventDispatcherInterface::class, $container->get(EventDispatcherInterface::class));

        // Storage + 4 repositories
        $storage = $container->get(SqliteStorage::class);
        self::assertSame($storage, $container->get(Storage::class));
        self::assertInstanceOf(CategoryRepository::class, $container->get(CategoryRepository::class));
        self::assertInstanceOf(FieldRepository::class, $container->get(FieldRepository::class));
        self::assertInstanceOf(ItemRepository::class, $container->get(ItemRepository::class));
        self::assertInstanceOf(FileRepository::class, $container->get(FileRepository::class));

        // Search / cache / files / session
        self::assertInstanceOf(FullTextSearch::class, $container->get(FullTextSearch::class));
        self::assertInstanceOf(FilesystemCache::class, $container->get(FilesystemCache::class));
        $local = $container->get(LocalFileStorage::class);
        self::assertSame($local, $container->get(FileStorage::class));
        self::assertInstanceOf(ImageProcessor::class, $container->get(ImageProcessor::class));
        self::assertInstanceOf(SessionStore::class, $container->get(SessionStore::class));
        self::assertInstanceOf(Csrf::class, $container->get(Csrf::class));
    }

    public function testFieldTypeRegistryHasAllSixteenBuiltins(): void
    {
        $container = DefaultBootstrap::boot(
            $this->databasePath,
            $this->uploadsPath,
            '/uploads',
            $this->cachePath,
        );
        $registry = $container->get(FieldTypeRegistry::class);

        self::assertInstanceOf(FieldTypeRegistry::class, $registry);
        self::assertCount(16, $registry->names());
    }

    public function testSchemaMigrationsRunOnFirstPdoResolve(): void
    {
        $container = DefaultBootstrap::boot(
            $this->databasePath,
            $this->uploadsPath,
            '/uploads',
            $this->cachePath,
        );
        $pdo = $container->get(\PDO::class);

        $tables = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' ORDER BY name")
            ->fetchAll(\PDO::FETCH_COLUMN);

        self::assertContains('items', $tables);
        self::assertContains('fields', $tables);
        self::assertContains('categories', $tables);
        self::assertContains('files', $tables);
        self::assertContains('schema_version', $tables);
    }

    public function testConfigOverridesAreApplied(): void
    {
        $container = DefaultBootstrap::boot(
            $this->databasePath,
            $this->uploadsPath,
            '/uploads',
            $this->cachePath,
            ['configOverrides' => ['debug' => true, 'maxItemsPerPage' => 33]],
        );
        $config = $container->get(Config::class);

        self::assertTrue($config->debug);
        self::assertSame(33, $config->maxItemsPerPage);
    }

    public function testServicesAreSharedSingletons(): void
    {
        $container = DefaultBootstrap::boot(
            $this->databasePath,
            $this->uploadsPath,
            '/uploads',
            $this->cachePath,
        );

        self::assertSame($container->get(\PDO::class), $container->get(\PDO::class));
        self::assertSame(
            $container->get(EventDispatcherInterface::class),
            $container->get(EventDispatcherInterface::class),
        );
        self::assertSame(
            $container->get(FieldTypeRegistry::class),
            $container->get(FieldTypeRegistry::class),
        );
    }
}
