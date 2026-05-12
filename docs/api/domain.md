# Domain

`Imanager\Domain` defines the four primitives the rest of the library
operates on. They are deliberately small `final readonly` value objects
— no setters, no behaviour beyond simple `with*()` helpers. Mutation
happens at the repository layer; the domain itself is just data.

> Source: `src/Domain/{Category,Field,Item,File,FieldValueBag}.php`
> and `src/Domain/Event/`.

---

## Category

A kind of thing — `Blog`, `Page`, `User`. Each category owns its own
field schema and its own slug. Categories are uniquely identified by
both `name` and `slug` within an install (the storage layer rejects
duplicates on either column).

```php
namespace Imanager\Domain;

final readonly class Category
{
    public function __construct(
        public ?int $id,
        public string $name,
        public string $slug,
        public int $position = 0,
        public int $created = 0,
        public int $updated = 0,
    ) {}

    public function withId(int $id): self;
}
```

### Lifecycle

- `id === null` — a fresh value object that has not been persisted.
  Pass to `CategoryRepository::save()` to get back a clone with `id`
  assigned and `created`/`updated` populated.
- `id !== null` — an already-persisted record. Saving again **updates**
  that row; the repository throws `NotFoundException` if the row no
  longer exists.

### Example

```php
$blog = $categories->save(new Category(null, 'Blog', 'blog'));
// $blog->id is now an int; $blog->created/$updated are unix timestamps.
```

---

## Field

A typed column on a category. Every item in the category may carry a
value for every defined field. Fields are uniquely identified by
`(categoryId, name)` — the same `name` may exist in different
categories.

```php
namespace Imanager\Domain;

final readonly class Field
{
    public function __construct(
        public ?int $id,
        public int $categoryId,
        public string $name,
        public ?string $label,
        public FieldType $type,
        public int $position = 0,
        public bool $required = false,
        public bool $indexed = false,
        public bool $searchable = false,
        public array $config = [],
        public int $created = 0,
        public int $updated = 0,
    ) {}

    public function withId(int $id): self;
}
```

### Flags

