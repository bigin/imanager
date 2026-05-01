<?php

declare(strict_types=1);

namespace Imanager\Storage\Sqlite;

use Imanager\Exception\SchemaException;
use Imanager\Storage\Migration;

/**
 * Migration whose body is a `.sql` file on disk.
 *
 * Filename convention: `NNNN_<description>.sql` where `NNNN` is a strictly
 * monotonic four-digit version. The numeric prefix and the underscore-
 * separated tail are parsed by {@see MigrationLoader}.
 */
final readonly class SqlFileMigration implements Migration
{
    public function __construct(
        private int $version,
        private string $description,
        private string $path,
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
        $sql = @file_get_contents($this->path);
        if ($sql === false) {
            throw SchemaException::migrationFailed(
                $this->version,
                \sprintf('Cannot read migration file "%s"', $this->path),
            );
        }
        $connection->exec($sql);
    }
}
