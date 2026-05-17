# The lifecycle of categories, fields, and items

The other foundation chapters focused on **writing** data — schema
setup, item save, validation. This chapter covers what happens
afterwards: how to **update** a row, how to **delete** one, what
cascades automatically, what doesn't, and how to wire up
application-level cleanup (physical file removal, cache
invalidation) through the event system.

Mini-case throughout: a single-user **bookmark vault** with one
category (`Bookmark`) holding `title`, `url`, and `cover_image`
fields. Small enough to fit in one head, has uploaded files so the
deletion stories have something concrete to clean up.

## The three verbs

Every iManager repository (`CategoryRepository`, `FieldRepository`,
`ItemRepository`, `FileRepository`) speaks the same three verbs:

```php
$repo->save($value);     // insert if id is null, update if id is set
$repo->ensure($value);   // upsert by natural key (Category + Field only)
$repo->delete(int $id);  // remove by primary key
```

Plus the read verbs (`find`, `findBy…`, `findAll`, `query`) that
return value objects to feed back in.

This chapter is mostly about the second life of those value
objects — what happens after they exist.

## Setup

Build the schema once, the same shape as the [schema chapter](schema.md):

```php
require __DIR__ . '/vendor/autoload.php';

use Imanager\DefaultBootstrap;
use Imanager\Domain\Category;
use Imanager\Domain\Field;
use Imanager\Domain\Item;
use Imanager\Storage\CategoryRepository;
use Imanager\Storage\FieldRepository;
use Imanager\Storage\ItemRepository;

$container = DefaultBootstrap::boot(
    databasePath: __DIR__ . '/data/bookmarks.db',
    uploadsPath:  __DIR__ . '/data/uploads',
    uploadsUrl:   '/uploads',
    cachePath:    __DIR__ . '/data/cache',
);

$categories = $container->get(CategoryRepository::class);
$fields     = $container->get(FieldRepository::class);
$items      = $container->get(ItemRepository::class);

$bookmark = $categories->ensure(new Category(null, 'Bookmark', 'bookmark'));

$fields->ensure(Field::text($bookmark->id, 'title', 'Title')->required()->maxLength(200));
$fields->ensure(Field::text($bookmark->id, 'url', 'URL')->required()->maxLength(2000));
$fields->ensure(
    Field::image($bookmark->id, 'cover_image', 'Cover')
        ->maxBytes(2_000_000)->mimes('image/jpeg', 'image/png'),
);

$saved = $items->save(new Item(
    null, $bookmark->id, 'imanager-docs', 'iManager Documentation',
    data: ['title' => 'iManager Documentation', 'url' => 'https://github.com/bigin/imanager'],
));
```

You now have one `Bookmark` item with id 1. The rest of the chapter
mutates and removes it.

## Update — three patterns

### Pattern 1: read, change, save

The most common shape — fetch the existing value object, derive a
new one with the change you want, write it back:

```php
$existing = $items->find($saved->id);
\assert($existing !== null);

$updated = $items->save(new Item(
    id:         $existing->id,        // keep the id ⇒ this is an update
    categoryId: $existing->categoryId,
    name:       $existing->name,
    label:      'iManager Library Documentation',   // ← the change
    position:   $existing->position,
    active:     $existing->active,
    data:       $existing->data,
));
```

`save()` distinguishes insert from update by whether `$item->id` is
`null`. With an id present, it issues `UPDATE items SET … WHERE id
= :id` and fires `ItemUpdated`. With `id = null`, it issues `INSERT`
and fires `ItemCreated`.

### Pattern 2: fluent setters on a fetched value object

For `Field` (which got fluent setters in 2.1.0), the same pattern
is one expression:

```php
$titleField = $fields->findByName($bookmark->id, 'title');
\assert($titleField !== null);

$fields->save(
    $titleField->maxLength(500)->placeholder('Page title…'),
);
```

Each setter returns a new `final readonly Field` with the existing
`id` preserved — `save()` sees the id and does an update.

