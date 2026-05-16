# Setup your first install

You'll build a one-table notebook — a single `Note` category with
one `body` field — and walk through every line that gets you from
`composer require` to "the database round-trips a row." About 5
minutes if you copy-paste, 15 if you pause to read the asides.

## What you need

- PHP **8.2 or newer**
- The extensions iManager actually opens: `pdo_sqlite`, `mbstring`,
  `gd`, `dom`, `json`. They're in every default PHP install — if
  yours is stripped down (Alpine container, Debian `php-cli` only),
  `apt install` / `apk add` the missing ones.
- **Composer 2**. Composer 1 won't resolve the `^8.2` PHP constraint.

Verify quickly:

```bash
php --version              # → PHP 8.2.x or newer
php -m | grep -E 'pdo_sqlite|mbstring|gd|dom|json'
composer --version         # → Composer version 2.x.x
```

## Install

A throwaway project to play in:

```bash
mkdir notebook && cd notebook
composer require bigins/imanager:^2.0
```

Composer pulls iManager 2.0.2 (or newer in the 2.x line) and writes
`composer.json` + `composer.lock` + a `vendor/` tree. There's nothing
else to install — iManager is a pure-PHP library, no native build
step, no compile-time configuration.

## The shape of an iManager app

Every iManager-using process — a web request, a CLI script, a queue
worker — goes through the same three steps:

1. **Boot** a *container* once at the start of the process.
2. **Pull services** (repositories, the field-type registry, the
   event dispatcher, …) out of the container as you need them.
3. **Call** those services to read or write data.

A *container* is just a dictionary of pre-built services keyed by
class name: ask for `CategoryRepository::class`, get a fully wired
`CategoryRepository` back. iManager uses one because the standard
service graph has ~15 objects with non-trivial wiring (PDO →
SqliteStorage → CategoryRepository, plus the event dispatcher hooked
into every repository); a container saves you from doing that wiring
by hand. If you've used PSR-11 before, iManager's container is one
of those.

The boot helper that produces the container is
`Imanager\DefaultBootstrap::boot()`.

## Boot it

Create `notebook.php` in the project root:

```php
<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use Imanager\DefaultBootstrap;
use Imanager\Domain\Category;
use Imanager\Domain\Field;
use Imanager\Domain\Item;
use Imanager\Storage\CategoryRepository;
use Imanager\Storage\FieldRepository;
use Imanager\Storage\ItemRepository;

$container = DefaultBootstrap::boot(
    databasePath: __DIR__ . '/data/notebook.db',
    uploadsPath:  __DIR__ . '/data/uploads',
    uploadsUrl:   '/uploads',
    cachePath:    __DIR__ . '/data/cache',
);
```

What each parameter means:

| Parameter | What lives there |
|---|---|
| `databasePath` | The SQLite file. iManager creates it on first call **and** runs the schema migrations into it — you don't run `CREATE TABLE` yourself. The parent directory (`data/` here) is auto-created if missing. |
| `uploadsPath` | Where binary uploads (file / image fields) get stored. Subdirectories are `<itemId>/<fieldId>/<filename>`. Auto-created if missing. |
| `uploadsUrl` | The URL prefix your webserver serves `uploadsPath` from. Used when iManager hands you a `File` object and you want to render its URL. For the notebook example we never serve files, so this value is just along for the ride. |
| `cachePath` | Where the optional filesystem cache lives. iManager itself doesn't write here in the standard service graph; some hosts (e.g. Scriptor's frontend) plug a `FilesystemCache` in via this path. Auto-created if missing. |

If you'd rather not have iManager `mkdir` for you — say, you want to
manage filesystem layout from your deploy scripts — use
`Imanager\Bootstrap::boot()` instead. It takes the same path
arguments but skips the auto-`mkdir` and lets you wire services
explicitly. `DefaultBootstrap` is just `Bootstrap` + opinionated
defaults for the 90% case.

## Get the repositories

Three lines after the boot call:

```php
$categories = $container->get(CategoryRepository::class);
$fields     = $container->get(FieldRepository::class);
$items      = $container->get(ItemRepository::class);
```

These are the three doors into your data:

- **`CategoryRepository`** owns the *kinds of thing* in your install
  (here: just `Note`).
- **`FieldRepository`** owns the *columns* each kind exposes.
- **`ItemRepository`** owns the *rows* themselves.

Categories and fields define the **shape**; items are the **content**.

## Define the schema

Add to `notebook.php`:

