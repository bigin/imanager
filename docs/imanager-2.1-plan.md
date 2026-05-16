# iManager 2.1.0 — Schema-Setup Ergonomics

Design plan, no code yet. Once this lands, the implementation
follows in 2–3 follow-up PRs.

## Status

- **Track opened**: 2026-05-16
- **Trigger**: Writing the `docs/tutorial/schema.md` chapter forced
  an honest demonstration of how a real user declares a schema —
  and exposed that a clean idempotent setup requires a 9-argument
  helper function wrapping `findBy*() ?? save(new Field(...))`. The
  helper is the symptom; the verbose value-object construction and
  the missing repository upsert are the causes.
- **Scope**: Additive ergonomics. No semantic changes to existing
  APIs, no behavior changes to existing methods, no removal of
  anything in 2.0.x.
- **SemVer**: MINOR (`2.1.0`). New public surface, no breaking
  changes for direct callers. *Implementers* of the storage
  interfaces (third-party `CategoryRepository` / `FieldRepository`
  classes) need to add one method each — practically zero impact
  because the storage interfaces are internal-shaped and have no
  known external implementers in the wild.

## Problem statement

iManager 2.0 stores schema as `Category` + `Field` value objects.
The value objects are `final readonly`, which is good (trivially
serializable, no hidden state, immutable across the lifecycle).
But:

1. **`Field` has nine constructor properties that matter at
   construction time** (`id`, `categoryId`, `name`, `label`,
   `type`, `position`, `required`, `indexed`, `searchable`,
   `config`). Defining a field requires either remembering 9
   defaults or 9 named arguments per call.
2. **No repository upsert.** Re-running schema setup against an
   existing database raises `UNIQUE constraint failed` unless the
   caller wraps every save in `findBy*() ?? save()`. The tutorial
   teaches a helper, but the helper is boilerplate every user has
   to write themselves.

What today's user has to write to declare a `title` field
idempotently:

```php
function ensureField(
    FieldRepository $repo, int $categoryId, string $name, string $label,
    FieldType $type, bool $required = false, bool $indexed = false,
    bool $searchable = false, array $config = [],
): Field {
    return $repo->findByName($categoryId, $name)
        ?? $repo->save(new Field(
            id: null, categoryId: $categoryId, name: $name, label: $label,
            type: $type, required: $required, indexed: $indexed,
            searchable: $searchable, config: $config,
        ));
}

ensureField($fields, $post->id, 'title', 'Title',
    type: FieldType::Text, required: true, indexed: true,
    searchable: true, config: ['maxLength' => 200]);
```

What 2.1.0 lets them write:

```php
$fields->ensure(
    Field::text($post->id, 'title', 'Title')
        ->required()->indexed()->searchable()->maxLength(200)
);
```

11 lines → 4 lines, and the user-defined helper disappears entirely.

## Non-goals

- **Not changing the value-object semantics.** `Field` remains
  `final readonly`. Every fluent setter returns a new instance —
  no mutable builder, no two-phase init. The `with*()` pattern that
  already exists on `Field::withId()` is the seed.
- **Not changing the runtime API.** `$items->save()`,
  `$items->query()`, `$item->data->get()` — none of that is touched.
- **Not adding a schema-definition DSL.** No closure-based
  `category('Post', function ($c) { $c->text(...) })`. Static
  factories + fluent setters are the entire ergonomic surface;
  beyond that is scope creep.
- **Not addressing Item ergonomics.** Items are written at runtime
  in app code, not in schema setup. The construction cost there is
  paid once per item write; ergonomics don't compound the same way.
  Revisit in 2.2.x if real friction shows up.
- **Not bundling other changes.** The hardcoded `VERSION` constant
  bug listed in `memory/project_imanager_followups.md` ships
  *with* this release but as its own commit; CHANGELOG entry under
  the same `2.1.0` section.

## API surface — decided

### A. `Field` static factories (16, one per `FieldType`)