| Flag | Meaning |
|---|---|
| `required` | The field-type plugin's `validate()` will reject empty values. |
| `indexed` | The field is promoted to a SQLite generated column for fast equality / range queries (see [Storage](storage.md#hot-fields--generated-columns)). |
| `searchable` | The field's value is included in the FTS5 index used by `Imanager\Search\FullTextSearch`. |

### `config`

An untyped `array<string,mixed>` whose shape is defined per
field-type plugin (see [Field types](field-types.md)). Examples:

- `TextFieldType`: `['max' => 255]`
- `DropdownFieldType`: `['options' => ['a' => 'Apple', 'b' => 'Banana']]`
- `ImageuploadFieldType`: `['maxBytes' => 5_000_000, 'mimes' => ['image/jpeg', 'image/png']]`

The repository round-trips `config` verbatim — it's the plugin's job
to make sense of its own shape.

### Example

```php
$titleField = $fields->save(
    new Field(null, $blog->id, 'title', 'Title', FieldType::Text),
);
```

---

## Item

An instance of a category. Field values live in a typed
`FieldValueBag` exposed on `$item->data`. The bag is the *whole*
payload; "hot" fields (those declared `indexed` on the schema) are
copied into SQLite generated columns on save and kept in sync
automatically — you don't write to them separately.

```php
namespace Imanager\Domain;

final readonly class Item
{
    public FieldValueBag $data;

    public function __construct(
        public ?int $id,
        public int $categoryId,
        public ?string $name = null,
        public ?string $label = null,
        public int $position = 0,
        public bool $active = true,
        FieldValueBag|array $data = [],
        public int $created = 0,
        public int $updated = 0,
    ) {}

    public function withId(int $id): self;
}
```

### Why `data` is a constructor parameter but a public property

The constructor accepts either an `array` or a `FieldValueBag` for
ergonomics: most callers build items from arrays
(`data: ['title' => 'Hello']`). Internally it's always coerced to a
`FieldValueBag` — that's what you read back from `$item->data`.

### Example

```php
$post = $items->save(new Item(
    null,
    $blog->id,
    'first-post',
    'First Post',
    data: ['title' => 'Hello', 'body' => 'World'],
));

echo $post->data->get('title');  // 'Hello'
```

---

## FieldValueBag

A typed bag of field-name → value pairs. Immutable; the `with*()` /
`without()` / `merge()` methods all return a new bag.

```php
namespace Imanager\Domain;

final readonly class FieldValueBag
{
    public function __construct(public array $values = []) {}

    public function has(string $field): bool;
    public function get(string $field, mixed $default = null): mixed;
    public function with(string $field, mixed $value): self;
    public function without(string $field): self;
    public function merge(self|array $other): self;
    public function toArray(): array;
    public function isEmpty(): bool;
    public function count(): int;
}
```

The bag does **not** validate values against the field schema — that
happens during `ItemRepository::save()`, which routes each value
through the relevant `FieldTypePlugin::validate()`. The bag is
storage; the plugin is the typing rule.

### Example

```php
$item   = $item->data->with('title', 'Updated title');
$merged = $item->data->merge(['body' => 'New body', 'active' => true]);
```

---

## File

Metadata for a binary asset attached to an item via a `fileupload`
or `imageupload` field. The actual bytes live under the
`FileStorage` mount (`data/uploads-2.0/<itemId>/<fieldId>/` by
default).

```php
namespace Imanager\Domain;

final readonly class File
{
    public function __construct(
        public ?int $id,
        public int $itemId,
        public int $fieldId,
        public string $name,
        public string $path,
        public string $mime,
        public int $size,
        public int $width = 0,
        public int $height = 0,
        public int $position = 0,
        public int $created = 0,
        public string $title = '',
    ) {}

    public function withId(int $id): self;
    public function withTitle(string $title): self;
    public function withPosition(int $position): self;
    public function isImage(): bool;
}
```

### `path`

A storage-relative path (e.g. `42/7/photo.jpg`), **not** an absolute
URL. Resolve to a URL via `$uploadsUrl` from your `DefaultBootstrap`
call.

### `isImage()`

Convenience that checks the MIME prefix; thumbnail generation in
`ImageProcessor` only runs for files where this returns `true`.

---

## Domain events

Every successful repository mutation publishes one of nine events
through the PSR-14 dispatcher wired in `DefaultBootstrap`. Subscribers
receive the event *after* the SQLite transaction has committed — a
listener that throws does **not** roll back the write.

All events implement the marker interface `DomainEvent`:

```php
namespace Imanager\Domain\Event;

interface DomainEvent
{
    public function occurredAt(): int;
}
```

### Created events

```php
final readonly class CategoryCreated implements DomainEvent
{
    public function __construct(public Category $category, public int $occurredAt) {}
}

final readonly class FieldCreated implements DomainEvent
{
    public function __construct(public Field $field, public int $occurredAt) {}
}

final readonly class ItemCreated implements DomainEvent
{
    public function __construct(public Item $item, public int $occurredAt) {}
}
```

### Updated events

Updated events carry **both** the previous and the current value, so
listeners can diff:

```php
final readonly class CategoryUpdated implements DomainEvent
{
    public function __construct(
        public Category $previous,
        public Category $current,
        public int $occurredAt,
    ) {}
}

final readonly class FieldUpdated implements DomainEvent
{
    public function __construct(
        public Field $previous,
        public Field $current,
        public int $occurredAt,
    ) {}
}

final readonly class ItemUpdated implements DomainEvent
{
    public function __construct(
        public Item $previous,
        public Item $current,
        public int $occurredAt,
    ) {}
}
```

### Deleted events

Deleted events only carry the *id* (and category id for fields/items)
— by the time the event fires, the row is gone:

```php
final readonly class CategoryDeleted implements DomainEvent
{
    public function __construct(public int $categoryId, public int $occurredAt) {}
}

final readonly class FieldDeleted implements DomainEvent
{
    public function __construct(
        public int $fieldId,
        public int $categoryId,
        public string $name,
        public int $occurredAt,
    ) {}
}

final readonly class ItemDeleted implements DomainEvent
{
    public function __construct(
        public int $itemId,
        public int $categoryId,
        public int $occurredAt,
    ) {}
}
```

`FileCreated` / `FileUpdated` / `FileDeleted` are deliberately
**not** emitted today — file metadata moves with item state and the
repository contract for files is intentionally narrower.

### Subscribing

`DefaultBootstrap` registers a `SubscriberListenerProvider` you can
fetch from the container:

```php
use Imanager\Domain\Event\ItemDeleted;
use Imanager\Events\SubscriberListenerProvider;

$provider = $container->get(SubscriberListenerProvider::class);

$provider->subscribe(ItemDeleted::class, function (ItemDeleted $event): void {
    // e.g. clear a cache, delete sidecar files
});
```

Listener instantiation can be lazy — wrap the closure body in a
`static $listener = null` guard if construction is expensive
(Scriptor's `ImanagerBootstrap` does this for `PageCacheInvalidationListener`).

---

## Related

- The repositories that produce and consume these objects: [Storage](storage.md).
- The plugins that validate `Item::$data` values: [Field types](field-types.md).
- The `Query` builder that filters items by their domain shape: [Query](query.md).
