# Field types

Field types are the plugin layer that decides:

1. How an incoming raw value (form submit, API payload, importer
   row) is **coerced and validated** into a domain value.
2. What **SQLite affinity** the value uses if it's promoted to a hot
   column.
3. How the value is **rendered** as an HTML form input — for hosts
   that lean on the library's rendering for their editor UI.

This page is the **reference**: enum cases, the plugin interface,
the registry, and a table of the built-in plugins. A step-by-step
cookbook for writing your own type will land in
`docs/field-types.md` later in Phase 16.

> Source: `src/Field/{FieldTypePlugin,FieldTypeRegistry,ValidationResult,RenderContext}.php`,
> `src/Field/Types/`, `src/Enum/FieldType.php`, `src/Enum/SqliteAffinity.php`,
> `src/Enum/InputErrorCode.php`.

---

## `FieldType` enum

The 16 case names every install knows out of the box. The `value` of
each case is also the string used in storage and in the registry — a
field's `type` is stored as this string in the `fields` table.

```php
namespace Imanager\Enum;

enum FieldType: string
{
    case Text        = 'text';
    case LongText    = 'longtext';
    case Editor      = 'editor';
    case Slug        = 'slug';
    case Datepicker  = 'datepicker';
    case Dropdown    = 'dropdown';
    case Checkbox    = 'checkbox';
    case Integer     = 'integer';
    case Decimal     = 'decimal';
    case Money       = 'money';
    case Password    = 'password';
    case Hidden      = 'hidden';
    case ArrayList   = 'array';
    case Filepicker  = 'filepicker';
    case Fileupload  = 'fileupload';
    case Imageupload = 'imageupload';

    public function sqliteAffinity(): SqliteAffinity;
}
```

`sqliteAffinity()` returns one of `Text` / `Integer` / `Real` /
`Blob`. The result is consumed by the schema generator when a
field is `indexed = true` — the generated column's type is derived
from this value.

---

## `FieldTypePlugin` interface

The single contract every type implements. Static methods identify
the plugin; instance methods do the work.

```php
namespace Imanager\Field;

use Imanager\Domain\Field;
use Imanager\Enum\SqliteAffinity;

interface FieldTypePlugin
{
    public static function name(): string;
    public static function affinity(): SqliteAffinity;

    /** @return array<string, mixed> */
    public function defaultConfig(): array;

    public function validate(mixed $rawValue, Field $field): ValidationResult;
    public function render(mixed $value, Field $field, RenderContext $context): string;
}
```

### `name()` / `affinity()`

Static because the registry needs to resolve them without
instantiating the plugin. The `name()` is what the registry keys on
— it **must** equal the `FieldType` enum value for built-ins, and
should be a unique slug-like string for custom plugins. The
`affinity()` mirrors `FieldType::sqliteAffinity()` for the same
reason — schema codegen needs it before any instance exists.

### `defaultConfig()`

Returns the field's `config` array when a host (typically an editor
UI) creates a new field of this type. The shape is plugin-specific —
the library never inspects it. Common patterns:

| Plugin | `defaultConfig()` keys |
|---|---|
| `TextFieldType` | `min`, `max`, `pattern` |
| `LongTextFieldType` | `max`, `format` (`'plain'` / `'markdown'`) |
| `DropdownFieldType` | `options` (`array<string,string>`), `multiple` (`bool`) |
| `IntegerFieldType` | `min`, `max` |
| `MoneyFieldType` | `currency`, `precision` |
| `ImageuploadFieldType` | `maxBytes`, `mimes`, `maxWidth`, `maxHeight` |
| `FileuploadFieldType` | `maxBytes`, `mimes`, `multiple` |
| `FilepickerFieldType` | `categoryId` (target items), `searchable` |

### `validate()`

The plugin's job is to **either accept and coerce** a raw value into
the canonical domain form, **or refuse** with a specific error code:

```php
public function validate(mixed $rawValue, Field $field): ValidationResult;
```

`$rawValue` is whatever the caller passed — a string, an int, an
array, `null`, `false`. The plugin decides what it accepts. Return
`ValidationResult::ok($coerced)` with the canonical value, or
`ValidationResult::failed(InputErrorCode::*, $message)` to refuse.

