# Storage

The storage subsystem is **four repository contracts** plus one
implementation (`SqliteStorage`). Everything host code talks to is
defined by an interface — swap the implementation, keep your code.

> Source: `src/Storage/{Storage,CategoryRepository,FieldRepository,ItemRepository,FileRepository,SchemaManager,Migration}.php`,
> `src/Storage/Sqlite/SqliteStorage.php`, `src/Storage/InMemory/`.
>
> Contracts (executable spec): `tests/Unit/Storage/*Contract.php`.

---

## Storage interface

The umbrella interface — one object holds references to the four
repositories and to the transaction boundary.

```php
namespace Imanager\Storage;

interface Storage
{
    public function categories(): CategoryRepository;
    public function fields(): FieldRepository;
    public function items(): ItemRepository;
    public function files(): FileRepository;

    public function transactional(callable $work): mixed;
}
```

Use it when you want a single dependency for a service that touches
multiple repositories. Inject `Storage` and call
`$storage->items()->save(...)`; or inject `ItemRepository` directly if
that's all you need. `DefaultBootstrap` registers both styles, sharing
the same underlying instances.

---

## CategoryRepository

```php
namespace Imanager\Storage;

interface CategoryRepository
{
    public function find(int $id): ?Category;
    public function findBySlug(string $slug): ?Category;
    public function findAll(): array;          // list<Category>
    public function save(Category $category): Category;
    public function delete(int $id): void;
}
```

### Reads

- `find()` and `findBySlug()` return `null` for unknown identifiers —
  they do **not** throw.
- `findAll()` returns categories ordered by `position`, then `id`.

### `save()`

- With `id === null`: inserts a new row, returns a clone with `id`
  and `created`/`updated` populated.
- With `id !== null`: updates the existing row. **Throws
  `NotFoundException`** if the row no longer exists.
- **Throws `ValidationException`** on duplicate `name` or `slug`.
  Both columns are unique.

### `delete()`

- **Throws `NotFoundException`** if the row no longer exists.
- **Cascades** to all fields and items in the category. If you only
  want to remove the category and keep its items, reassign them
  first.

### Example

```php
$blog = $storage->categories()->save(new Category(null, 'Blog', 'blog'));

$same = $storage->categories()->findBySlug('blog'); // === $blog
```

---

## FieldRepository

```php
namespace Imanager\Storage;

interface FieldRepository
{
    public function find(int $id): ?Field;
    public function findByName(int $categoryId, string $name): ?Field;
    public function findByCategory(int $categoryId): array;   // list<Field>
    public function save(Field $field): Field;
    public function delete(int $id): void;
}
```

### Reads

- `findByName()` is the standard lookup — fields are uniquely
  identified by `(categoryId, name)`.
- `findByCategory()` returns fields ordered by `position`, then
  `id`.

### `save()`

- **Throws `ValidationException`** on duplicate `name` within the
  same category. The same `name` **is allowed** across different
  categories (`Blog.title` and `Page.title` can both exist).
- Round-trips the `config` array unchanged.

### Example

```php
$titleField = $storage->fields()->save(
    new Field(null, $blog->id, 'title', 'Title', FieldType::Text),
);

$schema = $storage->fields()->findByCategory($blog->id);
```

---

## ItemRepository

```php
namespace Imanager\Storage;

interface ItemRepository
{
    public function find(int $id): ?Item;
    public function findByCategory(int $categoryId, int $offset = 0, int $limit = 0): array;
    public function countByCategory(int $categoryId): int;

    public function query(Query $query): array;   // list<Item>
    public function count(Query $query): int;

    public function save(Item $item): Item;
    public function delete(int $id): void;
}
```

### `findByCategory()`

The cheapest path when you want "all items in category X in display
order":

- Returns items ordered by `position`, then `id`.
- `limit = 0` (the default) means **no limit**.
- `offset` and `limit` are applied at the SQL layer.

### `query()` and `count()`

The general-purpose entry point — accepts an immutable `Query`
value object (see [Query](query.md)). `count()` runs the same
predicate as `query()` but returns the row count instead of the
hydrated items. Useful for pagination headers.

### `save()`

- Writes `$item->data` to the SQLite `data` column **verbatim**.
  `save()` does **not** invoke field-type plugins — validation is
  the host's responsibility (see the
  [Field-types cookbook](../field-types.md#the-validation-pipeline)
  for the canonical "validate before save" pattern). If you skip
  the plugin call, whatever you pass in lands in the JSON column as
  is: raw form input ends up unsanitised, plaintext passwords are
  stored plaintext, a `Password`-typed field doesn't auto-hash on
  save. Run your input through `FieldTypeRegistry::get($field->type)->validate(...)`
  before constructing the `Item`.
- Promotes "hot" (indexed) field values into generated columns
  automatically — you do **not** need to update them separately.
- **Throws `NotFoundException`** if you save an item with a
  non-existent `categoryId`, or update an item whose `id` no longer
  exists.

### Example

```php
$post = $storage->items()->save(new Item(
    null,
    $blog->id,
    'first-post',
    'First Post',
    data: ['title' => 'Hello', 'body' => 'World'],
));

$page = $storage->items()->findByCategory($blog->id, offset: 0, limit: 10);
```

---

## FileRepository

