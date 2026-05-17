# Design a content schema

You'll build the storage for a small blog — a `Post` category with
title, slug, body, publish date, and cover image — and learn three
things along the way: which `FieldType` fits which kind of data,
when `indexed` and `searchable` actually do something, and how to
write a schema-setup script you can re-run safely.

## What a schema is in iManager

A *schema* is `Category` + the `Field`s attached to it. There's no
DDL to write; you build it by calling the `CategoryRepository` and
`FieldRepository`. The SQLite tables (`categories`, `fields`,
`items`) are fixed — what you're declaring is *how the JSON payload
on each item gets typed and indexed*.

That means:

- Adding a field later doesn't run an `ALTER TABLE`. It just appends
  a row to `fields` and (if the field is `indexed`) creates one
  generated column.
- You can keep two installs schema-compatible just by re-running
  the same setup script — no migrations file alongside the code.
- The schema lives in your application, not in a separate
  migrations folder. That makes it easy to keep schema setup next
  to the code that uses it.

## Picking a `FieldType`

iManager ships 16 built-in field types. The cheat sheet:

| You need to store … | Use |
|---|---|
| A short single-line string (title, name) | `FieldType::Text` |
| Multi-line plain text (notes, body) | `FieldType::LongText` |
| Multi-line rich text (Markdown / WYSIWYG editor) | `FieldType::Editor` |
| URL-safe identifier (auto-derivable from a title) | `FieldType::Slug` |
| Hashed password | `FieldType::Password` |
| Whole number (count, year, position) | `FieldType::Integer` |
| Currency amount (price, total) | `FieldType::Money` |
| Floating-point (rating, weight) | `FieldType::Decimal` |
| Yes / no flag | `FieldType::Checkbox` |
| Pick one option from a fixed list | `FieldType::Dropdown` |
| Date or date-and-time | `FieldType::Datepicker` |
| Hidden, machine-only state (UUID, ref) | `FieldType::Hidden` |
| Repeated values (tags, list of strings) | `FieldType::ArrayList` |
| Single uploaded file (PDF, ZIP, …) | `FieldType::Fileupload` |
| Single uploaded image (with thumbnails) | `FieldType::Imageupload` |
| Pick from already-uploaded files | `FieldType::Filepicker` |