```php
namespace Imanager\Domain;

final readonly class Field
{
    public static function text(int $categoryId, string $name, ?string $label = null): self;
    public static function longText(int $categoryId, string $name, ?string $label = null): self;
    public static function editor(int $categoryId, string $name, ?string $label = null): self;
    public static function slug(int $categoryId, string $name, ?string $label = null): self;
    public static function password(int $categoryId, string $name, ?string $label = null): self;
    public static function integer(int $categoryId, string $name, ?string $label = null): self;
    public static function decimal(int $categoryId, string $name, ?string $label = null): self;
    public static function money(int $categoryId, string $name, ?string $label = null): self;
    public static function checkbox(int $categoryId, string $name, ?string $label = null): self;
    public static function dropdown(int $categoryId, string $name, ?string $label = null): self;
    public static function datepicker(int $categoryId, string $name, ?string $label = null): self;
    public static function hidden(int $categoryId, string $name, ?string $label = null): self;
    public static function arrayList(int $categoryId, string $name, ?string $label = null): self;
    public static function file(int $categoryId, string $name, ?string $label = null): self;
    public static function image(int $categoryId, string $name, ?string $label = null): self;
    public static function filePicker(int $categoryId, string $name, ?string $label = null): self;
}
```

**Naming notes:**

- `arrayList` (not `array`) — `array` is a PHP reserved word, and
  while PHP 7+ technically allows reserved words as method names,
  reaching for `Field::arrayList()` keeps the call site free of
  syntax-coloring surprises and matches the `FieldType::ArrayList`
  enum case verbatim.
- `file` and `image` (not `fileUpload` / `imageUpload`) — drops the
  redundant `Upload` suffix; the factory is the *upload* helper.
- `filePicker` — keeps the camelCase split from the enum case name.
- `longText` — camelCase split from the enum case `LongText`.
- All other factories match the enum case name in camelCase.

**Behavior:** Each factory returns a `Field` instance with
`$id = null` (fresh, not yet persisted), the corresponding
`FieldType` enum set, and otherwise default flags (`required=false`,
`indexed=false`, `searchable=false`, `position=0`, `config=[]`).
`$label` defaults to `null` and is presentation-only — the
`FieldRepository::save()` accepts `null` labels today.

### B. Fluent setters — general (apply to every factory)

Each returns a new `Field` instance (immutable value object stays
intact):

```php
public function required(bool $required = true): self;
public function indexed(bool $indexed = true): self;
public function searchable(bool $searchable = true): self;
public function position(int $position): self;
public function label(string $label): self;
public function config(array $config): self;   // full replace
```

The `bool $flag = true` shape (rather than `flag()` no-arg) lets
callers also turn flags **off** symmetrically:

```php
Field::text($cat, 'name', 'Name')->required()->required(false); // ends up not-required
```

Edge case in practice, but the API is consistent.

The general-purpose `->config(array)` setter is the escape hatch
for plugins that introduce new config keys not covered by the
type-aware setters below.

### C. Fluent setters — type-aware (named for the config key they touch)

These setters write into `Field::$config` under a documented key.
They're defined on `Field` itself (single class, no builder
hierarchy) — a setter that doesn't apply to a field type is
silently no-op: the `config` array accepts any key, the plugin's
`validate()` ignores keys it doesn't recognize.

| Setter | Stores under `config` key | Applies to (built-in plugins) |
|---|---|---|
| `maxLength(int $chars)` | `maxLength` | `Text`, `LongText`, `Editor`, `Slug`, `Password` |
| `minLength(int $chars)` | `minLength` | `Text`, `LongText`, `Editor`, `Password` |
| `placeholder(string $text)` | `placeholder` | `Text`, `LongText`, `Editor`, `Password`, `Datepicker` |
| `maxBytes(int $bytes)` | `maxBytes` | `Fileupload`, `Imageupload` |
| `mimes(string ...$mimes)` | `mimes` | `Fileupload`, `Imageupload` |
| `options(array $options)` | `options` | `Dropdown` |
| `format(string $format)` | `format` | `Datepicker` |

**Why "silently no-op" rather than throw:**

- Plugins are pluggable. A custom plugin might want to consume
  `maxLength` even on a checkbox-like field. Erroring at build time
  would prevent that.
- Type-safety here would require either 16 builder classes (one
  per factory) or runtime checks on a static set. Both have
  worse cost-to-value than letting `config` stay loose.
- The plugin's own `validate()` already ignores unknown config —
  the contract is that `config` is `array<string, mixed>`.

**Why setters at all (rather than just `->config(['maxLength' => 200])`):**

- Discoverability: IDE autocomplete on `Field` shows every common
  config key without the developer having to know the plugin's
  config-key spelling ahead of time.
