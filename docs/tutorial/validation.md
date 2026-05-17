# Validate user input before saving

You'll build a contact form (name + email + subject + message),
validate everything a visitor types, collect the errors, and only
hit the database when the whole form is clean. Along the way you'll
learn what iManager validates for you, what it explicitly doesn't,
and how to tell the difference.

## Why this is its own chapter

`ItemRepository::save()` writes whatever you hand it. It does **not**
call the field-type plugins' `validate()` methods on its way through.
The contract is intentional: the repository's job is persistence,
not coercion, so the same `save()` call works for editor-side
mutations (where you trust the payload), for a CLI seed script
(where you constructed the value object yourself), and for
form-handling (where you don't trust anything).

The validation layer is a **separate call** you make explicitly,
through the field-type registry. This chapter shows the canonical
pattern.

## What iManager's `validate()` actually checks

For each built-in field type, `FieldTypePlugin::validate()` does
three jobs in one call:

1. **Coerces** the raw input into the canonical storage shape
   (`int` for integer fields, `string` for text, `array<int,string>`
   for `ArrayList`, …).
2. **Sanitizes** the value (strips control chars, normalizes line
   endings, truncates over-long text via `Sanitizer`).
3. **Validates** against the field's `required` flag and the
   type-specific config (`minLength` for `Text` / `LongText` /
   `Password`, `mimes` for `Imageupload`, format for `Datepicker`,
   …).

What it returns:

```php
$result = $registry->get(FieldType::Text)->validate('Hello', $field);

$result->isValid;   // bool
$result->coerced;   // mixed: the storage value, only set on success
$result->errorCode; // InputErrorCode|null: set on failure
$result->message;   // string: optional human-readable hint
```

The success path uses `$result->coerced`; the failure path uses
`$result->errorCode` and `$result->message`. The shape never holds
both: it's a discriminated record.

### The error codes you'll actually see

iManager defines six `InputErrorCode` values, but only three are
emitted by the built-in plugins:

| Code | When the built-ins emit it |
|---|---|
| `EmptyRequired` | Required field came in empty (`Text`, `LongText`, `Editor`, `Slug`, `Datepicker`, `Dropdown`, `Decimal`, `Money`, `Password`, `Filepicker`, `ArrayList`, …). |
| `MinLengthExceeded` | Value below the `minLength` config (`Text`, `LongText`, `Password`). |
| `WrongValueFormat` | Couldn't parse the input (`Datepicker` rejecting a non-date, `Decimal` rejecting non-numeric, `Dropdown` rejecting an unlisted option, `Password` failing a complexity check, …). |

Three more exist for custom plugins to use:

| Code | What it's there for |
|---|---|
| `MaxLengthExceeded` | The enum case exists, but the built-in text plugins **truncate** silently via the sanitizer rather than failing. By design, so a stray paste doesn't reject the whole form. If you want hard maximum enforcement, write a tiny custom plugin or check the length before calling `validate()`. |
| `ComparisonFailed` | Reserved for custom plugins that compare against another field (e.g. password confirmation). |
| `UndefinedCategoryId` | Reserved for cross-category lookup plugins. |

The chapter sticks to the three built-ins emit.

## Build the contact-form schema

```php
<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use Imanager\DefaultBootstrap;
use Imanager\Domain\Category;
use Imanager\Domain\Field;
use Imanager\Domain\Item;
use Imanager\Enum\InputErrorCode;
use Imanager\Field\FieldTypeRegistry;
use Imanager\Storage\CategoryRepository;
use Imanager\Storage\FieldRepository;
use Imanager\Storage\ItemRepository;

$container = DefaultBootstrap::boot(
    databasePath: __DIR__ . '/data/contact.db',
    uploadsPath:  __DIR__ . '/data/uploads',
    uploadsUrl:   '/uploads',
    cachePath:    __DIR__ . '/data/cache',
);

$categories = $container->get(CategoryRepository::class);
$fields     = $container->get(FieldRepository::class);
$items      = $container->get(ItemRepository::class);
$registry   = $container->get(FieldTypeRegistry::class);
```

The new line is `$registry = $container->get(FieldTypeRegistry::class);`.
That's the door to every plugin's `validate()`.

```php
$contact = $categories->ensure(
    new Category(null, 'ContactMessage', 'contact-message'),
);

$fields->ensure(
    Field::text($contact->id, 'name', 'Name')
        ->required()->maxLength(100)->minLength(2),
);
$fields->ensure(
    Field::text($contact->id, 'email', 'Email')
        ->required()->maxLength(200),
);
$fields->ensure(
    Field::text($contact->id, 'subject', 'Subject')
        ->required()->maxLength(200)->minLength(3),
);
$fields->ensure(
    Field::longText($contact->id, 'message', 'Message')
        ->required()->maxLength(5000)->minLength(10),
);
```

Four fields, three of them with min-length constraints, one
(`email`) that *only* uses the built-in Text rules. We'll add the
email-pattern check at the application level in a moment. (For the
schema-setup mechanics — `ensure()`, the `Field::*` factories, the
fluent setters — see the [schema chapter](schema.md).)

## One field at a time

The smallest possible validate call:

```php
$nameField = $fields->findByName($contact->id, 'name');

$result = $registry->get($nameField->type)->validate('A', $nameField);

if ($result->isValid) {
    echo "Coerced: {$result->coerced}\n";
} else {
    echo "Failed: {$result->errorCode->name}: {$result->message}\n";
}
```

For input `'A'` against a `minLength: 2` field, you get:

```
Failed: MinLengthExceeded:
```

(`message` is empty because the built-in `TextFieldType` doesn't
populate it; `errorCode` carries the meaning.)

For input `'Alice'`:

```
Coerced: Alice
```

## The whole-form loop

Real forms have many fields and you want **every** error at once,
not "fix one, submit, get the next." The canonical pattern:

```php
/**
 * Validate every field on $category against $input (field-name → raw value).
 *
 * Returns [coercedValues, errors]:
 *  - coercedValues: array<string, mixed>  field-name → storage value
 *  - errors:        array<string, array{code: InputErrorCode, message: string}>
 *
 * When errors is empty, coercedValues is safe to feed into Item::$data.
 */
function validateForm(
    FieldTypeRegistry $registry,
    FieldRepository $fieldRepo,
    int $categoryId,
    array $input,
): array {
    $coerced = [];
    $errors  = [];

    foreach ($fieldRepo->findByCategory($categoryId) as $field) {
        $raw    = $input[$field->name] ?? null;
        $plugin = $registry->get($field->type);
        $result = $plugin->validate($raw, $field);

        if ($result->isValid) {
            $coerced[$field->name] = $result->coerced;
        } else {
            $errors[$field->name] = [
                'code'    => $result->errorCode,
                'message' => $result->message,
            ];
        }
    }

    return [$coerced, $errors];
}
```

This is the pattern every iManager-using app reaches for at some
point. It's worth keeping somewhere reusable in your code. The
[field-types cookbook](../field-types.md#2-the-validation-pipeline)
shows a slightly more sophisticated variant that also handles
fields the input doesn't mention.

## Use it

```php
$input = [
    'name'    => 'Alice',
    'email'   => 'alice@example.com',
    'subject' => 'Hi',
    'message' => 'Short',
];

[$coerced, $errors] = validateForm($registry, $fields, $contact->id, $input);

if ($errors) {
    foreach ($errors as $name => $err) {
        echo "[{$name}] {$err['code']->name}\n";
    }
} else {
    $saved = $items->save(new Item(
        id:         null,
        categoryId: $contact->id,
        name:       'msg-' . uniqid(),
        label:      $coerced['subject'],
        data:       $coerced,
    ));
    echo "Saved message #{$saved->id}\n";
}
```

Running this prints:

```
[subject] MinLengthExceeded
[message] MinLengthExceeded
```

Two clean failures, no partial write, no `try / catch`.
Validation said no, so `save()` was never called.

Fix the input:

```php
$input = [
    'name'    => 'Alice',
    'email'   => 'alice@example.com',
    'subject' => 'Hello there',
    'message' => 'I would like to ask about pricing.',
];
```

…and you get `Saved message #1`.

## Where iManager stops and your code starts

The contact form passes validation, but `email` is still anything
the user typed. The built-in `TextFieldType` enforces *length*, not
*format*. There's no email-pattern check in iManager today. If
that matters to you, two options:

### Option A — check it before calling `validate()`

The cheapest path: layer your own check on top of the iManager loop.

```php
[$coerced, $errors] = validateForm($registry, $fields, $contact->id, $input);

if (! isset($errors['email'])
    && filter_var($coerced['email'] ?? '', FILTER_VALIDATE_EMAIL) === false
) {
    $errors['email'] = [
        'code'    => InputErrorCode::WrongValueFormat,
        'message' => 'Please enter a valid email address.',
    ];
}
```

This keeps your validation contract centralized (one `errors` map,
one shape) without changing iManager.

### Option B — register a custom `EmailFieldType` plugin

When you have many email fields, or many format checks (phone,
postcode, IBAN, …), wrap each as its own plugin. Then the validation
loop above keeps working unchanged. The
[field-types cookbook](../field-types.md#writing-a-custom-field-type)
walks through the contract end-to-end; for an email plugin it'd be
~30 lines of code.

Pick A for one-off checks, B for things you'll reuse.

## Mapping error codes to human messages

`InputErrorCode` cases are stable identifiers, not display strings.
A central mapper keeps the UI layer ignorant of error-code internals:

```php
function errorMessage(InputErrorCode $code, Field $field): string
{
    $label = $field->label ?? $field->name;

    return match ($code) {
        InputErrorCode::EmptyRequired      => "{$label} is required.",
        InputErrorCode::MinLengthExceeded  => "{$label} is too short.",
        InputErrorCode::MaxLengthExceeded  => "{$label} is too long.",
        InputErrorCode::WrongValueFormat   => "{$label} has the wrong format.",
        InputErrorCode::ComparisonFailed   => "{$label} does not match.",
        InputErrorCode::UndefinedCategoryId => "{$label} references an unknown category.",
    };
}
```

For an i18n-aware app, swap the right-hand side with a translation
key:

```php
return $translator->trans(match ($code) {
    InputErrorCode::EmptyRequired      => 'validation.empty_required',
    InputErrorCode::MinLengthExceeded  => 'validation.min_length',
    /* … */
}, ['{label}' => $label]);
```

Either way, **don't** show `$code->name` to users. `EmptyRequired`
is for logs, not for a form field tooltip.

## What just happened, in one paragraph

You learned that `ItemRepository::save()` is intentionally
trust-the-caller, and that the field-type plugins' `validate()` is
the separate contract you call before saving. You wrote the
canonical "loop every field, collect every error" helper, used it
on a four-field contact form, watched it reject two fields with
`MinLengthExceeded`, then fed the coerced values into `Item::$data`
once the form was clean. You also saw where iManager's typing
validation ends (everything length / required / format-coerce) and
where your application layer starts (email-pattern check, business
rules, cross-field constraints), with two patterns for closing
that gap.

## Reference

- [`docs/api/field-types.md`](../api/field-types.md), every
  built-in field type's config keys and the error codes its
  `validate()` can emit.
- [`docs/field-types.md`](../field-types.md), the cookbook for
  writing your own `FieldTypePlugin`, including a custom
  `validate()`.
- [`src/Field/ValidationResult.php`](../../src/Field/ValidationResult.php),
  the discriminated record returned by every `validate()`.
- [`src/Enum/InputErrorCode.php`](../../src/Enum/InputErrorCode.php),
  every error code with its stable integer value.
