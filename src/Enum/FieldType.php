<?php

declare(strict_types=1);

namespace Imanager\Enum;

/**
 * Built-in field types shipped with iManager 2.0.
 *
 * The string value is the canonical serialized name used in SQLite's
 * `fields.type` column and in JSON exports. Treat it as a stable contract.
 *
 * Custom field types can be registered at runtime via the FieldTypeRegistry
 * (Phase 7) and do not need a case here.
 */
enum FieldType: string
{
    case Text = 'text';
    case LongText = 'longtext';
    case Editor = 'editor';
    case Slug = 'slug';
    case Datepicker = 'datepicker';
    case Dropdown = 'dropdown';
    case Checkbox = 'checkbox';
    case Integer = 'integer';
    case Decimal = 'decimal';
    case Money = 'money';
    case Password = 'password';
    case Hidden = 'hidden';
    case ArrayList = 'array';
    case Filepicker = 'filepicker';
    case Fileupload = 'fileupload';
    case Imageupload = 'imageupload';

    /**
     * SQLite type affinity used when generating an indexed column for a field
     * of this type. See https://sqlite.org/datatype3.html#type_affinity.
     */
    public function sqliteAffinity(): SqliteAffinity
    {
        return match ($this) {
            self::Integer, self::Datepicker, self::Checkbox => SqliteAffinity::Integer,
            self::Decimal, self::Money => SqliteAffinity::Real,
            default => SqliteAffinity::Text,
        };
    }
}
