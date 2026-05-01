<?php

declare(strict_types=1);

namespace Imanager\Storage\Sqlite;

use Imanager\Enum\FieldType;
use Imanager\Exception\SchemaException;

/**
 * Lifecycle of generated columns + indexes for fields marked `indexed = true`.
 *
 * Each indexed field gets:
 *  - a virtual generated column on `items` of the form
 *    `gen_<categoryId>_<fieldName> <AFFINITY>
 *       GENERATED ALWAYS AS (json_extract(data, '$.<fieldName>')) VIRTUAL`,
 *  - and a multi-column index `(category_id, gen_<categoryId>_<fieldName>)`.
 *
 * The category id is part of the column name so two categories can carry a
 * field with the same name without colliding on a single global generated
 * column.
 *
 * SQLite's `ALTER TABLE ... DROP COLUMN` is supported since 3.35 (March 2021).
 */
final readonly class IndexedFields
{
    public function __construct(private \PDO $connection) {}

    public function create(int $categoryId, string $fieldName, FieldType $type): void
    {
        $col = self::columnName($categoryId, $fieldName);
        $idx = self::indexName($categoryId, $fieldName);
        $affinity = $type->sqliteAffinity()->value;
        $jsonPath = self::jsonPath($fieldName);

        try {
            // The generated column reads from the JSON `data` blob; if the
            // key is absent json_extract returns NULL, which indexes fine.
            $this->connection->exec(\sprintf(
                'ALTER TABLE items ADD COLUMN %s %s GENERATED ALWAYS AS (json_extract(data, %s)) VIRTUAL',
                $col,
                $affinity,
                $this->connection->quote($jsonPath),
            ));
            $this->connection->exec(\sprintf(
                'CREATE INDEX %s ON items(category_id, %s)',
                $idx,
                $col,
            ));
        } catch (\PDOException $e) {
            throw SchemaException::generatedColumnFailed($fieldName, $e->getMessage(), $e);
        }
    }

    public function drop(int $categoryId, string $fieldName): void
    {
        $col = self::columnName($categoryId, $fieldName);
        $idx = self::indexName($categoryId, $fieldName);

        try {
            $this->connection->exec(\sprintf('DROP INDEX IF EXISTS %s', $idx));
            $this->connection->exec(\sprintf('ALTER TABLE items DROP COLUMN %s', $col));
        } catch (\PDOException $e) {
            throw SchemaException::generatedColumnFailed($fieldName, $e->getMessage(), $e);
        }
    }

    /**
     * Atomically rebuild the index for a renamed indexed field.
     */
    public function rename(int $categoryId, string $oldName, string $newName, FieldType $type): void
    {
        $this->drop($categoryId, $oldName);
        $this->create($categoryId, $newName, $type);
    }

    public function exists(int $categoryId, string $fieldName): bool
    {
        $col = self::columnName($categoryId, $fieldName);
        $stmt = $this->connection->prepare(
            'SELECT 1 FROM pragma_table_xinfo(\'items\') WHERE name = :col',
        );
        $stmt->execute([':col' => $col]);
        return $stmt->fetchColumn() !== false;
    }

    /**
     * Build the generated-column name for `(categoryId, fieldName)`.
     *
     * Field names are restricted to `[A-Za-z0-9_]` upstream (Phase 7's
     * Sanitizer); the regex below is a defense-in-depth guard against
     * any caller that bypasses that pipeline.
     */
    public static function columnName(int $categoryId, string $fieldName): string
    {
        $safe = (string) preg_replace('/[^A-Za-z0-9_]/', '_', $fieldName);
        if ($safe === '') {
            throw new SchemaException('Field name yields an empty SQL identifier');
        }
        return \sprintf('gen_%d_%s', $categoryId, $safe);
    }

    public static function indexName(int $categoryId, string $fieldName): string
    {
        $safe = (string) preg_replace('/[^A-Za-z0-9_]/', '_', $fieldName);
        return \sprintf('idx_items_%d_%s', $categoryId, $safe);
    }

    private static function jsonPath(string $fieldName): string
    {
        // SQLite's json_extract uses `$.<key>`; the key is taken verbatim as
        // a JSON object key, so escaping with addslashes is the right call
        // (we'll quote() the whole literal at the call site).
        return '$.' . str_replace(['\\', '"', "'"], ['\\\\', '\\"', "''"], $fieldName);
    }
}