```php
// Idempotent — ensure() inserts on first call and returns the
// existing row on every later call.
$note = $categories->ensure(new Category(null, 'Note', 'note'));

$fields->ensure(
    Field::longText($note->id, 'body', 'Body')->required(),
);
```

A few things to notice:

- **`ensure()` vs `save()`**: `ensure()` is upsert by natural key —
  for categories that's the unique `slug`, for fields it's
  `(categoryId, name)`. On a hit, the existing row is returned
  unchanged; on a miss, a new row is inserted. `save()` is the
  lower-level primitive that always writes (and raises a UNIQUE
  error if you try to insert a duplicate). For schema setup,
  `ensure()` is what you want; for runtime writes that *should*
  fail loudly on conflict, reach for `save()`.
- **`Category::name` vs. `slug`**: `name` is the human-facing label
  ("Note"), `slug` is the URL/JSON-stable identifier ("note"). Both
  are globally unique within the install.
- **`Field::longText($note->id, 'body', 'Body')`** is a static
  factory that returns a fresh `Field` of type `LongText`. There
  are 15 more — `Field::text()`, `Field::image()`,
  `Field::datepicker()`, … — one per built-in `FieldType`. Setters
  like `->required()`, `->indexed()`, `->maxLength(200)` chain off
  them. The full picker lives in the
  [schema chapter](schema.md).

## Write a row

```php
$saved = $items->save(new Item(
    id:         null,
    categoryId: $note->id,
    name:       'first-note',
    label:      'First note',
    data:       ['body' => 'Hello from iManager.'],
));

echo "Wrote item #{$saved->id}\n";
```

Items have their own `name` / `label` distinction:

- `name` is meant for URL slugs and lookups by stable identifier.
- `label` is the human-readable title that shows up in lists.

`data` is the field values, keyed by `Field::name`. Pass an array
and iManager wraps it in a `FieldValueBag` for you — `$saved->data`
on the returned item is the bag, not the raw array. Bags are
immutable, so the next chapters use `$item->data->get('body')` to
read and `$item->data->with('body', $new)` to update.

> **Heads up — `save()` does not validate.** You can write any
> nonsense into `data` here. iManager's validation contract is a
> separate `FieldTypeRegistry::get($type)->validate(...)` call that
> the [validation chapter](validation.md) covers in full. For a
> notebook with one trusted user that's fine; for anything taking
> external input, validate first.

## Read it back

```php
$all = $items->findByCategory($note->id);

foreach ($all as $item) {
    echo "#{$item->id} {$item->label}\n";
    echo "    " . $item->data->get('body') . "\n";
}
```

`findByCategory()` returns every item in a category in `position`
order. For one-off lookups you'd use `$items->find($id)` (by primary
key) — there's no `findByName()` on the item repo today, so if you
need lookups by `Item::name` you go through `$items->query(...)`
(see [`docs/query-cookbook.md`](../query-cookbook.md)).

## Run it

```bash
php notebook.php
```

First run:

```
Wrote item #1
#1 First note
    Hello from iManager.
```

Second run (no cleanup) prints the same line plus a second item —
`ensure()` saw both the category and the field already existed and
returned them as-is, then the item save inserted a new row (items
have no UNIQUE on name, so duplicates are allowed). Each subsequent
run adds another item, building up a small list:

```
Wrote item #2
#1 First note
    Hello from iManager.
#2 First note
    Hello from iManager.
```

That's the natural shape of an iManager install — schema is
declarative + idempotent, content is append-only by default.

## What just happened, in one paragraph

`DefaultBootstrap::boot()` opened the SQLite file (creating it if
absent), ran every pending schema migration, and built a container
holding the 16 built-in field-type plugins, the three repositories,
the event dispatcher, and a few smaller services. Your script then
asked the container for the three repositories and used them to
declare a category (`Note`), a field (`body`), and an item
(`First note`). The `Item::$data` you passed as an array became a
`FieldValueBag`; the saved item came back with its auto-assigned
`id` and `created` / `updated` timestamps populated.

## Next steps

- **[Design a content schema](schema.md)** — picking the right
  field types, when to mark a field `indexed` or `searchable`, and
  how to make your schema setup idempotent.
- **[Validate user input before saving](validation.md)** — the
  validation contract every external input has to pass through.

## Reference

- [`Imanager\DefaultBootstrap`](../../src/DefaultBootstrap.php) — the
  boot helper itself, fully commented.
- [`docs/api/domain.md`](../api/domain.md) — the value objects
  (`Category`, `Field`, `Item`, `FieldValueBag`) the snippets above
  passed around.
- [`docs/api/storage.md`](../api/storage.md) — the three repositories
  in detail.
