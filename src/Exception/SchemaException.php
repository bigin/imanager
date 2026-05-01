<?php

declare(strict_types=1);

namespace Imanager\Exception;

/**
 * Thrown for failures in schema management:
 * migrations, generated-column lifecycle, unique-key violations on schema-level.
 */
final class SchemaException extends \RuntimeException implements ImanagerException
{
    public static function migrationFailed(int $version, string $reason, ?\Throwable $previous = null): self
    {
        return new self(
            \sprintf('Schema migration %04d failed: %s', $version, $reason),
            0,
            $previous,
        );
    }

    public static function generatedColumnFailed(string $field, string $reason, ?\Throwable $previous = null): self
    {
        return new self(
            \sprintf('Generated column for field "%s" failed: %s', $field, $reason),
            0,
            $previous,
        );
    }
}
