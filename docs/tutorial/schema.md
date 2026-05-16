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

Two boolean fields on `Field` change the runtime behavior of the
field — they're the difference between "iManager stores it" and
"iManager *knows about* it."

### `indexed: true` — promote to a SQLite generated column

iManager normally stores all field values inside one JSON blob per
item. JSON is great for write-side flexibility, but **`json_extract`
is unindexed**: filtering or sorting by a JSON key reads every row
in the category.

Flagging a field `indexed` adds a SQLite *generated column* on the
`items` table that mirrors that JSON key, plus an index on it. Then
the `Query` builder can filter and sort by it for free:

```php
// Without indexed=true, this scans the table:
$items->query(Query::for($postId)->where('published_at', '<', time()));
```

Use for fields you **filter or sort by often**: slugs, foreign keys,
date columns. Don't use for fields you just display — the storage
cost is tiny but the maintenance is real (every save rewrites the
generated column).

### `searchable: true` — feed the FTS5 index

`searchable` includes the field's text value in the FTS5 full-text
index that powers `Imanager\Search\FullTextSearch::search()`. Use
for human-readable text: titles, body, descriptions. Don't use for
opaque identifiers — searching for `"hello-world"` to find a slug
defeats FTS5's tokenizer.

The cost is one extra `INSERT INTO items_fts` per item save. For
a hundred posts that's invisible; for a hundred thousand it's
something you'd measure with `bin/perf-smoke.php` (in Scriptor)
before flipping the flag.

### When to flip both, one, or neither

| Field is for | `indexed` | `searchable` |
|---|---|---|
| URL slug, primary lookup key | yes | no |
| Date / time you sort by | yes | no |
| Article body | no | yes |
| Article title | yes (for sorting) | yes |
| Hashed password | no | no |
| Cover image | no | no |

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

Define the category — and make it idempotent so re-running the
script is safe:

```php
$post = $categories->findBySlug('post')
    ?? $categories->save(new Category(
        id:   null,
        name: 'Post',
        slug: 'post',
    ));
```

The `??` short-circuits if `findBySlug` returns a `Category` — only
saves a fresh one when the slug isn't there yet. Same pattern works
for fields, but the field repo's lookup is by `(categoryId, name)`,
so the guard takes both arguments. The cleanest shape is a small
helper:

```php
function ensureField(
    FieldRepository $repo,
    int $categoryId,
    string $name,
    string $label,
    FieldType $type,
    bool $required = false,
    bool $indexed = false,
    bool $searchable = false,
    array $config = [],
): Field {
    return $repo->findByName($categoryId, $name)
        ?? $repo->save(new Field(
            id:         null,
            categoryId: $categoryId,
            name:       $name,
            label:      $label,
            type:       $type,
            required:   $required,
            indexed:    $indexed,
            searchable: $searchable,
            config:     $config,
        ));
}
```

Now declare the five fields:

```php
ensureField($fields, $post->id, 'title', 'Title',
    type: FieldType::Text,
    required: true,
    indexed: true,
    searchable: true,
    config: ['maxLength' => 200],
);

ensureField($fields, $post->id, 'slug', 'URL slug',
    type: FieldType::Slug,
    required: true,
    indexed: true,
);

ensureField($fields, $post->id, 'body', 'Body',
    type: FieldType::LongText,
    required: true,
    searchable: true,
);

ensureField($fields, $post->id, 'published_at', 'Published at',
    type: FieldType::Datepicker,
    indexed: true,
);

ensureField($fields, $post->id, 'cover_image', 'Cover image',
    type: FieldType::Imageupload,
    config: [
        'maxBytes' => 5_000_000,           // 5 MB
        'mimes'    => ['image/jpeg', 'image/png', 'image/webp'],
    ],
);

echo "Schema ready for category #{$post->id} ({$post->name})\n";
```

Notice what changes when `required`, `indexed`, `searchable`, or
`config` differ from the defaults — they're named-argument flags so
the call site stays readable. The `config` map is whatever the
plugin documents for itself; see
[`docs/api/field-types.md`](../api/field-types.md) for each type's
keys.

## Run it

```bash
php blog-schema.php
```

First run prints something like `Schema ready for category #1 (Post)`.
Second run prints the same line — no error, because every step is
guarded with a `findBy*()` lookup. Third run after editing a field's
flags? Still no error, but **the flag change is silently ignored** —
`ensureField` only saves if the field doesn't exist. If you want
upserts that update an existing field's flags, your helper has to do
a `save()` on the merged value:

```php
$existing = $fields->findByName($categoryId, $name);
$desired  = new Field(
    id:         $existing?->id,        // keep the id when updating
    categoryId: $categoryId,
    /* …all the other args… */
);
$fields->save($desired);
```

`save()` distinguishes insert from update by whether `$id` is `null`.
Use the upsert variant if you're iterating on the schema during
development; switch back to the cheap `??` guard once the schema is
stable.

## A pattern: keep schema and code together

There's no rule that the schema lives in its own file. A common
shape for small embeddings is "the page that uses the data ensures
the schema first," like:

```php
function bootBlog(\League\Container\Container $container): int
{
    $categories = $container->get(CategoryRepository::class);
    $fields     = $container->get(FieldRepository::class);

    $post = $categories->findBySlug('post')
        ?? $categories->save(new Category(null, 'Post', 'post'));

    ensureField($fields, $post->id, 'title', …);
    /* … */

    return $post->id;
}

$postCategoryId = bootBlog($container);
$posts = $container->get(ItemRepository::class)->findByCategory($postCategoryId);
```

This makes the schema's authority obvious — there's no "wait, where
did the `Post` category get defined?" The trade-off is the cost of
running the `findBy*()` lookups on every request; for a five-field
schema that's a handful of microseconds and well below noise.

For larger schemas (dozens of categories, hundreds of fields), keep
the setup in a one-shot CLI script and run it during deploy, the
same way you'd run migrations in a Symfony or Laravel app.

## What just happened, in one paragraph

You declared a `Post` category and five fields with carefully
chosen `FieldType`s, flagged the queryable ones `indexed` and the
searchable ones `searchable`, set per-field config for length /
mime / max-bytes constraints, and wrapped each step in a
`findBy*()` guard so the script is safe to re-run. The result is
that an item save into `Post` will round-trip the five values
through their typed plugins, the title and body will be findable
via FTS5, and queries filtering by `slug` or `published_at` will
hit indexes instead of scanning JSON.

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
