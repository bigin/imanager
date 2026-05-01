<?php

declare(strict_types=1);

namespace Imanager\Tests\Unit\Storage\Sqlite;

use Imanager\Exception\StorageException;
use Imanager\Storage\Sqlite\ConnectionFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ConnectionFactory::class)]
final class ConnectionFactoryTest extends TestCase
{
    public function testInMemoryProducesAUsablePdoConnection(): void
    {
        $pdo = ConnectionFactory::inMemory()->create();

        self::assertSame(\PDO::ERRMODE_EXCEPTION, $pdo->getAttribute(\PDO::ATTR_ERRMODE));
        self::assertSame(\PDO::FETCH_ASSOC, $pdo->getAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE));
    }

    public function testForeignKeysAreEnabled(): void
    {
        $pdo = ConnectionFactory::inMemory()->create();
        $stmt = $pdo->query('PRAGMA foreign_keys');
        self::assertInstanceOf(\PDOStatement::class, $stmt);

        self::assertSame(1, (int) $stmt->fetchColumn());
    }

    public function testSynchronousIsNormal(): void
    {
        $pdo = ConnectionFactory::inMemory()->create();
        $stmt = $pdo->query('PRAGMA synchronous');
        self::assertInstanceOf(\PDOStatement::class, $stmt);

        // PRAGMA synchronous returns the integer code (0 OFF, 1 NORMAL, 2 FULL, 3 EXTRA)
        self::assertSame(1, (int) $stmt->fetchColumn());
    }

    public function testFailingPathRaisesStorageException(): void
    {
        // Pointing at a directory that doesn't exist would surface a PDO error
        // on connect; SQLite happily creates files but cannot create folders.
        $factory = new ConnectionFactory('/this/path/cannot/exist/imanager.db');

        $this->expectException(StorageException::class);
        $factory->create();
    }
}
