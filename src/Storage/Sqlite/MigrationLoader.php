<?php

declare(strict_types=1);

namespace Imanager\Storage\Sqlite;

use Imanager\Exception\SchemaException;
use Imanager\Storage\Migration;

/**
 * Discovers `.sql` migration files in a directory.
 *
 * Files must match the pattern `NNNN_description.sql` (four-digit version,
 * underscore, snake-cased description). Anything else is ignored so that
 * `.gitkeep`, `README.md`, and friends can coexist in the schema folder.
 */
final readonly class MigrationLoader
{
    private const FILENAME_PATTERN = '/^(\d{4})_([a-zA-Z0-9_]+)\.sql$/';

    public function __construct(private string $directory) {}

    /**
     * @return list<Migration>
     */
    public function load(): array
    {
        if (! is_dir($this->directory)) {
            throw new SchemaException(\sprintf(
                'Migration directory "%s" does not exist',
                $this->directory,
            ));
        }

        $entries = scandir($this->directory);
        if ($entries === false) {
            throw new SchemaException(\sprintf(
                'Cannot read migration directory "%s"',
                $this->directory,
            ));
        }

        $migrations = [];
        foreach ($entries as $entry) {
            if (preg_match(self::FILENAME_PATTERN, $entry, $m) !== 1) {
                continue;
            }
            $migrations[] = new SqlFileMigration(
                version: (int) $m[1],
                description: str_replace('_', ' ', $m[2]),
                path: $this->directory . '/' . $entry,
            );
        }
        return $migrations;
    }
}
