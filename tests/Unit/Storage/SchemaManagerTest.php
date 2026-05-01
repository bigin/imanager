<?php

declare(strict_types=1);

namespace Imanager\Tests\Unit\Storage;

use Imanager\Exception\SchemaException;
use Imanager\Storage\Migration;
use Imanager\Storage\SchemaManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SchemaManager::class)]
final class SchemaManagerTest extends TestCase
{
    private \PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new \PDO('sqlite::memory:');
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
    }

    public function testCurrentVersionIsZeroOnAFreshDatabase(): void
    {
        $manager = new SchemaManager($this->pdo, []);

        self::assertSame(0, $manager->currentVersion());
    }

    public function testEnsuresTheRegistryTableExistsOnFirstQuery(): void
    {
        $manager = new SchemaManager($this->pdo, []);
        $manager->currentVersion();

        $stmt = $this->pdo->query(
            "SELECT name FROM sqlite_master WHERE type='table' AND name='schema_version'",
        );
        self::assertInstanceOf(\PDOStatement::class, $stmt);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        self::assertNotEmpty($row);
    }

    public function testPendingListsMigrationsAboveCurrentVersionInOrder(): void
    {
        $manager = new SchemaManager($this->pdo, [
            $this->fakeMigration(2, 'second', 'CREATE TABLE b (id INTEGER)'),
            $this->fakeMigration(1, 'first', 'CREATE TABLE a (id INTEGER)'),
            $this->fakeMigration(3, 'third', 'CREATE TABLE c (id INTEGER)'),
        ]);

        $versions = array_map(
            static fn(Migration $m): int => $m->version(),
            $manager->pending(),
        );

        self::assertSame([1, 2, 3], $versions);
    }

    public function testMigrateAppliesEveryPendingMigrationInVersionOrder(): void
    {
        $manager = new SchemaManager($this->pdo, [
            $this->fakeMigration(2, 'second', 'CREATE TABLE b (id INTEGER)'),
            $this->fakeMigration(1, 'first', 'CREATE TABLE a (id INTEGER)'),
        ]);

        $applied = $manager->migrate();

        self::assertSame(2, $applied);
        self::assertSame(2, $manager->currentVersion());
        self::assertTrue($this->tableExists('a'));
        self::assertTrue($this->tableExists('b'));
    }

    public function testMigrateIsIdempotent(): void
    {
        $migrations = [
            $this->fakeMigration(1, 'first', 'CREATE TABLE a (id INTEGER)'),
        ];

        $manager = new SchemaManager($this->pdo, $migrations);
        self::assertSame(1, $manager->migrate());
        self::assertSame(0, $manager->migrate());
        self::assertSame(1, $manager->currentVersion());
    }

    public function testMigrateOnlyAppliesPendingMigrationsAfterPartialProgress(): void
    {
        $first = new SchemaManager($this->pdo, [
            $this->fakeMigration(1, 'first', 'CREATE TABLE a (id INTEGER)'),
        ]);
        self::assertSame(1, $first->migrate());

        $second = new SchemaManager($this->pdo, [
            $this->fakeMigration(1, 'first', 'CREATE TABLE a (id INTEGER)'),
            $this->fakeMigration(2, 'second', 'CREATE TABLE b (id INTEGER)'),
        ]);

        self::assertSame(1, $second->migrate());
        self::assertSame(2, $second->currentVersion());
        self::assertTrue($this->tableExists('b'));
    }

    public function testFailedMigrationRollsBackAndRaisesSchemaException(): void
    {
        $manager = new SchemaManager($this->pdo, [
            $this->fakeMigration(1, 'broken', 'CREATE TABLE WHERE garbage SQL'),
        ]);

        $thrown = null;
        try {
            $manager->migrate();
        } catch (SchemaException $e) {
            $thrown = $e;
        }

        if ($thrown === null) {
            self::fail('Expected SchemaException to be thrown');
        }
        self::assertStringContainsString('Schema migration 0001 failed', $thrown->getMessage());
        // Failed migration must NOT be recorded as applied.
        self::assertSame(0, $manager->currentVersion());
    }

    public function testConstructorRejectsDuplicateVersions(): void
    {
        $this->expectException(SchemaException::class);
        $this->expectExceptionMessage('Duplicate migration version 1');

        new SchemaManager($this->pdo, [
            $this->fakeMigration(1, 'first', 'CREATE TABLE a (id INTEGER)'),
            $this->fakeMigration(1, 'duplicate', 'CREATE TABLE b (id INTEGER)'),
        ]);
    }

    private function tableExists(string $name): bool
    {
        $stmt = $this->pdo->prepare(
            "SELECT name FROM sqlite_master WHERE type='table' AND name = :n",
        );
        $stmt->execute([':n' => $name]);
        return $stmt->fetch(\PDO::FETCH_ASSOC) !== false;
    }

    private function fakeMigration(int $version, string $description, string $sql): Migration
    {
        return new class ($version, $description, $sql) implements Migration {
            public function __construct(
                private int $version,
                private string $description,
                private string $sql,
            ) {}

            public function version(): int
            {
                return $this->version;
            }

            public function description(): string
            {
                return $this->description;
            }

            public function apply(\PDO $connection): void
            {
                $connection->exec($this->sql);
            }
        };
    }
}