```php
namespace Imanager\Storage;

interface FileRepository
{
    public function find(int $id): ?File;
    public function findByItem(int $itemId): array;                  // list<File>
    public function findByItemAndField(int $itemId, int $fieldId): array;
    public function save(File $file): File;
    public function delete(int $id): void;
}
```

### Scope

`FileRepository` only tracks file **metadata**. The bytes themselves
are written through `Imanager\Files\FileStorage`. The two are
deliberately separate so you can mount object storage (S3, …) by
swapping the file storage without changing the metadata layer.

### Reads

- `findByItem()` returns files ordered by `position`, then `id`.
- `findByItemAndField()` is the standard accessor for a single
  file-typed field on an item.

### `save()`

- Same `id === null` / `id !== null` insert-vs-update split as the
  other repositories.
- **Throws `NotFoundException`** if `itemId` or `fieldId` references a
  row that doesn't exist.
- Common in-place update flow:

```php
$file    = $storage->files()->find($id);
$updated = $storage->files()->save($file->withTitle('New caption'));
```

---

## Hot fields & generated columns

For each field that has `indexed = true`, the SQLite schema carries a
generated column:

```sql
"field_<name>" AS (json_extract(data, '$.<name>')) STORED
```

The column's storage affinity follows `FieldType::sqliteAffinity()`
(text, integer, real, blob). You query against `field_<name>` via
the normal `Query` builder; the builder picks the generated column
automatically when the field is hot, and falls back to
`json_extract(data, ...)` when it isn't.

Toggling `indexed` from `false` → `true` on an existing field requires
a schema migration — it changes the table shape. The library does
**not** auto-migrate on save; you write the migration explicitly under
`config/schema/` and run `schema:migrate`.

---

## Transactions

```php
public function transactional(callable $work): mixed;
```

`SqliteStorage::transactional()` wraps the callback in
`BEGIN IMMEDIATE` / `COMMIT`, rolls back on any exception, and
returns whatever the callback returned. Domain events are dispatched
**after** commit — a listener that throws does not undo the work.

```php
$itemId = $storage->transactional(function () use ($storage, $blog) {
    $a = $storage->items()->save(new Item(null, $blog->id, 'a', 'A'));
    $b = $storage->items()->save(new Item(null, $blog->id, 'b', 'B'));
    return $a->id;
});
```

Nested calls are **not** supported — `SqliteStorage` will throw on a
nested `transactional()`. Compose your work as one outer transaction.

---

## SqliteStorage

The bundled implementation. You normally never construct it directly
— `DefaultBootstrap::boot()` does. Construct manually only when you
want a different PDO or a different event dispatcher:

```php
namespace Imanager\Storage\Sqlite;

final class SqliteStorage implements Storage
{
    public function __construct(
        \PDO $connection,
        ?\Psr\EventDispatcher\EventDispatcherInterface $events = null,
    );

    public function categories(): CategoryRepository;
    public function fields(): FieldRepository;
    public function items(): ItemRepository;
    public function files(): FileRepository;
    public function transactional(callable $work): mixed;
}
```

The constructor accepts a nullable dispatcher because the in-memory
storage used in tests reuses the same SQLite implementation under the
hood; tests can opt out of event emission.

---

## SchemaManager

`SchemaManager` tracks which migrations have been applied (in a
`schema_version` table) and runs anything pending. `DefaultBootstrap`
calls `migrate()` on the first PDO resolve, so by the time you get a
container service the schema is current.

```php
namespace Imanager\Storage;

final class SchemaManager
{
    /**
     * @param iterable<Migration> $migrations
     */
    public function __construct(\PDO $connection, iterable $migrations);

    public function currentVersion(): int;
    public function pending(): array;   // list<Migration>
    public function migrate(): int;     // number of migrations applied
}
```

```php
namespace Imanager\Storage;

interface Migration
{
    public function version(): int;
    public function description(): string;
    public function apply(\PDO $connection): void;
}
```

The bundled migrations under `config/schema/` are:

| Version | File | What it does |
|---|---|---|
| `0001` | `0001_initial.php` | `categories`, `fields`, `items`, `schema_version`. |
| `0002` | `0002_fts.php` | FTS5 mirror over `items` + sync triggers. |
| `0003` | `0003_files.php` | `files` table with item / field foreign keys. |
| `0004` | `0004_files_title.php` | `files.title` column for human captions. |

Migrations are applied in `version()` order. `currentVersion()` is
the **highest** applied version. `pending()` enumerates everything
above that. `migrate()` returns the count of migrations actually
applied this call — `0` is normal on a hot start.

---

## Exception hierarchy

All storage exceptions implement the marker interface
`Imanager\Exception\ImanagerException`. You can catch any specific
type, or catch the marker if you want a single net:

- `NotFoundException` — repository can't locate the requested row
  (delete of nothing, save of unknown id, save against unknown
  category).
- `ValidationException` — value rejected by a field-type plugin, or
  a uniqueness constraint failed. Carries the field name and
  `InputErrorCode` when raised by a plugin.
- `StorageException` — any other SQLite-layer failure (constraint
  violation we didn't anticipate, transient I/O). Wraps the
  underlying `PDOException` as `$previous`.
- `SchemaException` — `SchemaManager` could not parse or apply a
  migration.

---

## Related

- The values the repositories accept and return: [Domain](domain.md).
- Predicate-driven reads: [Query](query.md).
- Plugins that decide what `Item::$data` values are valid:
  [Field types](field-types.md).
