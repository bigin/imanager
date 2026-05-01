<?php

declare(strict_types=1);

namespace Imanager\Enum;

/**
 * SQLite column type affinity. Used when generating virtual columns from
 * JSON-extracted field values for indexing (Phase 4).
 *
 * @see https://sqlite.org/datatype3.html#type_affinity
 */
enum SqliteAffinity: string
{
    case Text = 'TEXT';
    case Integer = 'INTEGER';
    case Real = 'REAL';
    case Blob = 'BLOB';
}
