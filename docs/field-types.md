# Field types — cookbook

This guide is the **how-to** companion to the
[Field-types reference](api/field-types.md). The reference catalogues
the interface, the registry, and the 16 built-ins. This guide walks
through writing your own plugin end-to-end: anatomy, validation
patterns, rendering, registration, and tests.

If you only need API signatures, go read the reference. If you're
writing a new field type, start here.

> **Prerequisites.** You have iManager 2.0 booted via
> `Imanager\DefaultBootstrap::boot()` (see
> [README §Quickstart](../README.md#quickstart)). You know what a
> `Category` and a `Field` are (see
> [API > Domain](api/domain.md)). All examples below are tested
> against the real plugin contract — copy them, they compile.

---

## 1. Anatomy of a field type

Every field type is a class implementing
`Imanager\Field\FieldTypePlugin`. The smallest useful one looks like
this:

```php
<?php
declare(strict_types=1);

namespace App\Field;

use Imanager\Domain\Field;
use Imanager\Enum\InputErrorCode;
use Imanager\Enum\SqliteAffinity;
use Imanager\Field\FieldTypePlugin;
use Imanager\Field\RenderContext;
use Imanager\Field\ValidationResult;
use Imanager\Validation\Sanitizer;

final readonly class ColourFieldType implements FieldTypePlugin
{
    public function __construct(private Sanitizer $sanitizer) {}

    public static function name(): string
    {
        return 'colour';
    }

    public static function affinity(): SqliteAffinity
    {
        return SqliteAffinity::Text;
    }

    public function defaultConfig(): array
    {
        return [];
    }

    public function validate(mixed $rawValue, Field $field): ValidationResult
    {
        if ($rawValue === null || $rawValue === '') {
            return $field->required
                ? ValidationResult::failed(InputErrorCode::EmptyRequired)
                : ValidationResult::ok(null);
        }
        if (! \is_string($rawValue) || ! preg_match('/^#[0-9a-f]{6}$/i', $rawValue)) {
            return ValidationResult::failed(InputErrorCode::WrongValueFormat);
        }
        return ValidationResult::ok(strtolower($rawValue));
    }

    public function render(mixed $value, Field $field, RenderContext $context): string
    {
        $name = $this->sanitizer->entities($context->inputName);
        $current = $this->sanitizer->entities((string) ($value ?? ''));
        $required = $field->required ? ' required' : '';

        return \sprintf(
            '<input type="color" name="%s" value="%s"%s>',
            $name, $current, $required,
        );
    }
}
```

That class is a complete, registerable field type. The rest of this
guide is patterns and tradeoffs you'll meet when you write more
sophisticated ones.

### Five things to internalise

1. **Static methods identify the plugin.** `name()` and `affinity()`
   are static so the registry can resolve them without instantiating
   the plugin. The name must be unique across the registry; the
   affinity is the SQLite storage class the value uses if the field
   is ever flipped to `indexed = true`.
2. **Plugins are constructor-injected services.** They are NOT pure
   value objects. The built-ins take `Sanitizer` so they can escape
   HTML attributes and coerce strings; your custom types can take
   whatever they need (a `CategoryRepository`, a `LoggerInterface`,
   the `EventDispatcherInterface`).
3. **`defaultConfig()` is the single source of truth for config
   shape.** Always merge against it inside `validate()` and
   `render()`:

   ```php
   $config = [...$this->defaultConfig(), ...$field->config];
   ```

   Don't read raw `$field->config` keys directly — a missing key
   becomes a silent `null` and surprises someone down the road.
4. **`validate()` returns a `ValidationResult`, not an exception.**
   Even for catastrophic input. The repository turns failed results
   into a `ValidationException` at save-time with the field name
   attached.
5. **`render()` must produce HTML safe to drop in any `<form>`.**
   Escape every dynamic value through the injected `Sanitizer`. Do
   not assume the host editor is escaping for you.

---

## 2. The validation pipeline

`ItemRepository::save()` calls `validate()` once per field declared
on the item's category. The flow inside your plugin should follow
the same skeleton the built-ins use:

```php
public function validate(mixed $rawValue, Field $field): ValidationResult
{
    // (1) Empty / null handling — including the required check.
    if ($this->isEmpty($rawValue)) {
        return $field->required
            ? ValidationResult::failed(InputErrorCode::EmptyRequired)
            : ValidationResult::ok($this->emptyValue());
    }

    // (2) Format / shape check — refuse early, before coercion swallows.
    if (! $this->looksValid($rawValue)) {
        return ValidationResult::failed(InputErrorCode::WrongValueFormat);
    }

    // (3) Merge config, evaluate constraints (min/max, options, regex).
    $config = [...$this->defaultConfig(), ...$field->config];
    if (! $this->satisfiesConstraints($rawValue, $config)) {
        return ValidationResult::failed(/* a more specific code */);
    }

    // (4) Coerce to canonical domain shape.
    $coerced = $this->coerce($rawValue, $config);

    return ValidationResult::ok($coerced);
}
```

### Error codes (`InputErrorCode`)

The shared error vocabulary across all plugins. The numeric values
are stable — old data that persisted them as integers still rounds
back to the right case.

| Case | When to use |
|---|---|
| `EmptyRequired` | Empty value on a `required` field. The check fires *first*. |
| `MinLengthExceeded` | String value is shorter than `config.minLength` (or analogous lower bound). |
| `MaxLengthExceeded` | String value is longer than `config.maxLength`. |
| `WrongValueFormat` | Value's shape is wrong: dropdown key not in `options`, integer field got a non-numeric string, date string doesn't parse. |
| `ComparisonFailed` | Cross-value or range comparison failed (numeric `min`/`max`, date ordering, password-confirmation mismatch). |
| `UndefinedCategoryId` | The `filepicker` (or any reference-typed) field targets a category id that doesn't exist. |

If none of these fit the failure your plugin is reporting, look
twice — most "new" cases collapse into `WrongValueFormat`. Resist
the urge to add more codes; the host editor expects this exact set
when mapping failures to localised messages.

### When to return `ok(null)`

Three of the built-ins distinguish "empty but allowed" from "absent":

- **`IntegerFieldType`**: empty string on a non-required field
  rounds to `null`, so the column stays NULL rather than 0.
- **`DropdownFieldType`**: no-selection on a non-required field
  rounds to `null`.
- **`DatepickerFieldType`**: empty date on a non-required field
  rounds to `null`.

Pick the same rule for your own plugin if "no value" is a real,
distinguishable state. For string-typed fields the built-ins use
`''` instead — empty string is the natural absence in SQLite text
storage.

### What the `Sanitizer` actually does

The injected `Imanager\Validation\Sanitizer` is a thin facade. The
relevant calls inside `validate()` are:

| Call | What it does |
|---|---|
| `$this->sanitizer->text($s, $maxLength)` | Trims, collapses whitespace, truncates to `$maxLength` chars. Used by `TextFieldType`. |
| `$this->sanitizer->int($value, $min, $max)` | Coerces numeric/bool to int; clamps to `[$min, $max]`. |
| `$this->sanitizer->entities($s)` | HTML-encodes; **render-time only**, never inside `validate()`. |
| `$this->sanitizer->markdown($s)` / `->purify($s)` | Markdown render / HTMLPurifier — used by `LongText` / `Editor` plugins. |

If you find yourself reimplementing `htmlspecialchars()` or
`trim()` inside a plugin, you're skipping the `Sanitizer` — call
it instead. It exists so the host can swap the escaping backend
once and have every plugin pick it up.

---

## 3. Render patterns

`render()` is the consumer-facing HTML for editing a value. It is
called only by host editor UIs — your application's read templates
should pull `$item->data->get($name)` and render their own HTML.

### Pattern A — simple input element

```php
public function render(mixed $value, Field $field, RenderContext $context): string
{
    $name = $this->sanitizer->entities($context->inputName);
    $valueAttr = $this->sanitizer->entities((string) ($value ?? ''));
    $required = $field->required ? ' required' : '';

    return \sprintf(
        '<input type="text" name="%s" value="%s"%s>',
        $name, $valueAttr, $required,
    );
}
```

Three rules cover 80 % of cases:
- Escape `$context->inputName` and every dynamic value through
  `entities()`.
- Reflect `$field->required` as the HTML `required` attribute when
  the browser-side enforcement helps.
- Use named args via `sprintf()` rather than string concatenation —
  the templates stay legible and the escaping order is unmistakable.

### Pattern B — collections (dropdown / radio group)

When the choices come from `config`, normalise the array shape
defensively before iterating — `config` is untyped, and a host
editor may put junk in there:

```php
private function options(Field $field): array
{
    $config = [...$this->defaultConfig(), ...$field->config];
    $options = $config['options'] ?? [];
    if (! \is_array($options)) {
        return [];
    }
    $clean = [];
    foreach ($options as $key => $label) {
        $clean[(string) $key] = (string) $label;
    }
    return $clean;
}
```

The built-in `DropdownFieldType` ships exactly this guard. Use it
as a starting point for any plugin whose config carries a list.

### Pattern C — file inputs

If you handle uploads, render a plain HTML file input — the bytes
themselves flow through `FileStorage`, not through the form value:

```php
return \sprintf(
    '<input type="file" name="%s[]" accept="%s" multiple data-field="%s">',
    $this->sanitizer->entities($context->inputName),
    $this->sanitizer->entities($acceptedMimes),
    $this::name(),
);
```

The `data-field` attribute exists so host editors can attach
JavaScript by field type without parsing class names. The built-in
`ImageuploadFieldType` uses `data-field="imageupload"`.

### Pattern D — host-driven render

For complex inputs (rich-text editors, custom date pickers, asset
pickers) you usually only emit a placeholder element and a hidden
input — the host editor's JS picks it up by `data-field` and
augments it. Keep the placeholder dumb; let the host edit-mode JS
own the interactive surface.

### What `$context` gives you

`Imanager\Field\RenderContext` is deliberately tiny:

| Property | Use |
|---|---|
| `$inputName` | What goes in the form-field's `name=""`. The host editor decides this (often `"data[<fieldname>]"`). |
| `$itemId` | `null` for "create" forms, the item id for "edit" forms. Useful for file-typed plugins that render an existing-attachment list. |

Anything you need beyond that — CSRF tokens, asset base URLs,
locale, current user — comes through your plugin's **constructor**,
not through `RenderContext`. Resist the urge to grow the context
object; that surface is shared by every plugin in the system.

---

## 4. End-to-end: a money-with-currency field

A worked example bigger than the colour picker. Stores an integer
amount (minor units) plus an ISO 4217 currency code in a single
JSON object. `defaultConfig()` declares the allowed currencies; the
validator enforces the shape; the renderer emits a number + select
pair.

### 4.1 Domain shape

The canonical stored value is:

```php
['amount' => 1299, 'currency' => 'EUR']  // €12.99
```

Empty / non-required selections round to `null`. The amount is
always an integer in minor units (cents) — never a float — to dodge
floating-point rounding.

### 4.2 The plugin

```php
<?php
declare(strict_types=1);

namespace App\Field;

use Imanager\Domain\Field;
use Imanager\Enum\InputErrorCode;
use Imanager\Enum\SqliteAffinity;
use Imanager\Field\FieldTypePlugin;
use Imanager\Field\RenderContext;
use Imanager\Field\ValidationResult;
use Imanager\Validation\Sanitizer;

final readonly class MoneyWithCurrencyFieldType implements FieldTypePlugin
{
    public function __construct(private Sanitizer $sanitizer) {}

    public static function name(): string
    {
        return 'money_with_currency';
    }

    public static function affinity(): SqliteAffinity
    {
        // JSON blob — not numerically queryable as a hot column.
        return SqliteAffinity::Text;
    }

    public function defaultConfig(): array
    {
        return [
            'currencies' => ['EUR', 'USD', 'GBP', 'CHF'],
            'min'        => 0,
            'max'        => null,
        ];
    }

    public function validate(mixed $rawValue, Field $field): ValidationResult
    {
        $config = [...$this->defaultConfig(), ...$field->config];

        // Empty / not-supplied
        if ($rawValue === null || $rawValue === '' || $rawValue === []) {
            return $field->required
                ? ValidationResult::failed(InputErrorCode::EmptyRequired)
                : ValidationResult::ok(null);
        }

        // Shape: must be an array with both keys
        if (! \is_array($rawValue)
            || ! isset($rawValue['amount'], $rawValue['currency'])
        ) {
            return ValidationResult::failed(InputErrorCode::WrongValueFormat);
        }

        // Currency must be one of the configured ones
        $currency = (string) $rawValue['currency'];
        $currencies = \is_array($config['currencies']) ? $config['currencies'] : [];
        if (! \in_array($currency, $currencies, true)) {
            return ValidationResult::failed(InputErrorCode::WrongValueFormat);
        }

        // Amount must be numeric
        if (! is_numeric($rawValue['amount'])) {
            return ValidationResult::failed(InputErrorCode::WrongValueFormat);
        }

        // Range
        $min = \is_int($config['min']) ? $config['min'] : null;
        $max = \is_int($config['max']) ? $config['max'] : null;
        $amount = $this->sanitizer->int($rawValue['amount'], $min, $max);

        // Detect a clamp — if the user passed something out of range, fail
        // rather than silently clipping (this is a financial field).
        $raw = (int) $rawValue['amount'];
        if ($min !== null && $raw < $min) {
            return ValidationResult::failed(InputErrorCode::ComparisonFailed);
        }
        if ($max !== null && $raw > $max) {
            return ValidationResult::failed(InputErrorCode::ComparisonFailed);
        }

        return ValidationResult::ok([
            'amount'   => $amount,
            'currency' => $currency,
        ]);
    }

    public function render(mixed $value, Field $field, RenderContext $context): string
    {
        $config = [...$this->defaultConfig(), ...$field->config];
        $currencies = \is_array($config['currencies']) ? $config['currencies'] : [];

        $amount   = (\is_array($value) && isset($value['amount']))   ? (int) $value['amount']      : 0;
        $current  = (\is_array($value) && isset($value['currency'])) ? (string) $value['currency'] : '';

        $name = $this->sanitizer->entities($context->inputName);
        $required = $field->required ? ' required' : '';

        $options = '';
        foreach ($currencies as $code) {
            $codeStr = (string) $code;
            $options .= \sprintf(
                '<option value="%s"%s>%s</option>',
                $this->sanitizer->entities($codeStr),
                $codeStr === $current ? ' selected' : '',
                $this->sanitizer->entities($codeStr),
            );
        }

        return \sprintf(
            '<input type="number" name="%1$s[amount]" value="%2$d" step="1"%3$s>'
            . '<select name="%1$s[currency]"%3$s>%4$s</select>',
            $name, $amount, $required, $options,
        );
    }
}
```

### 4.3 Defining the field

Once the plugin is registered (next section), create a `Field` of
this type just like any other:

```php
$fields = $container->get(\Imanager\Storage\FieldRepository::class);

// Custom plugins are addressed by their string name() — the FieldType
// enum only carries the 16 built-ins. Pass the string through Field::type
// as if it were an enum case; the SQLite layer doesn't care which.
$fields->save(new \Imanager\Domain\Field(
    id: null,
    categoryId: $blog->id,
    name: 'price',
    label: 'Price',
    type: \Imanager\Enum\FieldType::Text, // see "Storing custom types" below
    required: true,
    config: ['currencies' => ['EUR', 'USD']],
));
```

> **Storing custom types.** The `fields.type` column is a TEXT
> column whose value is the plugin's `name()`. The `FieldType` enum
> exists for ergonomics on the built-ins — when you create a `Field`
> for a custom type, set `type` to the closest-fitting `FieldType`
> case (here `Text`) and treat the plugin's string name as
> authoritative at the validate/render boundary. If you want
> first-class custom-type support without the proxy enum, save a
> migration that broadens `fields.type` to accept your name and
> point your plugin's `name()` at it.

### 4.4 What's left out

Real-world money handling needs currency conversion, locale-aware
display formatting, and zero-decimal-currency support (JPY has no
minor units, KWD has three). All of that belongs in your domain
service, not in the field-type plugin — the plugin's job is to
**validate and coerce the raw form value into your storage shape**,
nothing more.

---

## 5. Registering a custom plugin

`DefaultBootstrap` registers all 16 built-ins automatically. To add
a custom type, fetch the registry and call `register()`:

```php
use Imanager\Field\FieldTypeRegistry;

$registry = $container->get(FieldTypeRegistry::class);
$registry->register(new App\Field\MoneyWithCurrencyFieldType(
    $container->get(\Imanager\Validation\Sanitizer::class),
));
```

A few details worth knowing:

- **`register()` is idempotent-by-name.** Registering a plugin whose
  `name()` matches an already-registered plugin **overwrites** the
  earlier one. This is intentional — it's the supported way to swap
  a built-in for a customised variant. Just don't do it by accident.
- **Order matters for `names()`.** `FieldTypeRegistry::names()`
  returns names in registration order. Host editor "type" dropdowns
  often use this list, so register your plugins where you want them
  to appear in the UI.
- **Booting your own container?** If you skip `DefaultBootstrap` and
  build a leaner container, you have to register the 16 built-ins
  yourself. Take a look at `DefaultBootstrap::registerFieldTypes()`
  in the iManager source for the canonical list.

### Where to register in a real app

The typical shape is: host bootstrap calls
`DefaultBootstrap::boot(...)`, then immediately runs a small
"register custom plugins" function that picks up registry + services
from the container:

```php
$container = DefaultBootstrap::boot(/* … */);

(function () use ($container): void {
    $registry  = $container->get(FieldTypeRegistry::class);
    $sanitizer = $container->get(\Imanager\Validation\Sanitizer::class);

    $registry->register(new App\Field\MoneyWithCurrencyFieldType($sanitizer));
    $registry->register(new App\Field\ColourFieldType($sanitizer));
    // …
})();
```

Keep this function out of the request hot path — it runs once per
container construction and that's all.

---

## 6. Testing your plugin

Unit-test the plugin in isolation against a real `Sanitizer` and a
hand-built `Field`. The built-in tests under
`tests/Unit/Field/Types/` are the canonical examples — copy their
shape.

```php
<?php
declare(strict_types=1);

namespace App\Tests\Field;

use App\Field\ColourFieldType;
use Imanager\Domain\Field;
use Imanager\Enum\FieldType;
use Imanager\Enum\InputErrorCode;
use Imanager\Field\RenderContext;
use Imanager\Validation\Sanitizer;
use PHPUnit\Framework\TestCase;

final class ColourFieldTypeTest extends TestCase
{
    private ColourFieldType $plugin;

    protected function setUp(): void
    {
        $this->plugin = new ColourFieldType(new Sanitizer());
    }

    public function testValidateAcceptsLowercasedHex(): void
    {
        $result = $this->plugin->validate('#FF00aa', $this->field());

        self::assertTrue($result->isValid);
        self::assertSame('#ff00aa', $result->coerced);
    }

    public function testValidateRejectsBadFormat(): void
    {
        $result = $this->plugin->validate('not a colour', $this->field());

        self::assertFalse($result->isValid);
        self::assertSame(InputErrorCode::WrongValueFormat, $result->errorCode);
    }

    public function testValidateRequiredRejectsEmpty(): void
    {
        $result = $this->plugin->validate('', $this->field(required: true));

        self::assertFalse($result->isValid);
        self::assertSame(InputErrorCode::EmptyRequired, $result->errorCode);
    }

    public function testRenderEscapesInputName(): void
    {
        $html = $this->plugin->render(
            '#abcdef',
            $this->field(),
            new RenderContext('data[<bad>]'),
        );

        self::assertStringContainsString('name="data[&lt;bad&gt;]"', $html);
        self::assertStringContainsString('value="#abcdef"', $html);
    }

    private function field(bool $required = false): Field
    {
        return new Field(
            id: 1,
            categoryId: 1,
            name: 'colour',
            label: 'Colour',
            type: FieldType::Text,
            required: $required,
        );
    }
}
```

Three habits that pay off:

- **Test through the public methods only.** `validate()` and
  `render()` are your contract; everything else is internal.
- **Use a real `Sanitizer`, not a mock.** The built-in Sanitizer is
  a pure-function facade — mocking it hides bugs in escaping that
  only show up when something special-cases a quote.
- **Assert on the `errorCode` enum, not the message.** Messages
  drift; codes are the host editor's stable mapping target.

For an integration-level test (plugin + repository + SQLite),
register the plugin on a `DefaultBootstrap`-built container and
exercise `ItemRepository::save()` end-to-end. The pattern is in
`tests/Unit/DefaultBootstrapTest.php`.

---

## 7. Common pitfalls

### 7.1 Reading `$field->config` directly

```php
// WRONG — silent null if the key is missing
$max = $field->config['maxLength'];
```

Always merge against `defaultConfig()` first:

```php
$config = [...$this->defaultConfig(), ...$field->config];
$max = (int) ($config['maxLength'] ?? 255);
```

### 7.2 Throwing from `validate()`

Don't. The repository catches `ValidationResult::failed(...)` and
turns it into a `ValidationException` for you. Throwing your own
exception breaks the host editor's error-mapping flow.

### 7.3 Escaping at the wrong layer

`Sanitizer::entities()` belongs in `render()`. `Sanitizer::text()` /
`->int()` belong in `validate()`. Mixing them is the most common
source of double-escaped values in form output.

### 7.4 Trusting `$field->required` for "must be present in DB"

`required` is a *form-validation* flag — it rejects empty values
when an item is saved through the normal API. It does **not** add a
`NOT NULL` constraint to the generated column. Items inserted by
data migration, by direct SQL, or with `$item->data` missing the key
entirely will not trip the check.

### 7.5 Forgetting to return ok/failed on every code path

The compiler doesn't enforce it. Add an assertion in tests:

```php
self::assertNotNull($result, 'validate() must return a ValidationResult');
```

Or: just write the tests. The built-ins each ship 8–12 test methods
covering every branch — that's the bar.

---

## 8. Where to look in the source

When in doubt, the source is short and well-named:

- `src/Field/Types/TextFieldType.php` — simplest validation +
  render shape.
- `src/Field/Types/DropdownFieldType.php` — typed-config list,
  defensive iteration.
- `src/Field/Types/IntegerFieldType.php` — numeric coercion with
  clamping vs strict-fail.
- `src/Field/Types/SlugFieldType.php` — multi-step coercion
  (truncate → slugify → uniqueness suffix).
- `src/Field/Types/ImageuploadFieldType.php` — file-typed plugin
  surface and `data-field=` rendering for host JS hooks.
- `src/Field/FieldTypeRegistry.php` — registry mechanics.
- `src/Field/ValidationResult.php` — the `ok` / `failed` named
  constructors.

The reference page at [API > Field types](api/field-types.md) is
the index of all of the above.
