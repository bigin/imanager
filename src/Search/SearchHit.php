<?php

declare(strict_types=1);

namespace Imanager\Search;

/**
 * One row of a {@see FullTextSearch::search()} result.
 *
 * `snippet` is HTML that already contains `<b>...</b>` markers around the
 * matched terms — render it raw, do *not* re-escape, or the highlighting
 * disappears. The matched-term tokens themselves are entity-escaped by
 * SQLite before the bold tags are wrapped around them, so the snippet is
 * safe to drop into a template.
 *
 * `rank` is FTS5's relevance score. **Lower is better** (it's a negative
 * number for matches, with the most relevant hit closest to zero on
 * the negative side). It's exposed mainly so callers can debug result
 * ordering; ordinary use-cases just iterate the list as-is.
 */
final readonly class SearchHit
{
    public function __construct(
        public int $itemId,
        public int $categoryId,
        public string $snippet,
        public float $rank,
    ) {}
}
