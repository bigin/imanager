<?php

declare(strict_types=1);

namespace Imanager\Tests\Unit\Storage\Sqlite;

use Imanager\Exception\SchemaException;
use Imanager\Storage\Sqlite\MigrationLoader;
use Imanager\Storage\Sqlite\SqlFileMigration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(MigrationLoader::class)]
#[CoversClass(SqlFileMigration::class)]
final class MigrationLoaderTest extends TestCase
{
    private string $tmp = '';

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/imanager-migrations-' . uniqid();
        mkdir($this->tmp, 0o755, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tmp . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->tmp);
    }

    public function testLoadsCanonicalMigrationFiles(): void
    {
        file_put_contents($this->tmp . '/0001_initial.sql', 'CREATE TABLE a (id INTEGER)');
        file_put_contents($this->tmp . '/0002_more_tables.sql', 'CREATE TABLE b (id INTEGER)');

        $migrations = (new MigrationLoader($this->tmp))->load();

        self::assertCount(2, $migrations);
        self::assertSame(1, $migrations[0]->version());
        self::assertSame('initial', $migrations[0]->description());
        self::assertSame(2, $migrations[1]->version());
        self::assertSame('more tables', $migrations[1]->description());
    }

    public function testIgnoresFilesThatDoNotMatchTheNamingPattern(): void
    {
        file_put_contents($this->tmp . '/0001_real.sql', 'CREATE TABLE a (id INTEGER)');
        file_put_contents($this->tmp . '/README.md', '# notes');
        file_put_contents($this->tmp . '/no-prefix.sql', 'CREATE TABLE b (id INTEGER)');

        $migrations = (new MigrationLoader($this->tmp))->load();

        self::assertCount(1, $migrations);
        self::assertSame('real', $migrations[0]->description());
    }

    public function testThrowsForMissingDirectory(): void
    {
        $this->expectException(SchemaException::class);
        (new MigrationLoader($this->tmp . '/does-not-exist'))->load();
    }

    public function testCanonicalSchemaDirectoryProducesExactlyTheExpectedMigrations(): void
    {
        $loader = new MigrationLoader(SqliteStorageFactory::schemaDir());
        $migrations = $loader->load();

        self::assertNotEmpty($migrations);
        self::assertSame(1, $migrations[0]->version());
        self::assertSame('initial', $migrations[0]->description());
    }
}
