<?php

declare(strict_types=1);

namespace Imanager\Storage;

/**
 * Single, idempotent forward migration.
 *
 * Each migration declares a numeric version and a human-readable description,
 * and applies itself against an open PDO connection. The {@see SchemaManager}
 * sequences pending migrations and records applied versions in
 * `schema_version`. Migrations are not reversible — corrective changes ship
 * as a new, higher-numbered migration.
 */
interface Migration
{
    /**
     * Strictly monotonic version number. Conventional layout is `NNNN_description`
     * in the filename; the integer part is what matters here.
     */
    public function version(): int;

    public function description(): string;

    /**
     * Apply the schema change. The connection is already inside a transaction
     * managed by `SchemaManager`; implementations should not commit or roll
     * back themselves.
     */
    public function apply(\PDO $connection): void;
}