`ItemRepository::save()` calls `validate()` for every value in
`$item->data` before writing — a failure raises `ValidationException`
carrying the field name, code, and message.

### `render()`

Returns the HTML for editing this value in a form. The base
contract is "return a `<input>` / `<textarea>` / `<select>` snippet
that round-trips through `validate()`". Use `RenderContext::$inputName`
as the form-field name; the snippet must be safe to drop inside any
parent `<form>`.

`render()` is purely an *editor* concern — your application's read
templates touch `$item->data->get($name)` directly and do their own
rendering. If you don't host an editor UI, `render()` can return an
empty string.

---

## `FieldTypeRegistry`

The single source of truth for "which types exist in this install."
`DefaultBootstrap` constructs one, registers every built-in plugin,
and shares it across the container.

```php
namespace Imanager\Field;

final class FieldTypeRegistry
{
    public function register(FieldTypePlugin $plugin): void;
    public function has(FieldType|string $type): bool;
    public function get(FieldType|string $type): FieldTypePlugin;
    public function names(): array;     // list<string>
}
```

- `register()` adds or replaces a plugin by `$plugin::name()`.
- `has()` returns `true` if a plugin is registered under that name.
- `get()` returns the plugin or **throws
  `FieldTypeNotRegisteredException`** if missing.
- `names()` lists registered names (used by tests; convenient for
  building a "type" dropdown in admin UIs).

### Adding a custom type

Register on the existing registry after the container is built:

```php
$registry = $container->get(FieldTypeRegistry::class);
$registry->register(new MyColourPickerFieldType());
```

A custom type appears under whatever `name()` returns — pick
something that won't collide with the 16 built-ins. The registry has
no namespacing.

---

## `ValidationResult`

The shape returned by `FieldTypePlugin::validate()`:

```php
namespace Imanager\Field;

use Imanager\Enum\InputErrorCode;

final readonly class ValidationResult
{
    public bool $isValid;
    public mixed $coerced;
    public ?InputErrorCode $errorCode;
    public string $message;

    public static function ok(mixed $coerced): self;
    public static function failed(InputErrorCode $code, string $message = ''): self;
}
```

Always use the named constructors; the `__construct()` is for the
named constructors' use, not for callers.

### `InputErrorCode`

The error vocabulary every plugin shares — `ItemRepository::save()`
propagates the code through `ValidationException`, and editor UIs
can map codes to localised messages without parsing free-text. The
enum lives at `src/Enum/InputErrorCode.php`; common cases:

- `Required` — value was empty and the field is required.
- `OutOfRange` — numeric or length bounds violated.
- `PatternMismatch` — string regex check failed.
- `InvalidType` — raw value's shape was wrong (e.g. string passed
  where array expected).
- `UnknownOption` — dropdown value isn't in `config.options`.
- `FileTooLarge`, `UnsupportedMime` — file-upload constraints.

---

## `RenderContext`

The context object handed to `FieldTypePlugin::render()`:

```php
namespace Imanager\Field;

final readonly class RenderContext
{
    public function __construct(
        public string $inputName,
        public ?int $itemId = null,
    ) {}
}
```

- `$inputName` — what the form-field's `name=""` attribute must be.
  The hosting editor decides this (often `"data[<fieldname>]"`).
- `$itemId` — `null` for "create" forms, the item id for "edit"
  forms. Useful for file-typed plugins that need to render an
  existing-attachment list.

The context is deliberately tiny — anything more (CSRF token, asset
base URL, current locale) is the *host's* concern. A plugin that
needs more context should accept it via constructor injection when
the host registers it.

---

## Built-in plugins

All 16 plugins live under `src/Field/Types/` and are registered
automatically by `DefaultBootstrap`. The "Affinity" column tells you
what SQLite type the generated column gets if you flip
`indexed = true`.

