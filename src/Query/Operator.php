<?php

declare(strict_types=1);

namespace Imanager\Query;

/**
 * Comparison operators supported by {@see Query} where-clauses.
 *
 * `Like` carries SQL LIKE semantics: `%` matches any sequence of characters,
 * `_` matches a single character. The InMemory backend translates the pattern
 * to a regex; SQLite uses the operator natively (case-insensitive for ASCII).
 */
enum Operator: string
{
    case Eq = '=';
    case Neq = '!=';
    case Lt = '<';
    case Lte = '<=';
    case Gt = '>';
    case Gte = '>=';
    case Like = 'LIKE';
}
