<?php

declare(strict_types=1);

namespace Imanager\Field;

use Imanager\Domain\Field;
use Imanager\Enum\SqliteAffinity;

/**
 * Contract every field type — built-in or third-party — must implement.
 *
 * Three responsibilities, three methods:
 *
 *  1. {@see validate()} checks user-supplied raw input against the field
 *     definition AND, on success, produces the canonical storage value
 *     (coercion is part of the same call to keep the API one-shot).
 *  2. {@see render()} produces an HTML form input for the editor.
 *  3. {@see defaultConfig()} declares the per-field config keys this type
 *     understands, with the defaults that apply if the field hasn't
 *     overridden them.
 *
 * The two static methods ({@see name()}, {@see affinity()}) describe the
 * type itself — they're the same for every instance, so the registry can
 * key on `name()` without instantiating.
 */
interface FieldTypePlugin
{
    /**
     * Stable canonical identifier. Matches the corresponding
     * `Imanager\Enum\FieldType` value.
     */
    public static function name(): string;

    /**
     * SQLite type affinity used for indexed generated columns
     * (see Phase 4's `IndexedFields`).
     */
    public static function affinity(): SqliteAffinity;

    /**
     * Default values for this type's per-field config keys. Merged with
     * `Field::$config` (caller's overrides win) at validation/render time.
     *
     * @return array<string, mixed>
     */
    public function defaultConfig(): array;

    /**
     * Validate raw input AND produce the coerced storage value.
     *
     * On success, return `ValidationResult::ok($coercedValue)`.
     * On failure, return `ValidationResult::failed($errorCode, $message)`.
     */
    public function validate(mixed $rawValue, Field $field): ValidationResult;

    /**
     * Render an HTML form input for editing this field.
     *
     * `$value` is the already-coerced storage value (whatever shape
     * `validate()` produces); pass `null` when rendering a brand-new item
     * for which no value has been set yet.
     */
    public function render(mixed $value, Field $field, RenderContext $context): string;
}