| Enum case | Class | Affinity | Notes |
|---|---|---|---|
| `Text` | `TextFieldType` | `Text` | Single-line `<input type="text">`. `config.min`, `config.max`, `config.pattern`. |
| `LongText` | `LongTextFieldType` | `Text` | `<textarea>`. `config.format` toggles markdown render via `Sanitizer`. |
| `Editor` | `EditorFieldType` | `Text` | Rich-text — same storage as `LongText`, different render hint for the host editor. |
| `Slug` | `SlugFieldType` | `Text` | Auto-derives from a source field on the same form; rejects collisions in the host. |
| `Datepicker` | `DatepickerFieldType` | `Text` | Stores ISO-8601 date strings. |
| `Dropdown` | `DropdownFieldType` | `Text` | `config.options`, optional `config.multiple` (stores comma-joined / array). |
| `Checkbox` | `CheckboxFieldType` | `Integer` | Stores `0` / `1`. `null` raw values coerce to `0`. |
| `Integer` | `IntegerFieldType` | `Integer` | `config.min`, `config.max`. |
| `Decimal` | `DecimalFieldType` | `Real` | `config.precision` (digits after the point). |
| `Money` | `MoneyFieldType` | `Integer` | Stores **minor units** (cents). `config.currency`. |
| `Password` | `PasswordFieldType` | `Text` | One-way hash on save; never round-trips the cleartext. |
| `Hidden` | `HiddenFieldType` | `Text` | `<input type="hidden">`. No validation beyond `required`. |
| `ArrayList` | `ArrayListFieldType` | `Text` | Stores a list of strings as JSON inside the value. |
| `Filepicker` | `FilepickerFieldType` | `Text` | References *existing* items in another category (e.g. an asset library). `config.categoryId`. |
| `Fileupload` | `FileuploadFieldType` | `Text` | Records `File` rows via `FileRepository`; bytes via `FileStorage`. `config.mimes`, `config.maxBytes`, `config.multiple`. |
| `Imageupload` | `ImageuploadFieldType` | `Text` | Subset of `Fileupload` constrained to image MIMEs; `ImageProcessor` renders on-demand thumbnails. `config.maxWidth`, `config.maxHeight`. |

Each plugin's source file is short (typically 60–120 lines) and is
the authoritative answer for "what exactly does this type accept?"
— if a docstring here ever disagrees with the source, the source
wins.

---

## Writing your own

Sketch of a minimal custom type:

```php
use Imanager\Domain\Field;
use Imanager\Enum\InputErrorCode;
use Imanager\Enum\SqliteAffinity;
use Imanager\Field\FieldTypePlugin;
use Imanager\Field\RenderContext;
use Imanager\Field\ValidationResult;

final class ColourFieldType implements FieldTypePlugin
{
    public static function name(): string { return 'colour'; }
    public static function affinity(): SqliteAffinity { return SqliteAffinity::Text; }

    public function defaultConfig(): array { return []; }

    public function validate(mixed $rawValue, Field $field): ValidationResult
    {
        if (! is_string($rawValue) || ! preg_match('/^#[0-9a-f]{6}$/i', $rawValue)) {
            return ValidationResult::failed(InputErrorCode::PatternMismatch, 'Expected #rrggbb');
        }
        return ValidationResult::ok(strtolower($rawValue));
    }

    public function render(mixed $value, Field $field, RenderContext $context): string
    {
        $name  = htmlspecialchars($context->inputName, \ENT_QUOTES);
        $value = htmlspecialchars((string) ($value ?? ''), \ENT_QUOTES);
        return "<input type=\"color\" name=\"{$name}\" value=\"{$value}\">";
    }
}
```

Register on the container's registry once and the type can be used
on any new `Field` going forward. There's nothing else to wire — the
storage layer reads the type's `name()` from `fields.type` and looks
it up in the registry whenever a value flows through `validate()`.

A full walk-through (host editor wiring, validation patterns,
testing strategies) will land in the `docs/field-types.md` cookbook.

---

## Related

- Where the schema for fields lives: [Storage > FieldRepository](storage.md#fieldrepository).
- The `Item::$data` payload that types validate against: [Domain > Item](domain.md#item).
- How validation failures surface: [Storage > Exception hierarchy](storage.md#exception-hierarchy).
