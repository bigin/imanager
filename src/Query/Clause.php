<?php

declare(strict_types=1);

namespace Imanager\Query;

/**
 * A single boolean predicate inside a {@see Query}: `<field> <op> <value>`.
 *
 * A clause's `field` may name either a structural Item attribute
 * (id, category_id, name, label, position, active, created, updated) or a
 * dynamic field stored in the JSON `data` blob; the storage backend resolves
 * which path to take.
 */
final readonly class Clause
{
    public function __construct(
        public string $field,
        public Operator $op,
        public mixed $value,
    ) {}
}
