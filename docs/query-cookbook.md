# Query cookbook

How-to companion to the [Query reference](api/query.md). The
reference catalogues the builder, operators, and value objects;
this guide is a recipe book: pagination flows, faceted lookups,
JSON-field predicates, hand-off to full-text search, and the
performance shape behind each pattern.

If you only need a method signature, go read the reference. If
you're building a query and wondering "what's the canonical way",
start here.

> **Prerequisites.** A container built by
> `Imanager\DefaultBootstrap::boot()` (see
> [README §Quickstart](../README.md#quickstart)). You're calling
> `ItemRepository::query()` and `count()`; you know what a
> `Category` and a `Field` are
> ([API > Domain](api/domain.md)).

---

## 1. Anatomy of a query

Every query you run is one of two shapes:

```php
use Imanager\Query\Query;
use Imanager\Storage\ItemRepository;

$items = $repo->query(
    (new Query())
        ->inCategory($blog->id)
        ->where('active', '=', true)
        ->orderBy('position')
        ->limit(20),
);
```

…or the same predicate against `count()`:

```php
$total = $repo->count(
    (new Query())
        ->inCategory($blog->id)
        ->where('active', '=', true),
);
```

`Query` is **immutable**. Every builder method returns a new
instance: share a "base query" across call sites and refine each
independently without worrying about shared state.

```php
$base    = (new Query())->inCategory($blog->id)->where('active', '=', true);
$active  = $base->orderBy('position')->limit(10);     // page 1, 10 rows
$archive = $base->where('archived', '=', true);       // subset of $base
```

### Three rules to internalise

1. **A `Query` without `inCategory()` searches *every* category.**
   Useful for admin-side global searches; dangerous as the default
   for application code. Set it.
2. **Multiple `where()` calls AND together.** There is no OR. Build
   OR-shaped logic with a [`SelectorParser`](#5-selector-strings)
   source string or union the results of two queries
   application-side.
3. **`count()` ignores `limit()` / `offset()`.** The contract is
   "how many rows match this predicate": pagination metadata, not
   page size. Use it for total counts and let the page query carry
   the limit.

---

## 2. Predicate recipes

### 2.1 Structural columns vs JSON fields

There are two kinds of fields you can predicate on:

| Where it lives | Examples | What runs |
|---|---|---|
| Structural column on `items` | `id`, `name`, `label`, `position`, `active`, `created`, `updated`, `categoryId` | Plain SQL on the column. |
| Field declared on the category | `title`, `body`, `tags`, … (anything in `$item->data`) | Hot (`indexed = true`): generated column. Cold: `json_extract(data, ...)`. |

You don't pick which one: the builder picks for you based on the
field name. From the call site, both look the same:

```php
// Structural column
(new Query())->where('active', '=', true)->where('position', '>=', 5);

// JSON-data field
(new Query())->where('title', '=', 'Hello')->where('priority', '>', 3);
```

The cost is different — see [§7 Performance](#7-performance) — but
the API is uniform.

### 2.2 Equality and inequality

```php
->where('active', '=', true)
->where('name', '!=', 'draft')
->where('categoryId', '=', $newsCategoryId)
```

Booleans coerce to SQLite integers (`0` / `1`). Null comparisons go
through `IS NULL` / `IS NOT NULL` semantics automatically.

### 2.3 Ranges

```php
->where('position', '>=', 3)
->where('position', '<',  10)
->where('created', '>=', strtotime('2026-01-01'))
```

Operators accept either the enum case or the literal string:

```php
use Imanager\Query\Operator;
->where('position', Operator::Gte, 3);   // identical
->where('position', '>=', 3);
```

Use the enum form when the operator is dynamic and you want
type-safety; the string form when it's literal and the strings
read more naturally.

### 2.4 `LIKE` patterns

`Operator::Like` uses SQLite's `LIKE` semantics. `%` matches any
sequence, `_` matches a single character. Match is
case-insensitive for ASCII.

```php
use Imanager\Query\Operator;

// Starts-with: can use an index on the column
->where('name', Operator::Like, 'hello%')

// Contains: cannot use an index; full scan
->where('name', Operator::Like, '%world%')

// Ends-with: also a scan
->where('name', Operator::Like, '%post')
```

**Rule of thumb:** trailing-only wildcards (`'foo%'`) are
index-friendly. Leading wildcards (`'%foo'`) require a scan. Once
your `items` table has a few thousand rows, that scan starts to
hurt. For free-text contains-search, prefer the FTS hand-off in
[§6](#6-full-text-search-hand-off).

### 2.5 Combining clauses (AND)

```php
$q = (new Query())
    ->inCategory($blog->id)
    ->where('active', '=', true)
    ->where('tagCount', '>=', 1)
    ->where('name', Operator::Like, 'php-%');
```

The clauses AND together unconditionally. There is no `OR`.

### 2.6 The "OR shaped" problem

Three pragmatic paths, in order of "do this first":

1. **Two queries, application-side union.** If the OR splits along
   a clean axis (e.g. "active" vs "featured"), run two queries and
   merge the result lists. Cheap, readable, no SQL gymnastics.

   ```php
   $active   = $repo->query($base->where('active', '=', true));
   $featured = $repo->query($base->where('featured', '=', true));
   $merged   = self::dedupeById([...$active, ...$featured]);
   ```

2. **Selector strings with `%` wildcards.** If the OR really boils
   down to "matches one of several name patterns", express it as a
   `LIKE` over a contains-wildcard:

   ```php
   (new SelectorParser())->parse('name=%blog%');  // matches "blog", "blogpost", "weblog"
   ```

3. **Hand off to FTS.** If the OR is "matches any of these search
   terms anywhere", that's exactly what FTS5 is for:
   [§6](#6-full-text-search-hand-off).

A fourth path — fetch all and filter in PHP — works for ≤ a few
hundred rows. Don't reach for it for thousands.

### 2.7 Negative matches on JSON arrays

A common modelling choice is to store a list (tags, categories)
inside `data`:

```php
$repo->save(new Item(
    // …
    data: ['tags' => ['php', 'cms']],
));
```

iManager has **no array-membership operator**. To find items whose
tag list contains `php`, use `LIKE` on the JSON-encoded value:

```php
->where('tags', Operator::Like, '%"php"%')
```

It's a contains-wildcard so the cost is a scan: fine for hundreds
of rows, not great beyond. If you query tag membership frequently,
the right answer is a join table (separate category), not a
denormalised JSON list.

---

## 3. Sorting

`orderBy()` calls compose left-to-right. The first call is the
primary order; subsequent calls are tiebreakers:

```php
(new Query())
    ->inCategory($blog->id)
    ->orderBy('position')              // primary
    ->orderBy('id', Direction::Asc);   // tiebreaker
```

### Defaults

If you don't call `orderBy()` at all, the storage layer returns
items by **`position` ASC, then `id` ASC**. That matches what
`findByCategory()` does: adding an explicit `orderBy('position')`
is redundant but harmless.

### Direction

```php
use Imanager\Query\Direction;

->orderBy('created', Direction::Desc)
->orderBy('created', 'desc')           // identical
->orderBy('created', 'DESC')           // case-insensitive, also fine
```

Both forms call into `Direction::coerce()` for you; an unknown
string throws `\ValueError`.

### Reverse-chronological with stable tiebreaker

```php
->orderBy('created', Direction::Desc)
->orderBy('id', Direction::Desc);
```

The `id` tiebreaker matters whenever two items can share a
`created` timestamp, common when seeding from a migration that
stamps a constant `now()`. Without it, the row order between two
ties is undefined and your pagination can skip or duplicate items
across pages.

---

## 4. Pagination

The standard flow is **three queries**: count, page, page-after-
page. `Pagination` is a small view-model that derives offsets and
last-page math from a known total.

```php
use Imanager\Query\{Pagination, Query};

$perPage = 20;
$page    = max(1, (int) ($_GET['page'] ?? 1));

$base  = (new Query())
    ->inCategory($blog->id)
    ->where('active', '=', true);

// 1. Total rows for the predicate (limit-independent).
$total = $repo->count($base);

// 2. The actual page.
$items = $repo->query(
    $base
        ->orderBy('created', Direction::Desc)
        ->orderBy('id',      Direction::Desc)
        ->limit($perPage)
        ->offset(($page - 1) * $perPage),
);

// 3. Metadata for the template.
$pager = new Pagination($page, $perPage, $total);

// In your template:
//   "Showing $items items on page {$pager->page} of {$pager->lastPage()}"
//   if ($pager->hasMore()) renderNextLink();
```

A few details to know:

- **`Pagination` never executes anything.** You computed `$total`
  via `count()`; this object just turns three numbers into
  `lastPage()`, `offset()`, `hasMore()`. The constructor enforces
  `page ≥ 1`, `perPage ≥ 1`, `total ≥ 0`.
- **`lastPage()` returns `1` for an empty result.** Keeps template
  code that calls `for ($i = 1; $i <= $pager->lastPage(); $i++)`
  from infinite-looping or showing "0 of 0".
- **Stable order is required for stable pagination.** If two items
  can tie on the primary sort key, add a tiebreaker (see §3).
  Otherwise items can jump pages between requests when the
  underlying storage returns a different tie-order.
- **Offset pagination is not free.** SQLite still scans the rows
  it skips. For deep pages (> 1000), prefer **keyset pagination**:

  ```php
  // Instead of offset($n * $perPage), remember the last seen sort key:
  $base
      ->where('created', '<', $lastSeenCreated)
      ->orderBy('created', Direction::Desc)
      ->limit($perPage);
  ```

  Works only when the sort column is monotonic (`created`, `id`).
  For a generic UI that lets users jump to arbitrary pages, offset
  pagination is still the right call, just don't use it for
  background workers walking a table.

### Per-page over a maximum

A safety guard for any HTTP-driven pagination: never trust the
client to pick a sensible per-page.

```php
$perPage = max(1, min(100, (int) ($_GET['per_page'] ?? 20)));
```

iManager itself doesn't enforce a ceiling; that's a host concern.

---

## 5. Selector strings

`SelectorParser` is a tiny string DSL for predicates. It exists so
you can store filters declaratively — in a config file, a URL
parameter, an editor preset — without exposing PHP construction
syntax.

```php
use Imanager\Query\SelectorParser;

$parser = new SelectorParser();
$query  = $parser->parse('active=1, position>=10');
// equivalent to:
// (new Query())
//   ->where('active', '=', 1)
//   ->where('position', '>=', 10);
```

### Grammar in one paragraph

A selector is one or more **clauses** separated by `,`. Each
clause is **identifier op value**. Operators are `=`, `!=`, `>`,
`<`, `>=`, `<=`. The right-hand side is a bare value (no quoting,
leading/trailing whitespace stripped). A `%` inside an `=` value
upgrades the clause to `LIKE`:

| Selector | Equivalent `Query::where(…)` |
|---|---|
| `name=Hello` | `where('name', '=', 'Hello')` |
| `name=Hello%` | `where('name', Operator::Like, 'Hello%')` |
| `name=%Hello%` | `where('name', Operator::Like, '%Hello%')` |
| `position>=5, active=1` | `->where('position', '>=', 5)->where('active', '=', 1)` |

### What the parser does NOT handle

By design:

- **`inCategory()`, `orderBy()`, `limit()`, `offset()`**: those
  live in code, not in the selector string. Selectors describe
  predicates; the rest of the query you build around them:

  ```php
  $query = (new SelectorParser())->parse($source)
      ->inCategory($blog->id)
      ->orderBy('created', 'desc')
      ->limit(50);
  ```
- **OR.** Multiple clauses AND together. If your selector needs OR,
  use one of the patterns in [§2.6](#26-the-or-shaped-problem).
- **Quoting / escaping.** A literal `,` or `=` inside a value will
  break parsing. If your right-hand side is user-controlled and may
  contain those characters, don't take the SelectorParser path.
  Build the `Query` programmatically and feed values as parameters.
- **Functions / casts.** No `LOWER(name)`, no `JSON(tags) LIKE …`.
  Keep it small.

### When to use it vs build manually

Use **`SelectorParser`** when:
- The predicate is stored as data (config file, settings table,
  URL parameter) and authored by a human.
- The shape of the predicate is genuinely declarative: "filter by
  these N criteria, AND".

Build a **`Query`** manually when:
- Values come from untrusted sources (URLs you don't fully
  control, raw form input). Parameters bypass the selector regex.
- You need `OR`, `IN`, subqueries, or anything beyond the grammar.
- The predicate is part of *your* code's logic, not data.

### Errors

Malformed selectors throw `Imanager\Exception\InvalidSelectorException`:

```php
try {
    $query = $parser->parse($source);
} catch (\Imanager\Exception\InvalidSelectorException $e) {
    // surface to the user / fall back to a default
}
```

Examples that throw:
- `'name'`: missing operator + value
- `'name='`: empty right-hand side
- `'1foo=bar'`: identifier must start with a letter or underscore

---

## 6. Full-text search hand-off

Once your "contains-search" use case grows past a few thousand
items, switch from `Operator::Like` with a `%…%` wildcard to
`FullTextSearch`. It's a SQLite FTS5 index kept in sync with
`items` automatically, every save / delete updates it inside the
same transaction.

### Indexing

Items are indexed by `name`, `label`, and a flattened version of
`data`. Field-level inclusion in the index is governed by
`Field::$searchable`: set it to `true` on the fields whose
content should be matched.

```php
$fields->save(new Field(
    // …
    name: 'body',
    type: FieldType::LongText,
    searchable: true,
));
```

The index is built incrementally on item save. To rebuild from
scratch (after changing tokenizer settings or making a bulk import):

```bash
vendor/bin/imanager fts:rebuild
```

### Querying

```php
use Imanager\Search\FullTextSearch;

$fts = $container->get(FullTextSearch::class);

foreach ($fts->search('sqlite AND fts*', categoryId: $blog->id, limit: 10) as $hit) {
    // $hit->itemId
    // $hit->categoryId
    // $hit->snippet:    HTML snippet with <b>…</b> around matches
    // $hit->rank:       float; smaller is better (FTS5 convention)
}

$total = $fts->count('sqlite AND fts*', categoryId: $blog->id);
```

The query string is whatever FTS5 accepts:

- Bare words: `sqlite fts5` (implicit AND).
- Boolean: `sqlite AND fts5`, `sqlite OR sqlite3`, `sqlite NOT mysql`.
- Phrases: `"full text search"`.
- Prefix: `sqlit*` (matches `sqlite`, `sqlites`, …).

See [SQLite FTS5 docs](https://sqlite.org/fts5.html#the_match_operator)
for the full grammar.

### Hand-off pattern (FTS + Query)

A common shape is **FTS to find, Query to enrich**: search by text,
then re-query the matching ids with the regular `Query` builder
so you can filter by structural fields (active, date range, etc.)
or apply your standard ordering:

```php
$hits = $fts->search($userInput, categoryId: $blog->id, limit: 50);
if ($hits === []) {
    return [];
}

$ids = array_map(static fn($h) => $h->itemId, $hits);

// There's no `IN (…)` operator on Query. Fall back to a per-id
// fetch loop (cheap; SQLite is local) or a hand-rolled SQL JOIN
// if you have thousands of hits.
$items = [];
foreach ($ids as $id) {
    $item = $repo->find($id);
    if ($item !== null && $item->active) {
        $items[] = $item;
    }
}
```

For thousands of hits, drop into `\PDO` directly: `FullTextSearch`
already does that for the index lookup, and the query layer is
intentionally not the right place to bolt on bulk-id fetching.

### When FTS is wrong

- Exact lookup by id, slug, structural column → use `find()` /
  `findBySlug()` / `Query`.
- Range queries (`position >= 3`) → `Query`.
- Sorting by anything other than relevance → `Query`. FTS results
  come out by `rank` (descending relevance).
- Boolean attributes (`active = true`) → `Query`. FTS doesn't
  predicate on the index, it predicates on text content.

The hand-off pattern above exists exactly so you can combine the
two: FTS for text-shaped predicates, `Query` for everything else.

---

## 7. Performance

### Hot vs cold fields

A field with `indexed = true` becomes a SQLite generated column
(see [Storage > Hot fields](api/storage.md#hot-fields--generated-columns)).
The query layer picks that column automatically when you predicate
on the field; cold fields fall back to `json_extract(data, '$.x')`
per row.

Translation:

- **Predicate on a hot field** = index-eligible, fast.
- **Predicate on a cold field** = full-table scan + JSON parse per
  row. Fine for hundreds of items, painful for tens of thousands.

When in doubt, look at the actual SQL. SQLite has `EXPLAIN QUERY
PLAN` and it reads in plain English:

```bash
sqlite3 data/imanager.db "EXPLAIN QUERY PLAN
  SELECT * FROM items WHERE gen_7_active = 1;"
```

(`gen_7_active` is the generated column for an indexed `active`
field in category id 7; see
[Storage > Hot fields](api/storage.md#hot-fields--generated-columns)
for the naming.) If you see `SCAN items` for a query you call often,
the field behind that predicate wants to be hot.

### Wildcard cost

| Pattern | Cost |
|---|---|
| `'Hello%'` | Index-eligible on hot fields. |
| `'%Hello'`, `'%Hello%'` | Full scan, always. |
| `'_ello'` (single-char wildcard at the start) | Full scan. |

For leading-wildcard contains-search at scale, the answer is FTS,
not a cleverer `LIKE`.

### `count()` + `query()` is two queries

The `Query` builder does not cache anything: calling `count()` and
then `query()` against the same predicate runs the SQL twice. If
you call both inside one request, cache the count yourself:

```php
$base  = (new Query())->inCategory($blog->id)->where('active', '=', true);
$total = $repo->count($base);
$items = $repo->query($base->limit($perPage)->offset(($page - 1) * $perPage));
```

The two queries are still cheap on a local SQLite — milliseconds
combined — but it's worth knowing the shape.

### Pagination depth

Offset pagination scales O(offset). At `offset = 10_000` SQLite
walks 10 000 rows it ultimately discards. The right fix at depth
is **keyset pagination** (§4): drop `offset()` and predicate on
the last-seen sort key.

### Indexes you can add yourself

iManager's bundled schema indexes the common structural columns
(`position`, `category_id`, `created`, …). If you find yourself
repeatedly querying on a combination — say `(active, position)` —
add a covering index via a migration:

```sql
CREATE INDEX idx_items_active_position
ON items (active, position)
WHERE active = 1;
```

A partial index (`WHERE active = 1`) is often the right call:
it's smaller and hot more of the time. Add it as a numbered
migration under your install's `config/schema/` directory
following the existing
[`0001`–`0005`](api/storage.md#schemamanager) format.

---

## 8. Common pitfalls

### 8.1 Forgetting `inCategory()`

```php
// Probably not what you wanted, scans every category in the install.
$repo->query((new Query())->where('active', '=', true));
```

Always set the category scope explicitly in application code; the
unfiltered form is an admin-search escape hatch, not a default.

### 8.2 Treating `count()` as paginated

```php
$total = $repo->count((new Query())->inCategory($id)->limit(20));
//                                                   ^^^^^^^^^^
// Ignored. $total is the full match set, not the page size.
```

`count()` always returns the total for the predicate. That's the
contract: `Pagination` is the layer that decides what "page 1"
means.

### 8.3 Predicating on the wrong field name

The field name passed to `where()` is matched first against
**structural columns**, then against **declared fields** in the
category's schema. A typo doesn't error: it predicates on a
missing JSON key, which always matches zero rows.

```php
// Typo "actice" → JSON key that doesn't exist → empty result, no error.
->where('actice', '=', true)
```

If a query suddenly returns zero rows after a refactor, check the
field names first.

### 8.4 Stale FTS index after a bulk import

Bulk-inserting items via the migration tool keeps the FTS index in
sync (the importer does it in-transaction). Bulk-inserting via raw
SQL does **not**. You'll need `vendor/bin/imanager fts:rebuild`
afterwards. If a search "can't find" an item that obviously exists,
check FTS sync before chasing tokenizer settings.

### 8.5 Mutating a `Query` reference

```php
$base = (new Query())->inCategory($blog->id);
$base->where('active', '=', true);    // <-- nothing happens to $base
$repo->query($base);                  // still un-filtered
```

Every builder method returns a **new** `Query`. The old reference
is unchanged. Assign the result:

```php
$base = $base->where('active', '=', true);
```

This is the single most common bug among people coming from
mutable builders elsewhere.

---

## 9. Where to look in the source

- `src/Query/Query.php`, the builder.
- `src/Query/Clause.php`, `Operator.php`, `OrderBy.php`,
  `Direction.php`, `Pagination.php`, the value objects.
- `src/Query/SelectorParser.php`, the selector grammar.
- `src/Search/FullTextSearch.php`, FTS search + count + rebuild.
- `tests/Unit/Storage/ItemQueryContract.php`, every recipe in
  this guide is asserted there (different surface, same shape);
  it's the canonical "does this query actually do what I think it
  does?" answer.

The reference page at [API > Query](api/query.md) is the matching
index of signatures.
