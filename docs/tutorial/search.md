# Full-text search

You'll build a small knowledge base — `Article` items with title,
body, and tags — then run searches against it. Along the way
you'll learn the FTS5 query language iManager exposes, how
`SearchHit` is shaped (it has one surprise — `rank` is negative,
lower is better), how to combine full-text matches with structural
predicates from `Query`, and what the `rebuild()` operation is for.

The architecture deep-dive lives in
[schema.md → "`searchable: true` — what really happens"](schema.md#searchable-true--what-really-happens);
this chapter is the recipe side.

## Setup

```php
require __DIR__ . '/vendor/autoload.php';

use Imanager\DefaultBootstrap;
use Imanager\Domain\Category;
use Imanager\Domain\Field;
use Imanager\Domain\Item;
use Imanager\Search\FullTextSearch;
use Imanager\Storage\CategoryRepository;
use Imanager\Storage\FieldRepository;
use Imanager\Storage\ItemRepository;

$container = DefaultBootstrap::boot(
    databasePath: __DIR__ . '/data/kb.db',
    uploadsPath:  __DIR__ . '/data/uploads',
    uploadsUrl:   '/uploads',
    cachePath:    __DIR__ . '/data/cache',
);

$categories = $container->get(CategoryRepository::class);
$fields     = $container->get(FieldRepository::class);
$items      = $container->get(ItemRepository::class);
$fts        = $container->get(FullTextSearch::class);

$article = $categories->ensure(new Category(null, 'Article', 'article'));
$fields->ensure(Field::text($article->id, 'title', 'Title')->required()->maxLength(200));
$fields->ensure(Field::longText($article->id, 'body', 'Body')->required());
$fields->ensure(Field::text($article->id, 'tags', 'Tags')->maxLength(200));
```

Seed three articles so the searches below return something:

```php
$items->save(new Item(null, $article->id, 'sqlite-fts5', 'SQLite FTS5 intro',
    data: ['title' => 'SQLite FTS5 intro',
           'body'  => 'FTS5 is an SQLite extension for full-text search. '
                    . 'It tokenizes content into words and ranks results by relevance.',
           'tags'  => 'sqlite database search']));

$items->save(new Item(null, $article->id, 'imanager-storage', 'iManager storage layer',
    data: ['title' => 'iManager storage layer',
           'body'  => 'Items are stored as JSON in a single data column. '
                    . 'Indexed fields get a generated column for fast filters; '
                    . 'searchable fields feed the FTS5 index.',
           'tags'  => 'imanager sqlite storage']));

$items->save(new Item(null, $article->id, 'fast-search', 'Why search is fast',
    data: ['title' => 'Why search is fast',
           'body'  => 'An inverted index maps each word to the rows that contain it. '
                    . 'Search asks "which rows have this word?" instead of '
                    . '"does this row have this word?". Sub-millisecond on millions of rows.',
           'tags'  => 'algorithms performance']));
```

## The first search

```php
$hits = $fts->search('fts5');

foreach ($hits as $hit) {
    echo "#{$hit->itemId}: {$hit->snippet}\n";
}
```

Output:

```
#1: SQLite <b>FTS5</b> intro SQLite FTS5 intro <b>FTS5</b> is an SQLite extension for…
#2: iManager storage layer iManager storage layer Items are…feed the <b>FTS5</b> index.
```

Two articles match. Notice the **snippet contains `<b>…</b>`
markers** around matched terms — render it raw in your template, do
**not** re-escape, or the highlighting disappears. SQLite escapes
the matched-term content before wrapping it in bold tags, so the
snippet is safe to drop directly into HTML.

## What `SearchHit` carries

```php
final readonly class SearchHit
{
    public int    $itemId;     // the item.id that matched
    public int    $categoryId; // the item.category_id, for filtering on the consumer side
    public string $snippet;    // HTML with <b>…</b> highlighting; render raw
    public float  $rank;       // FTS5 relevance — negative; closer to zero = more relevant
}
```

The `rank` shape is the one thing that catches everyone the first
time: **lower is more relevant**. FTS5 uses a negative-number
scoring scheme (BM25-flavored); the most relevant hit might be
`-3.5`, the least relevant `-0.4`. `search()` already orders by
rank ascending, so you don't normally touch it; it's exposed mainly
so you can debug "why is X ranking above Y?".

## The query language

iManager passes your query string straight to FTS5. The grammar:

### Implicit AND between bare tokens

```php
$fts->search('sqlite fts5');
// matches rows containing BOTH "sqlite" AND "fts5"
```

Two bare words are AND'd, not OR'd. Same as Google.

### Explicit boolean operators

```php
$fts->search('sqlite OR mysql');
$fts->search('fts5 NOT mysql');
$fts->search('sqlite AND (fts5 OR fts4)');
```

`AND`, `OR`, `NOT` are case-sensitive in FTS5 — lowercase `or`
becomes a literal search for the word "or". Parentheses for
grouping work as expected.

### Phrase search

```php
$fts->search('"full-text search"');
```

Quoted strings match the exact sequence — `"full-text search"`
won't match `"full search text"`. Use for multi-word terms like
proper nouns ("Quick Sort", "Donald Knuth") or technical phrases.

### Prefix match

```php
$fts->search('index*');
// matches "index", "indexed", "indexing", "indices"
// (in our seed data: "indexed" in article #2, "index" in article #3)
```

The `*` suffix expands at search time — useful for autocomplete or
when you don't know the exact stem. Note the asterisk only works
as a **suffix**: `*index` is a parse error in FTS5. There's no
infix wildcard.

### Column-restricted search

The `items_fts` table has three columns: `name`, `label`, `body`.
Limit a match to one column with the `column:` prefix:

```php
$fts->search('name: sqlite-fts5');   // restrict to item.name (slug-shaped)
$fts->search('label: storage');       // restrict to item.label (human-readable title)
$fts->search('body: tokenize');       // restrict to body (flattened field values)
```

Useful when a query term is ambiguous between a slug-like
identifier and prose ("did the user mean an item whose `name` is
`storage` or one whose body *contains* the word storage?").

### Combining

```php
$fts->search('"full-text" AND (storage OR layer) NOT mysql');
```

Production code typically pre-processes the user's raw input
before handing it to FTS5 — strip unbalanced quotes, escape
operator words the user didn't mean as operators, fail soft if
the resulting expression is malformed. The simplest hardening:
quote the whole query so it becomes a single phrase:

```php
$safe = '"' . str_replace('"', '""', $userInput) . '"';
$hits = $fts->search($safe);
```

That's lossy (no operators, no prefix matching) but bullet-proof
against parser errors.

## Scoping by category

A single search call can be limited to one category — useful when
your install has multiple categories (Articles, Products, Pages)
but the user is searching from inside one of them:

```php
$hits = $fts->search('storage', categoryId: $article->id);
```

Without the scope, the same query searches across **every**
category in the install. The category id ends up as `AND
i.category_id = :cid` in the underlying SQL — it's a cheap filter,
not a separate index, but FTS5's intersection logic handles it
efficiently.

## Pagination

`search()` accepts `limit` (default 20) and `offset` (default 0):

```php
$page = 3;
$perPage = 20;
$hits = $fts->search(
    $query,
    categoryId: $article->id,
    limit:      $perPage,
    offset:     ($page - 1) * $perPage,
);
```

The matching `count()` method returns the total across the whole
result set — for the "Showing 41-60 of 312" header:

```php
$total = $fts->count($query, categoryId: $article->id);
$shown = sprintf(
    'Showing %d–%d of %d results',
    ($page - 1) * $perPage + 1,
    min($page * $perPage, $total),
    $total,
);
```

`count()` ignores the `limit` / `offset` on the corresponding
`search()` call — it always counts the full match set. So you do
two queries per pagination render: one `search()` for the slice
and one `count()` for the total.

## Combining FTS hits with structural predicates

This is where iManager's FTS surface is honestly thin today.
`search()` returns just the matching `itemId`s + snippets — it
doesn't take a `Query` object, and `Query` doesn't take a "matches
FTS query X" predicate. So if you want "all articles matching
'scriptor' that are also `active = true`", you compose manually:

```php
use Imanager\Query\Query;
use Imanager\Query\Operator;

$hits = $fts->search('scriptor', categoryId: $article->id, limit: 100);
$matchedIds = array_map(static fn($hit) => $hit->itemId, $hits);

// Now filter to only the active ones — by re-fetching and checking,
// since Query has no in-ids predicate today:
$active = [];
foreach ($matchedIds as $id) {
    $item = $items->find($id);
    if ($item !== null && $item->active) {
        $active[] = $item;
    }
}
```

That's O(n) lookups, fine for the typical "first page of search
results" but wasteful at scale. Two cleaner alternatives:

**For high-volume reads**: drop to raw SQL. The FTS table is just
`items_fts`; you JOIN with `items` and add whatever WHERE clause
you need:

```php
$pdo = $container->get(PDO::class);
$sql = 'SELECT items_fts.rowid, snippet(items_fts, -1, \'<b>\', \'</b>\', \'…\', 16) AS snippet
        FROM items_fts
        JOIN items i ON i.id = items_fts.rowid
        WHERE items_fts MATCH :q
          AND i.category_id = :cid
          AND i.active = 1
          AND i.gen_4_published_at < :now
        ORDER BY rank';
```

(Where `gen_4_published_at` is the generated column for an indexed
`published_at` field in category id 4 — see
[schema.md](schema.md#indexed-true--what-really-happens) for how
those names get formed.)

**For "FTS over a filtered subset"**: invert the order — get the
filtered ids from `Query` first, then post-filter the FTS hits:

```php
$candidates = $items->query(
    Query::for($article->id)
        ->where('published_at', Operator::Lt, time())
);
$candidateIds = array_flip(array_map(static fn($i) => $i->id, $candidates));

$hits = array_filter(
    $fts->search('scriptor', categoryId: $article->id, limit: 1000),
    static fn($hit) => isset($candidateIds[$hit->itemId]),
);
```

Neither pattern is elegant — a future iManager release may add
`Query::matchesFts(string $query)` to bridge the gap. Until then,
the right choice depends on which side has fewer rows: FTS-first if
the search terms are selective, predicate-first if the WHERE clause
is.

## Rendering the snippet

```php
foreach ($hits as $hit) {
    $item = $items->find($hit->itemId);
    if ($item === null) continue;
    ?>
    <li>
        <a href="/articles/<?= htmlspecialchars($item->name) ?>">
            <?= htmlspecialchars($item->label) ?>
        </a>
        <p class="snippet"><?= $hit->snippet ?></p>
    </li>
    <?php
}
```

The two things to remember:

- `$item->name` and `$item->label` come from your data — escape with
  `htmlspecialchars`.
- `$hit->snippet` is already HTML — render raw. SQLite escapes the
  matched content before wrapping `<b>` tags around it, so this is
  safe.

If you want to swap the highlighting tag (e.g., `<mark>` instead
of `<b>`), you can't — `FullTextSearch::search()` hard-codes
`<b>…</b>` in the SQL. The two clean workarounds: a `str_replace`
in your template layer, or a CSS rule (`p.snippet b { background:
yellow; font-weight: normal; }`).

## Rebuilding the index

The FTS index is normally kept in sync transparently — every item
save writes one row to `items_fts`, every delete removes one. But
some operations break the invariant and need a manual rebuild:

- Bulk-importing items via raw SQL (bypasses
  `SqliteItemRepository::syncFts()`).
- Changing the tokenizer settings in `0002_fts.sql` and shipping
  a new schema version.
- Recovering from corruption (SQLite FTS5 indexes can wedge after
  certain crash patterns).

The CLI:

```bash
vendor/bin/imanager fts:rebuild
```

Or programmatically:

```php
$fts->rebuild();
```

What it does: `DELETE FROM items_fts` followed by `INSERT INTO
items_fts SELECT … FROM items`. On 10k items it takes seconds; on
100k items, low tens of seconds; on millions, plan a maintenance
window.

### A subtle inconsistency to know about

The live `syncFts()` (called on every save) and `rebuild()` produce
*slightly* different FTS bodies:

- **`syncFts()`** writes `name + label + flattenForSearch(data)`,
  where `flattenForSearch` walks the `data` array and includes
  string/numeric values only — JSON keys do NOT appear in the
  body.
- **`rebuild()`** writes `name + label + raw JSON of data`, which
  means JSON keys (`"title"`, `"body"`) ARE present in the
  index — but the FTS5 unicode61 tokenizer treats quotes, braces,
  and colons as separators, so functionally the difference is
  small (key names become extra tokens).

You'll only notice this if you search for a literal JSON key name
(`"body"` matching everything because every item's rebuilt body
contains the JSON key) — uncommon but worth knowing for debugging.
A future release may make `syncFts` + `rebuild` produce identical
bodies; for now they're close-enough-but-not-identical.

## The `searchable: true` flag is still aspirational

Briefly recapped from [schema.md](schema.md#searchable-true--what-really-happens):
today the FTS sync indexes **every** text value from `Item::$data`
regardless of which fields you flagged `->searchable()`. Setting
the flag correctly is still worth it — captures intent and the
future-stricter behavior won't surprise you — but the practical
effect today is that any text field is findable.

For an opt-out-now hack, the only path is to **not** put the
content in `data` at all (e.g., store it in a sibling table you
manage yourself). Most apps don't care enough about the leak to
bother.

## Performance

The orders of magnitude (illustrative):

| Operation | Index size | Latency |
|---|---:|---:|
| `search('scriptor')` | 1k items | < 1 ms |
| `search('scriptor')` | 10k items | ~ 1 ms |
| `search('scriptor')` | 100k items | ~ 2 ms |
| `count('scriptor')` | any | similar to `search()` — same MATCH |
| `rebuild()` | 1k items | < 200 ms |
| `rebuild()` | 100k items | low tens of seconds |

FTS5's inverted-index latency stays near-constant as the dataset
grows — that's the whole point of the index. The expensive
operation is `rebuild()`, which is O(n) over all items by design;
you only run it manually after bulk imports or schema migrations.

For a real measurement, Scriptor's `bin/perf-smoke.php`
(sibling-project tool) includes `FullTextSearch::search()` as one
of its four canonical timing checkpoints — the typical install
comes in well under the budget of 100 ms.

## What just happened, in one paragraph

You learned how `FullTextSearch::search()` works end-to-end: the
two-method surface (`search()` + `count()`, plus the occasional
`rebuild()`), the `SearchHit` value object with its negative-rank
quirk, the FTS5 query language iManager exposes verbatim
(implicit-AND tokens, phrases, prefix `*`, boolean operators,
column-restricted matches with `name:` / `label:` / `body:`),
how to scope a search to one category and paginate it with
`limit`/`offset` + a parallel `count()` for the total, why
combining FTS hits with `Query` predicates is honestly clunky
today (no `Query::matchesFts()` bridge — drop to raw SQL or
post-filter manually), and the rebuild operation with its CLI hook
and the subtle live-sync-vs-rebuild content difference. You also
saw two snippet-safety reminders: `$hit->snippet` is pre-escaped
HTML, render raw; `$item->name` / `$item->label` are user data,
escape them.

## Reference

- [`src/Search/FullTextSearch.php`](../../src/Search/FullTextSearch.php)
  — the full two-method API + `rebuild()`.
- [`src/Search/SearchHit.php`](../../src/Search/SearchHit.php) —
  the result value object with its negative-rank docstring.
- [`config/schema/0002_fts.sql`](../../config/schema/0002_fts.sql)
  — the `items_fts` table declaration with the tokenizer choice
  (`unicode61 remove_diacritics 2`).
- [`docs/query-cookbook.md`](../query-cookbook.md) — predicate
  recipes for the `Query` builder; useful for the structural-half
  of any "FTS + WHERE" search you build.
- [SQLite FTS5 reference](https://sqlite.org/fts5.html) — the
  full FTS5 grammar, ranking model, and tokenizer options.
- [schema.md → "`searchable: true`"](schema.md#searchable-true--what-really-happens)
  — the architecture deep-dive on what FTS5 actually is and how
  iManager wires it.
