<?php

declare(strict_types=1);

namespace Imanager\Query;

use Imanager\Exception\InvalidSelectorException;

/**
 * Parses iManager's string DSL into a {@see Query}.
 *
 * Grammar (informal):
 *
 *   selector := clause (',' clause)*
 *   clause   := identifier op value
 *   identifier := [A-Za-z_][A-Za-z0-9_]*
 *   op       := '>=' | '<=' | '!=' | '>' | '<' | '='
 *   value    := any non-empty string (a literal `%` switches '=' to LIKE)
 *
 * Multiple clauses combine with AND. Wildcard tokens follow SQL LIKE
 * semantics: `name=foo` is exact match, `name=foo%` is starts-with,
 * `name=%foo` is ends-with, `name=%foo%` is contains.
 */
final readonly class SelectorParser
{
    private const CLAUSE_PATTERN = '/^([A-Za-z_][A-Za-z0-9_]*)\s*(>=|<=|!=|>|<|=)\s*(.+)$/';

    public function parse(string $selector): Query
    {
        $query = new Query();
        $trimmed = trim($selector);
        if ($trimmed === '') {
            return $query;
        }

        foreach (explode(',', $trimmed) as $rawClause) {
            $clause = trim($rawClause);
            if ($clause === '') {
                continue;
            }

            if (preg_match(self::CLAUSE_PATTERN, $clause, $m) !== 1) {
                throw new InvalidSelectorException(\sprintf(
                    'Cannot parse selector clause "%s"',
                    $clause,
                ));
            }

            $field = $m[1];
            $opStr = $m[2];
            $value = trim($m[3]);

            if ($value === '') {
                throw new InvalidSelectorException(\sprintf(
                    'Selector clause "%s" has an empty right-hand side',
                    $clause,
                ));
            }

            // `=` upgrades to LIKE when the value carries a `%` wildcard;
            // anything else keeps the operator literal.
            if ($opStr === '=' && str_contains($value, '%')) {
                $query = $query->where($field, Operator::Like, $value);
                continue;
            }

            $query = $query->where($field, Operator::from($opStr), $value);
        }

        return $query;
    }
}