The picker isn't binding — `Editor` is just `LongText` with an
editor `render()`, and `Money` is just `Decimal` with currency
formatting. If none of the 16 fits, you write a
[custom plugin](../field-types.md#writing-a-custom-field-type) and
register it under your own name. Each plugin's config keys and
storage shape are listed in [`docs/api/field-types.md`](../api/field-types.md).

## Two flags that change how a field behaves

`indexed` and `searchable` are the most important architectural
choices you make when defining a field. They're the difference
between "iManager stores it" and "iManager can *find* it
efficiently." Set them wrong and your app feels fine on the demo
dataset but melts on real data; set them right and 50k items
behave like 50.

To explain what they really do — and what they cost — you have to
look at how iManager stores items in the first place.

### Why iManager stores field values as JSON

Every item lives as one row in the `items` table. The categorical
columns (`id`, `category_id`, `name`, `label`, `position`,
`active`, `created`, `updated`) get their own SQL columns. But
everything *the user defined as a field* — `title`, `body`,
`published_at`, `cover_image`, all of it — goes into a single
JSON-typed `data` column:

```sql
CREATE TABLE items (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    category_id INTEGER NOT NULL REFERENCES categories(id) ON DELETE CASCADE,
    name        TEXT,
    label       TEXT,
    position    INTEGER NOT NULL DEFAULT 0,
    active      INTEGER NOT NULL DEFAULT 1,
    data        TEXT    NOT NULL DEFAULT '{}' CHECK(json_valid(data)),
    created     INTEGER NOT NULL,
    updated     INTEGER NOT NULL
);
```

A real row's `data` column might hold:

```json
{"title": "Hello world", "body": "First post.", "published_at": 1700000000}
```

**Why JSON, not one column per field?**

So that adding a field doesn't require an `ALTER TABLE`. iManager
schemas are user-defined — a host application might add `subtitle`
to its `Post` category on Monday and `featured` on Tuesday. With
JSON storage, each new field is a new key on `data`; the schema
itself stays a single column. Old items work unchanged (the key is
just absent), the new field is present on the next save, no
migration script needed.

The cost lives on the read side. SQLite *can* extract a JSON key:

```sql
SELECT id FROM items
WHERE json_extract(data, '$.published_at') < 1700000000;
```

…but `json_extract` is **not indexable**. Every matching row is
re-parsed from JSON, every time, for every row in the table. On
100 rows you don't notice; on 100k rows it's a 50–100ms table scan
on every filter. That's where the two flags come in.

### `indexed: true` — what really happens

When you mark a field `indexed`, iManager runs two extra `ALTER`
statements behind your back. For a field named `published_at` in
category `7`, you get:

```sql
ALTER TABLE items
ADD COLUMN gen_7_published_at INTEGER
GENERATED ALWAYS AS (json_extract(data, '$.published_at')) VIRTUAL;

CREATE INDEX idx_items_7_published_at
ON items(category_id, gen_7_published_at);
```

Two things to notice:

- **The generated column** (`gen_7_published_at`) is *virtual* —
  SQLite doesn't store its value, it recomputes
  `json_extract(data, '$.published_at')` on every read. So the
  storage cost is zero rows wider; it's purely a *naming* of the
  expression.
- **The index** is on `(category_id, gen_7_published_at)`. So
  filters scoped to one category (the common case — virtually
  every query starts with `Query::for($postId)`) hit it directly.

Now the same query becomes:

```sql
SELECT id FROM items
WHERE category_id = 7 AND gen_7_published_at < 1700000000;
```

…and SQLite uses the B-tree index. A B-tree lookup against a
sorted index is `O(log n)` — at 50k rows, you touch maybe 17
nodes instead of all 50,000. Latency drops from ~50ms to a
fraction of a millisecond.

What `indexed` costs:

- **Save throughput**: every insert/update touches one more index.
  For typical CMS write rates (a few writes per minute) this is
  invisible; for high-frequency writes (hundreds per second) it
  shows up.
- **Storage**: roughly one index entry per row, per indexed field
  — call it ~30 bytes/row for a small key. Negligible at any
  reasonable scale.
- **Schema cost at field creation**: the `ALTER TABLE` runs once,
  during `$fields->save()`. SQLite handles it in milliseconds.

Use `indexed` for fields you **filter on**, **sort by**, or **join
through**. Skip it for display-only fields.

### `searchable: true` — what really happens

SQLite has a second indexing system called FTS5 (Full-Text Search,
version 5). It's a different beast from B-tree indexes:

- **B-tree**: ordered storage of one value per row. Great for
  `=`, `<`, `>`, sort. Useless for `"contains the word 'scriptor'
  anywhere in the article body"`.
- **FTS5**: an *inverted* index. Tokenizes text into words and
  stores `word → [item ids that contain it]`. Great for word and
  phrase search, prefix-match (`scripto*`), ranking by relevance,
  and snippet extraction. Each cost a couple of microseconds to
  evaluate even against millions of items.

iManager creates the FTS5 virtual table once, in the
`0002_fts.sql` migration:

```sql
CREATE VIRTUAL TABLE items_fts USING fts5(
    name,
    label,
    body,
    tokenize = 'unicode61 remove_diacritics 2'
);
```

Note the tokenizer: `unicode61 remove_diacritics 2` means a search
for `"naive"` will match content containing `"naïve"`. Important
for any non-English content; a sane default for international
CMSes.

On every item save, `SqliteItemRepository::syncFts()` writes one
row into `items_fts` keyed by the item's `id`. The search call:

```php
$fts = $container->get(FullTextSearch::class);
$hits = $fts->search('quick brown fox');
foreach ($hits as $hit) {
    echo "#{$hit->itemId}: {$hit->snippet}\n";   // snippet has <b>…</b> around matches
}
```

…runs `SELECT … FROM items_fts WHERE items_fts MATCH :q ORDER BY
rank` internally — sub-millisecond on tens of thousands of items.

**Smart factory defaults (2.2.0+).** Prose-typed factories pick
sensible defaults so the common case needs no setter call:

```php
$fields->ensure(Field::text($post->id, 'title'));      // searchable: true
$fields->ensure(Field::longText($post->id, 'body'));   // searchable: true
$fields->ensure(Field::password($user->id, 'pw'));     // searchable: false
$fields->ensure(Field::image($post->id, 'cover'));     // searchable: false
```

The rule of thumb: a field whose value is *prose a human would
type to find this item* defaults to indexed. Hashes, file paths,
numeric IDs, and money amounts default out. Override either way:

```php
// Make a slug not searchable — it's already in the URL.
$fields->ensure(Field::slug($post->id, 'slug')->searchable(false));

// Make a numeric SKU searchable — your users will type it.
$fields->ensure(Field::integer($product->id, 'sku')->searchable(true));
```

`name` and `label` are structural columns on the `items_fts`
table and are *always* indexed — they live on the item row itself,
not in `data`, and the `searchable` flag doesn't apply.

What `searchable` costs:

- **Storage**: roughly 1× the indexed text size again. FTS5
  stores its inverted index inline.
- **Save throughput**: every save rewrites the item's FTS row.
  For a 5KB article, this is sub-millisecond; for a 500KB body
  on a write-heavy install, measure first.
- **Tokenizer trade-offs**: the default `unicode61` tokenizer is
  language-agnostic — no stemming (`"running"` won't match
  `"run"`). If you need stemming, see the `tokenize =` options in
  `docs/api/storage.md`.

Use `searchable` for human-readable prose: **titles**, **bodies**,
**descriptions**, **comments**. Skip it for opaque identifiers —
searching for `"550e8400-e29b-41d4-a716"` to find a UUID defeats
the tokenizer.

### A mental model for choosing

Two yes/no questions, asked per field:

1. *"Will I filter or sort by this in a `$items->query(...)` call?"*
   → if yes, `->indexed()`.
2. *"Will a user type words from this to find an item via
   full-text search?"*
   → if yes, `->searchable()`.

Both, one, or neither — each combination is fine. Most fields end
up with neither flag (display-only); a few have one; the workhorses
of your app (titles, search-relevant body text) have both.

### The expanded cheat sheet

| Field is for | `indexed` | `searchable` | Why |
|---|---|---|---|
| URL slug, primary lookup key | yes | no | filtered by exact value; FTS would waste tokens on `hello-world` |
| Date / time you sort or filter by | yes | no | range queries (`< now()`) hit the B-tree index |
| Author / category foreign-key field | yes | no | joins + equality filters |
| Article title | yes | yes | sortable + user searches by it |
| Article body | no | yes | rarely sorted; users search by its words |
| Short description / excerpt | no | yes | feeds the same FTS index |
| Hashed password | no | no | never queried by value, never searched |
| Cover image, file upload | no | no | filenames don't help users find anything; binary metadata isn't tokenizable |
| Hidden status / role enum | yes | no | filtered (`active = 1`); never searched |
| Free-text note that's display-only | no | no | nothing to filter or search by |

### Performance with concrete numbers

A real-world feel for the order of magnitude, on a single-host
SQLite install with WAL mode:

| Operation | Without flag | With flag |
|---|---:|---:|
| Filter by `published_at < now` on **100 rows** | ~0.2 ms | ~0.1 ms |
| Filter by `published_at < now` on **10k rows** | ~12 ms | ~0.15 ms |
| Filter by `published_at < now` on **100k rows** | ~95 ms | ~0.18 ms |
| Full-text search on **10k articles** | n/a — would need LIKE `%…%` scan, ~80 ms | ~1.5 ms |
| Full-text search on **100k articles** | not viable (>800 ms) | ~2 ms |

These are typical ballpark numbers — your hardware, schema, and
query shape will move them. The shape is what matters: **unindexed
JSON filtering is O(n), indexed access is effectively O(log n)**,
and the gap widens as your data grows.

Scriptor's `bin/perf-smoke.php` (a sibling-project tool) runs four
canonical timing checkpoints against the live database, including
`FullTextSearch::search()`. On the bundled demo dataset (about a
dozen items) every operation comes in well under 1ms; the value
of the flags shows up once a real install has a few thousand
rows.

### A note on changing flags after the fact

The flags aren't immutable. If you flip `->indexed(true)` on an
existing field and re-save it (via `findByName()` + `save()`, see
the "Run it" section below), iManager runs the `ALTER TABLE` that
adds the generated column + index right then. The reverse
(flipping `indexed` off) drops them. That makes flag changes a
runtime concern: a quick development tweak is one `save()` away,
but on a production database with millions of rows the `CREATE
INDEX` takes proportional time. Plan accordingly — adding an
index to a live high-write table is usually done during a low-
traffic window.

## Build the blog schema

Create `blog-schema.php`:

```php
<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use Imanager\DefaultBootstrap;
use Imanager\Domain\Category;
use Imanager\Domain\Field;
use Imanager\Enum\FieldType;
use Imanager\Storage\CategoryRepository;
use Imanager\Storage\FieldRepository;

$container = DefaultBootstrap::boot(
    databasePath: __DIR__ . '/data/blog.db',
    uploadsPath:  __DIR__ . '/data/uploads',
    uploadsUrl:   '/uploads',
    cachePath:    __DIR__ . '/data/cache',
);

$categories = $container->get(CategoryRepository::class);
$fields     = $container->get(FieldRepository::class);
```

Define the category. `CategoryRepository::ensure()` is upsert by
natural key (`slug`): it inserts when the slug is new and returns
the existing row when it isn't — re-runs are safe.

```php
$post = $categories->ensure(new Category(null, 'Post', 'post'));
```

For the fields, `FieldRepository::ensure()` does the same against
`(categoryId, name)`. The fields themselves are built with the
`Field::*` static factories + fluent setters introduced in 2.1.0,
so the call site reads top-to-bottom like the schema you want:

```php
$fields->ensure(
    Field::text($post->id, 'title', 'Title')
        ->required()->indexed()->searchable()->maxLength(200),
);

$fields->ensure(
    Field::slug($post->id, 'slug', 'URL slug')
        ->required()->indexed(),
);

$fields->ensure(
    Field::longText($post->id, 'body', 'Body')
        ->required()->searchable(),
);

$fields->ensure(
    Field::datepicker($post->id, 'published_at', 'Published at')
        ->indexed(),
);

$fields->ensure(
    Field::image($post->id, 'cover_image', 'Cover image')
        ->maxBytes(5_000_000)
        ->mimes('image/jpeg', 'image/png', 'image/webp'),
);

echo "Schema ready for category #{$post->id} ({$post->name})\n";
```

Each fluent setter returns a new `final readonly Field`, so the
chain is pure value-object construction with no hidden state. The
type-aware setters (`maxLength`, `maxBytes`, `mimes`, …) all write
into the field's `config` array under documented keys; see
[`docs/api/field-types.md`](../api/field-types.md) for the full key
list each built-in plugin understands.

## Run it

```bash
php blog-schema.php
```

First run prints `Schema ready for category #1 (Post)`. Second
run prints the same line — no error, because `ensure()` returns
the existing row on the hit path.

**Third run after editing a flag** — say you change `->indexed()`
to `->indexed(false)` and re-run? The flag change is silently
ignored: `ensure()` is *insert-on-miss, return-existing-on-hit* by
design, not *upsert-with-update*. This is deliberate — a stray
`->indexed()` flip during schema iteration would otherwise trigger
an `ALTER TABLE`-equivalent on every request without warning, and
the same for `->searchable()` triggering a full FTS reindex.

For genuine updates during development, route the existing field
back through `save()` (which IS an update when `$id !== null`):

```php
$existing = $fields->findByName($post->id, 'title');
\assert($existing !== null);

$fields->save(
    $existing->indexed(false)->maxLength(500),
);
```

The fluent setters work just as well on a fetched field as on a
fresh factory output — the existing `id` carries through, and
`save()` does an update.

## A pattern: keep schema and code together

There's no rule that the schema lives in its own file. A common
shape for small embeddings is "the page that uses the data ensures
the schema first," like:

```php
function bootBlog(\League\Container\Container $container): int
{
    $categories = $container->get(CategoryRepository::class);
    $fields     = $container->get(FieldRepository::class);

    $post = $categories->ensure(new Category(null, 'Post', 'post'));

    $fields->ensure(Field::text($post->id, 'title')->required()->indexed()->searchable());
    /* … */

    return $post->id;
}

$postCategoryId = bootBlog($container);
$posts = $container->get(ItemRepository::class)->findByCategory($postCategoryId);
```

This makes the schema's authority obvious — there's no "wait, where
did the `Post` category get defined?" The trade-off is the cost of
running the `ensure()` lookups on every request; for a five-field
schema that's a handful of microseconds and well below noise.

For larger schemas (dozens of categories, hundreds of fields), keep
the setup in a one-shot CLI script and run it during deploy, the
same way you'd run migrations in a Symfony or Laravel app.

## What just happened, in one paragraph

You declared a `Post` category and five fields with carefully
chosen `FieldType`s via the `Field::*` factories, flagged the
queryable ones `->indexed()` and the searchable ones
`->searchable()`, set per-field config for length / mime /
max-bytes constraints with the type-aware fluent setters, and
ran every step through `ensure()` so the script is safe to
re-run. The result is that an item save into `Post` will
round-trip the five values through their typed plugins, the title
and body will be findable via FTS5, and queries filtering by
`slug` or `published_at` will hit indexes instead of scanning
JSON.

## Next steps

- **[Validate user input before saving](validation.md)** — `save()`
  doesn't run your field plugins' `validate()` for you; the next
  chapter shows the canonical loop.
- **[`docs/query-cookbook.md`](../query-cookbook.md)** — once you
  have items, predicates and pagination are the next thing you'll
  reach for.

## Reference

- [`docs/api/field-types.md`](../api/field-types.md) — every built-in
  field type's storage shape and config keys.
- [`docs/api/domain.md`](../api/domain.md) — `Field` flags explained
  in the value-object reference.
- [`docs/api/storage.md`](../api/storage.md#hot-fields--generated-columns)
  — what `indexed` actually does to the SQL schema.
- [`docs/field-types.md`](../field-types.md) — the cookbook for
  writing your own field-type plugin.
