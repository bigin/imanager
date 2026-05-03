<?php

declare(strict_types=1);

namespace Imanager\Cli\Support;

use Imanager\Storage\SchemaManager;
use Imanager\Storage\Sqlite\ConnectionFactory;
use Imanager\Storage\Sqlite\MigrationLoader;
use Imanager\Storage\Sqlite\SqliteStorage;

/**
 * Shared CLI plumbing: open a SQLite database, run migrations, hand back the
 * configured pieces (PDO, SchemaManager, SqliteStorage) so each command can
 * pick what it needs without re-deriving the schema directory.
 */
final readonly class DatabaseFactory
{
    public static function connect(string $databasePath): \PDO
    {
        return (new ConnectionFactory($databasePath))->create();
    }

    public static function schemaManager(\PDO $connection): SchemaManager
    {
        $loader = new MigrationLoader(self::schemaDir());
        return new SchemaManager($connection, $loader->load());
    }

    public static function storage(\PDO $connection): SqliteStorage
    {
        return new SqliteStorage($connection);
    }

    /**
     * Resolve the canonical `config/schema/` directory. Works whether
     * iManager is running from its own checkout or installed under
     * `vendor/bigins/imanager/`.
     */
    public static function schemaDir(): string
    {
        return \dirname(__DIR__, 3) . '/config/schema';
    }
}
