<?php

declare(strict_types=1);

namespace Imanager\Cli\Support;

use Imanager\Storage\SchemaManager;
use Imanager\Storage\Sqlite\ConnectionFactory;
use Imanager\Storage\Sqlite\MigrationLoader;
use Imanager\Storage\Sqlite\SqliteStorage;
use Symfony\Component\Console\Output\OutputInterface;

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
     * Apply pending migrations on `$connection`. Returns the number applied
     * (0 when the schema is already at head). When `$output` is provided,
     * announces each pending migration before applying so users see what
     * just happened — avoids the "silent migration on first command run"
     * surprise.
     *
     * Use this from commands whose work depends on the current schema
     * (e.g. `fts:rebuild` re-derives FTS from the items+fields tables and
     * is incorrect against a stale schema). Diagnostic commands that
     * inspect the file as-is (`dump`, `repair`, `schema:status`) should
     * NOT call this — they're expected to report on the actual on-disk
     * state.
     */
    public static function migrateIfNeeded(\PDO $connection, ?OutputInterface $output = null): int
    {
        $manager = self::schemaManager($connection);
        $pending = $manager->pending();
        if ($pending === []) {
            return 0;
        }

        if ($output !== null) {
            $output->writeln(\sprintf(
                '<info>Applying %d pending migration(s):</info>',
                \count($pending),
            ));
            foreach ($pending as $migration) {
                $output->writeln(\sprintf(
                    '  %04d — %s',
                    $migration->version(),
                    $migration->description(),
                ));
            }
        }

        return $manager->migrate();
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