- Type safety on the *value*: `maxLength(int)` rejects `maxLength('200')`
  at the static-analysis layer.
- Composability: setters return a new instance, so chaining
  reads cleaner than nested array literals.

### D. Repository `ensure()` methods

#### `CategoryRepository::ensure(Category $c): Category`

```php
public function ensure(Category $category): Category;
```

**Semantics:**

- If `$category->id !== null` → identical to `save()` (update by id).
- If `$category->id === null`:
  - Lookup by `$category->slug`.
  - **Hit**: returns the *existing* category, **does not update**.
    The `ensure` contract is "make sure this exists," not "force
    this state." Callers wanting upsert-with-update should use
    `save()` after `findBySlug()`.
  - **Miss**: insert (same as `save(new Category(...))`).
- Emits `CategoryCreated` only when a row was actually inserted.
  No event on hit.

#### `FieldRepository::ensure(Field $f): Field`

```php
public function ensure(Field $field): Field;
```

**Semantics:**

- If `$field->id !== null` → identical to `save()`.
- If `$field->id === null`:
  - Lookup by `(categoryId, name)`.
  - **Hit**: returns the existing field, **no update**.
  - **Miss**: insert.
- Emits `FieldCreated` only when a row was actually inserted.

**Why no-update-on-hit:** The biggest risk with implicit upsert is
silently changing a field's `indexed` flag (which costs an `ALTER
TABLE`-equivalent on the items table) or `searchable` (which
triggers FTS reindexing). The user might re-run schema setup not
expecting a structural change. "Ensure" with insert-only semantics
matches the cheap idempotent path the tutorial helper provides
today; users who want upsert-with-update reach for `save()` after
an explicit `findByName()`.

This semantic is the same as the Tutorial's `ensureField` helper —
which is the right design, just done by the framework now.

#### `ItemRepository::ensure()` — deliberately not added

Items have no unique natural key beyond `id`. There's no
sensible `ensure` semantic. Callers that want "create if not
exists" by item `name` within a category can compose a one-line
helper themselves using `$items->query()`.

## Backwards compatibility

| Change | Caller-side BC | Implementer-side BC |
|---|---|---|
| Static factories on `Field` | Additive — no BC impact. | N/A — `Field` is a value object, not an interface. |
| Fluent setters on `Field` | Additive — no BC impact. | N/A. |
| `CategoryRepository::ensure()` (interface method) | Additive — no caller breaks. | **Technical break for any class implementing `CategoryRepository`.** The shipped `SqliteCategoryRepository` gets the method. Third-party implementers (none known in the wild) would need to add `ensure()`. Acceptable for a 2.x → 2.1 MINOR. |
| `FieldRepository::ensure()` (interface method) | Same as above. | Same. |

**Mitigation for third-party implementers:** The `CHANGELOG.md`
2.1.0 entry calls out the interface additions explicitly, with a
copy-paste implementation hint (`ensure` can be a 4-line default
in terms of `find*` + `save`).

## Implementation notes

### 1. Factory implementation pattern

Every factory is one line:

```php
public static function text(int $categoryId, string $name, ?string $label = null): self
{
    return new self(
        id: null, categoryId: $categoryId, name: $name,
        label: $label, type: FieldType::Text,
    );
}
```

No clever metaprogramming. 16 explicit methods, one per type.
Costs an extra ~50 LOC in `Field.php` but keeps the file
greppable and the API surface obvious to anyone reading the class.

### 2. Fluent setter implementation pattern

Each setter uses the same one-property-changed clone:

```php
public function required(bool $required = true): self
{
    return new self(
        id: $this->id, categoryId: $this->categoryId, name: $this->name,
        label: $this->label, type: $this->type, position: $this->position,
        required: $required, indexed: $this->indexed, searchable: $this->searchable,
        config: $this->config, created: $this->created, updated: $this->updated,
    );
}
```

Verbose at the implementation site, but each setter is independently
correct and trivially testable. The verbosity is paid once, in
`Field.php`, never at the call site.

### 3. Type-aware setter implementation pattern

```php
public function maxLength(int $chars): self
{
    return $this->config(['maxLength' => $chars] + $this->config);
}
```

Merge into existing `config`, last writer wins on key collision.
Re-uses the general `->config()` setter for the actual rebuild.

### 4. Repository `ensure()` implementation

```php
public function ensure(Field $field): Field
{
    if ($field->id !== null) {
        return $this->save($field);
    }
    $existing = $this->findByName($field->categoryId, $field->name);
    return $existing ?? $this->save($field);
}
```

3 lines. Same shape for `CategoryRepository::ensure()` against
`findBySlug()`.

## Test plan

- **Unit tests on `Field`** for every factory (16 tests, one per
  factory): verifies the returned object has `id=null`, the right
  `FieldType`, default flags.
- **Unit tests on `Field` for every fluent setter**: verifies the
  returned object has the changed flag and *only* the changed
  flag — preserves every other property.
- **Unit tests on type-aware setters**: verifies the right `config`
  key gets written, existing config keys are preserved.
- **Repository tests for `ensure()`**:
  - Miss (no row exists) → insert, returns row with `id`.
  - Hit (row exists by natural key, input `id=null`) → returns
    existing row, no event emitted.
  - Hit with `id !== null` → behaves as `save()` (update path).
- **Integration smoke**: the tutorial `schema.md` rewrite runs
  end-to-end against a fresh DB, ensure() called twice in a row
  emits exactly one `CategoryCreated` / one `FieldCreated` per
  field.
- **No regression** in any existing test.

## Open questions

These are deliberately left for the review pass on this plan, not
decided unilaterally:

1. **Should `Category` get static factories too?** `Category::named(string $name, ?string $slug = null): self` — auto-slugifies if `slug` is omitted? Or is the existing 2-arg constructor good enough? **Lean: skip for 2.1.0** — `new Category(null, 'Post', 'post')` is short already. Add in a future MINOR if users ask for it.
2. **Should the factories be in a separate `Imanager\Domain\Fields` static helper class** (`Fields::text(...)`) rather than on `Field` itself? Keeps the value object lean. **Lean: keep them on `Field`** — discoverability beats encapsulation here, and the surface is finite (16 methods).
3. **Naming sanity check** on the bigger renames: `arrayList`, `file`, `image`, `filePicker`. Anyone object before we commit?
4. **`->mimes()` variadic vs. array**: `->mimes('image/jpeg', 'image/png')` vs `->mimes(['image/jpeg', 'image/png'])`. Variadic is more readable for 1–3 mimes, awkward for "load from config." **Lean: variadic** — config-driven callers can spread `...$mimes`.
5. **Should `->config()` merge or replace?** Currently spec'd as replace; `mergeConfig(array)` could be the merge variant. **Lean: keep `config()` as replace, add `mergeConfig()` if a real need shows up.**

## Out of scope, but related

- **Schema-version tracking on categories.** When schema setup
  starts updating fields (not just adding), a category-level
  schema-version column would let callers know "have I been
  upgraded?" Plausible 2.2.x topic.
- **Schema introspection helpers**. `$post->fields()` returning the
  full field schema as a typed list. Useful for theme authors;
  separate ergonomic concern.
- **Snake/kebab/camel name conventions for field `name`.** The
  current convention (snake-ish: `cover_image`) is fine but
  unspecified. Could be tightened in 2.2.x.

## Release shape

Once this plan is signed off:

1. **PR 1 — Field factories + fluent setters** (`feat(domain): add Field static factories and fluent setters`). 16 factories + 6 general setters + 7 type-aware setters, plus tests.
2. **PR 2 — Repository `ensure()` methods** (`feat(storage): add ensure() upsert-by-natural-key to Category and Field repositories`). Two interface methods, two implementations, tests.
3. **PR 3 — VERSION constant bump + CHANGELOG** (`chore(release): cut 2.1.0`). Bundles the followup from `memory/project_imanager_followups.md` (`Imanager::VERSION` constant moved to 2.1.0) plus the CHANGELOG `[2.1.0]` section.
4. **Tag 2.1.0.**
5. **PR 4 (separate, on Scriptor side later)** — bump Scriptor's `composer.lock` to iManager 2.1.0.
6. **PR 5 (back on iManager)** — `docs/tutorial/schema.md` retrofit to use the new API; `docs/tutorial/validation.md` likewise gets the helper removed. Same PR can land the remaining three tutorial chapters (`files.md`, `search.md`, `events.md`) on top of the new ergonomics.

Total estimate: 1–2 days of focused work for the implementation, plus the tutorial retrofit on top.