(For `Item`, there's a similar `$item->data->with('key', $value)`
on the `FieldValueBag` — see [`docs/api/domain.md`](../api/domain.md)
for the bag's `with()` / `without()` / `merge()` helpers.)

### Pattern 3: `ensure()` cannot update

`ensure()` is deliberately insert-only on the natural-key path —
this is the safety promise documented in
[`docs/imanager-2.1-plan.md`](../imanager-2.1-plan.md): a
silently-flipped `indexed` flag would trigger an `ALTER TABLE` you
didn't ask for. So if you want an idempotent setup script that
*also* applies flag updates on re-run, you compose:

```php
$existing = $fields->findByName($bookmark->id, 'title');
$fields->save(
    $existing
        ? $existing->maxLength(500)        // update path
        : Field::text($bookmark->id, 'title', 'Title')->maxLength(500),
);
```

Verbose, but explicit about what's an update.

### Events that fire on updates

Every successful update emits its `*Updated` event carrying **both**
the previous and the new value object, so listeners can diff:

```php
use Imanager\Domain\Event\FieldUpdated;
use Imanager\Events\SubscriberListenerProvider;

$provider = $container->get(SubscriberListenerProvider::class);

$provider->subscribe(FieldUpdated::class, function (FieldUpdated $e): void {
    if ($e->previous->maxLength !== $e->current->maxLength) {
        echo "Field {$e->current->name}: maxLength {$e->previous->maxLength}"
            . " → {$e->current->maxLength}\n";
    }
});
```

Same shape for `CategoryUpdated` (`previous`, `current`) and
`ItemUpdated` (`previous`, `current`).

## Delete

The interesting work. Deleting in iManager isn't just a SQL DELETE
— it's a cascade with carefully-ordered events, and a few things
that **don't** happen automatically that you have to know about.

### Deleting a category

```php
$categories->delete($bookmark->id);
```

What runs, in order:

1. **Lookup**: `find($id)` confirms the row exists; raises
   `NotFoundException` if not (no silent no-op delete).
2. **`CategoryDeleted` event fires.** Crucially — *before* the SQL
   delete, so listeners can still walk into the soon-to-be-gone
   children if they need to. Listeners that need fields / items /
   files for cleanup have one shot here.
3. **`DELETE FROM categories WHERE id = :id`** runs.
4. **SQLite's foreign-key cascade kicks in**:
   - All `fields` rows with `category_id = $id` → gone.
   - All `items` rows with `category_id = $id` → gone.
   - All `files` rows referencing those items → gone (transitive
     cascade through `items.id`).
5. **The category-id index entries and the indexed-field generated
   columns are NOT cleaned up.** ← see the caveat section below.

### Deleting a field

```php
$fields->delete($titleField->id);
```

What runs:

1. Lookup or `NotFoundException`.
2. **`FieldDeleted` event fires** with the field's `id`,
   `categoryId`, and `name` (because by event time you couldn't
   recover those by lookup anymore).
3. `DELETE FROM fields WHERE id = :id`.
4. **If `$field->indexed === true`**, the matching generated column
   and its index get dropped — `ALTER TABLE items DROP COLUMN gen_<catId>_<fieldName>` plus the matching `DROP INDEX`. This is the
   ONLY path that cleans up generated columns; see the caveat below.
5. The field's JSON key in existing items' `data` columns is **NOT
   touched**. Item rows still carry `{"title": "..."}` for items
   created before deletion — there's just no schema entry for it
   anymore. Items become valid (any key, no validation), the values
   simply orphan.

### Deleting an item

```php
$items->delete($saved->id);
```

What runs:

1. Lookup or `NotFoundException`.
2. **`ItemDeleted` event fires** with the item's `id` and
   `categoryId` — *before* the SQL DELETE, intentionally, so
   listeners can still call `$files->findByItem($id)` to walk
   physical-file metadata before the FK cascade flattens it.
3. `DELETE FROM items WHERE id = :id`.
4. `DELETE FROM items_fts WHERE rowid = :id` — keeps the FTS index
   in sync.
5. **SQLite cascade**: all `files` rows for this item → gone.

The deliberate event-before-delete ordering for the `Deleted` events
is the difference between iManager and a naive ORM: by the time
your listener runs, the row is still readable. You don't need to
denormalize "what files did this item have" into the event payload
— you can just look them up.

## Cascade caveats

Two situations where the automatic cascades are weaker than they
look. Worth knowing before you build a long-lived install.

### Orphan generated columns on category delete

When you delete a *field* explicitly via `$fields->delete($id)`,
iManager calls `IndexedFields::drop()` to remove the generated
column from the `items` table.

When you delete a *category* via `$categories->delete($id)`, the
fields are removed via SQLite FK cascade — but that cascade goes
straight through the database, not through `SqliteFieldRepository`.
So `IndexedFields::drop()` is **not invoked**. The generated columns
sit on the `items` table forever, referencing `data` keys for
items that no longer exist. They compute to `NULL` for all
remaining rows and waste a little schema overhead.

Workaround: enumerate the category's fields and delete them
explicitly *before* deleting the category, so each field's
`delete()` path runs the proper cleanup:

```php
foreach ($fields->findByCategory($bookmark->id) as $field) {
    $fields->delete($field->id);
}
$categories->delete($bookmark->id);
```

A future iManager release may close this loop via a
`CategoryDeleted` listener wired into `DefaultBootstrap`; for now
the manual loop is the honest answer.

### Physical files survive cascade

The `files` SQL table cascades cleanly: a deleted item → its file
metadata rows → gone. But the **physical bytes on disk** (the
`<uploadsPath>/<itemId>/<fieldId>/photo.jpg` files written by the
upload handler) are NOT touched by iManager's storage layer. The
database happily forgets the metadata; the bytes orphan on disk.

This is by design — iManager doesn't assume the FileStorage backend
is local-disk; it could be S3, a CDN, a CAS store. Deletion through
those backends has implementation-specific semantics, so iManager
delegates the cleanup to a **host-supplied listener** subscribed
to `ItemDeleted`. The next section shows the canonical shape.

## Wiring file cleanup yourself

The pattern: subscribe an `ItemDeleted` listener, walk the item's
files via `FileRepository`, delete each via `FileStorage`.

```php
use Imanager\Domain\Event\ItemDeleted;
use Imanager\Events\SubscriberListenerProvider;
use Imanager\Files\FileStorage;
use Imanager\Files\FileStorageException;
use Imanager\Storage\FileRepository;

$provider = $container->get(SubscriberListenerProvider::class);
$files    = $container->get(FileRepository::class);
$storage  = $container->get(FileStorage::class);

$provider->subscribe(
    ItemDeleted::class,
    function (ItemDeleted $event) use ($files, $storage): void {
        foreach ($files->findByItem($event->itemId) as $file) {
            try {
                $storage->delete($file->path);
            } catch (FileStorageException) {
                // Already gone, or transient I/O failure. Log + move on —
                // raising here would abort the SQL DELETE that follows the
                // event (see "exceptions" below).
            }
        }
    },
);
```

What this gets you:

- Every `$items->delete($id)` triggers the listener.
- The listener runs **before** the FK cascade flattens the file
  metadata, so `$files->findByItem()` still returns the rows.
- For each file, `$storage->delete($file->path)` removes the
  physical bytes.
- After the listener returns, the SQL DELETE runs and the file
  metadata rows go with the item via FK cascade.

The same pattern works for `FieldDeleted` (when you remove a field
that owned upload-type values) — though the field-delete path is
less common than item-delete, and the cleanup walk would use
`FileRepository::findByItemAndField()` on a per-item loop.

### Exceptions inside listeners

iManager's `SyncEventDispatcher` does **not** swallow listener
exceptions — they propagate up. For `Deleted` events the dispatch
happens *before* the SQL delete, so a throwing listener leaves the
row intact in the database. That's a feature, not a bug: a failed
file cleanup shouldn't be quietly papered over by a successful row
delete that orphans the files.

Practical consequence: keep listener bodies defensive. The
try/catch in the example above swallows `FileStorageException` so
"file already gone" doesn't poison the whole delete. Anything more
serious you'd let propagate.

## No soft-delete on the model layer

iManager has no `deleted_at` / `is_deleted` mechanism baked in.
`delete()` is a hard delete; the row is gone, the event fires once.

Most reasons to want soft-delete map to one of three patterns:

- **"Hide without removing"** — use the existing `Item::$active`
  boolean. Default queries can filter for `active = true`; admin
  views can show inactive too. No data loss, no cascade headaches.
- **"Restore-on-undo"** — keep a separate audit/snapshot table
  populated from `ItemUpdated` and `ItemDeleted` listeners. The
  iManager core doesn't enforce a shape; you control it.
- **"Compliance / retention window"** — a scheduled job runs
  `$items->delete($id)` after the retention period; until then,
  use `active = false`.

If you genuinely need soft-delete at the model layer (e.g., for
referential integrity reasons), the lightest implementation is a
custom field of type `Datepicker` named `deleted_at`, plus a
`Query::where('deleted_at', '=', null)` clause baked into your read
paths.

## What just happened, in one paragraph

You walked through how iManager mutates state — the three repository
verbs (`save`, `ensure`, `delete`), the events each emits, and the
ordering quirks that matter when listeners need to read state
on the way out. You learned that SQLite FK cascades handle the
*metadata* tables (categories → fields, items, files) automatically
but leave two real-world gaps: indexed generated columns aren't
cleaned up when a category is deleted via cascade, and physical
file bytes survive an `ItemDeleted` unless a host listener tears
them down. You saw the canonical file-cleanup listener using
`ItemDeleted` + `FileRepository::findByItem` + `FileStorage::delete`,
and read why iManager has no soft-delete baked in (it doesn't need
one — `Item::$active` handles the most common cases).

## Reference

- [`docs/api/domain.md`](../api/domain.md) — every event with its
  payload shape (`*Created`, `*Updated`, `*Deleted` × Category /
  Field / Item, plus the marker `DomainEvent` interface).
- [`docs/api/storage.md`](../api/storage.md) — repository contracts
  for `save()`, `ensure()`, `delete()`.
- [`src/Files/FileStorage.php`](../../src/Files/FileStorage.php) —
  the file-bytes-backend interface; `LocalFileStorage` is the
  default, swap for S3-shaped backends by registering a different
  binding in your bootstrap.
- [`src/Events/SyncEventDispatcher.php`](../../src/Events/SyncEventDispatcher.php)
  — the in-process PSR-14 dispatcher iManager wires by default,
  with notes on its sync + propagate-exception semantics.
