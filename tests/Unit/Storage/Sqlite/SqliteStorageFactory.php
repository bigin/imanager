<?php

declare(strict_types=1);

namespace Imanager\Tests\Unit\Storage\Sqlite;

use Imanager\Storage\SchemaManager;
use Imanager\Storage\Sqlite\MigrationLoader;
use Imanager\Storage\Sqlite\SqliteStorage;

/**
 * Test helper that builds a fresh in-memory SQLite database, migrates it
 * with the canonical SQL files from `config/schema/`, and wraps it in a
 * {@see SqliteStorage}.
 *
 * Each call returns a brand-new connection so contract subclasses that
 * extend the Phase 3 contracts get full test isolation for free.
 */
final class SqliteStorageFactory
{
    public static function inMemory(): SqliteStorage
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);
        $pdo->exec('PRAGMA foreign_keys = ON');

        $loader = new MigrationLoader(self::schemaDir());
        $manager = new SchemaManager($pdo, $loader->load());
        $manager->migrate();

        return new SqliteStorage($pdo);
    }

    public static function schemaDir(): string
    {
        return \dirname(__DIR__, 4) . '/config/schema';
    }
}
