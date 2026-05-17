<?php

declare(strict_types=1);

namespace Imanager\Search;

/**
 * Composes the `body` column written into `items_fts`.
 *
 * Centralized so both the per-save writer
 * ({@see \Imanager\Storage\Sqlite\SqliteItemRepository::syncFts()}) and the
 * bulk rebuilder ({@see FullTextSearch::rebuild()}) flatten data the same
 * way — drift between the two would silently corrupt search results.
 */
final readonly class FtsBody
{
    /**
     * Flatten an item's structural + dynamic fields into the single string
     * stored in `items_fts.body`.
     *
     * `$name` and `$label` are structural columns on the items table and
     * are always concatenated in. `$data` is the dynamic per-field bag.
     *
     * When `$allowedKeys` is non-null, only top-level entries in `$data`
     * whose key appears in the list are walked — that's how the per-field
     * `searchable` flag (honored from 2.2.0) takes effect. When `null`, the
     * whole `$data` blob is flattened (legacy behavior, retained for the
     * 2.0/2.1 constructor signature of `SqliteItemRepository`).
     *
     * @param array<string, mixed> $data
     * @param list<string>|null    $allowedKeys
     */
    public static function compose(
        ?string $name,
        ?string $label,
        array $data,
        ?array $allowedKeys,
    ): string {
        if ($allowedKeys !== null) {
            $data = array_intersect_key($data, array_flip($allowedKeys));
        }

        $parts = [];
        array_walk_recursive($data, static function (mixed $value) use (&$parts): void {
            if (\is_string($value) || \is_int($value) || \is_float($value)) {
                $parts[] = (string) $value;
            }
        });

        return ($name ?? '') . ' ' . ($label ?? '') . ' ' . implode(' ', $parts);
    }
}
