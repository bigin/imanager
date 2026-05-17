# iManager 2.2.0 — Honest `searchable` flag

Design plan, no code yet. Once this lands, the implementation
follows in 2–3 follow-up PRs.

## Status

- **Track opened**: 2026-05-17
- **Trigger**: Writing `docs/tutorial/schema.md` forced us to
  document — honestly — that the per-field `searchable` boolean
  has *no effect* today. The FTS index dumps every string/numeric
  value from every field into the `body` column regardless. The
  tutorial currently carries a "this is aspirational, planned for
  a later release" caveat. This plan removes that caveat by making
  the flag actually load-bearing.
- **Scope**: Behavioral change to FTS indexing — `searchable: false`
  fields stop appearing in `items_fts.body`. Additive ergonomics
  (smart factory defaults). Schema migration `0005` for existing
  installs.
- **SemVer**: MINOR (`2.2.0`). No public API additions or
  removals; the `Field::searchable()` setter that exists since
  2.1.0 stops being a no-op. CHANGELOG must call out the
  behavioral switch because the FTS index content changes after a
  rebuild on upgrade.

## Problem statement

`Field` has a `bool $searchable = false` property since 1.x. It
has a fluent setter (`->searchable(true)`) since 2.1.0. The repo
round-trips it correctly. But:

1. **`SqliteItemRepository::syncFts()` ignores the flag.** It
   takes the item's `data` array, flattens every string/int/float
   recursively via `flattenForSearch()`, and writes the result
   into `items_fts.body`. Whether a field was declared
   `->searchable(false)` makes zero difference.
2. **`FullTextSearch::rebuild()` ignores the flag.** It runs a
   single `INSERT INTO items_fts ... SELECT ... i.data` over the
   whole table — the entire JSON blob, untrimmed.
3. **Existing comment is honest about the gap**
   (`SqliteItemRepository.php:208`): "post-Phase-8 we'll respect
   the per-field `searchable` flag once a use case asks for opt-
   out." A use case has arrived: the tutorial, which can't
   credibly teach the flag while it does nothing.

What 2.1.x users see today:

```php
$fields->ensure(Field::password($u->id, 'password')->required());
// Saves a password field. Whatever bcrypt-hashed value lands in
// the item's `password` key gets indexed into FTS. Pointless and
// noisy.
```

What 2.2.0 changes:

```php
$fields->ensure(Field::password($u->id, 'password')->required());
// Same code. But because Field::password() now defaults
// searchable: false, the hash never reaches FTS.
```

And the opt-out is explicit:

```php
$fields->ensure(Field::text($post->id, 'title')->searchable(false));
// Excludes this field from FTS deliberately.
```

## Non-goals

- **Not changing what FTS5 ranks against.** `name` and `label`
  remain structural columns on the FTS table, always indexed —
  they live on the `items` row itself, not in `data`, and they're
  already separately addressable via `name:` / `label:` column-
  restricted queries.
- **Not adding a `Query::matchesFts()` bridge.** Mixing structured
  predicates with FTS hits is still a documented gap. Out of
  scope for this release.
- **Not changing the FTS schema** (`items_fts` columns,
  tokenizer settings). The migration only touches the `fields`
  table to update default values for existing rows.
- **Not adding per-field weighting** (BM25 column weights). Could
  land later if a real use case appears.

## Recommended-lean naming + defaults

Five decisions where we're presenting our pick rather than
deferring. Each has a one-line rationale.

### D1. Constructor default stays `searchable: false`

`Field::__construct(... bool $searchable = false ...)` does not
change. This avoids any signature break for code that constructs
`Field` directly. The factories — which is what the 2.1.0
ergonomics push everyone toward — set their own per-type
defaults.

### D2. Factory smart defaults

| Factory | Default `searchable` |
|---|---|
| `Field::text()` | **true** |
| `Field::longText()` | **true** |
| `Field::editor()` | **true** |
| `Field::slug()` | **true** |
| `Field::password()` | false |
| `Field::integer()` | false |
| `Field::decimal()` | false |
| `Field::money()` | false |
| `Field::checkbox()` | false |
| `Field::dropdown()` | false |
| `Field::datepicker()` | false |
| `Field::hidden()` | false |
| `Field::arrayList()` | false |
| `Field::file()` | false |
| `Field::image()` | false |
| `Field::filePicker()` | false |

Rule of thumb: a field whose value is *prose a human would type
to find this item* defaults to indexed. Everything else
(hashes, ids, file paths, money amounts, dates) doesn't.

Users still have full control via `->searchable(true|false)`.

### D3. Inject `FieldRepository` into `SqliteItemRepository` as an OPTIONAL parameter

```php
public function __construct(
    private \PDO $connection,
    ?EventDispatcherInterface $events = null,
    ?FieldRepository $fields = null,   // NEW
) { ... }
```

- `null` → fall back to legacy behavior (index everything),
  preserving backward compatibility for anyone constructing the
  repo manually with the 2.0/2.1 signature.
- `DefaultBootstrap` wires the real `FieldRepository` in, so the
  paved path gets the new behavior automatically.
- A one-line E_USER_DEPRECATED notice when `$fields === null` on
  the first FTS-write call, pointing at the bootstrap or the
  3-arg constructor — gives external integrators a heads-up
  without breaking them.

Alternative considered (required param): cleaner but breaks
direct constructor users for a feature they may not even use.
Optional-with-deprecation is the lower-friction landing.

### D4. Per-process category fields cache, invalidated on field events

`syncFts()` is called on every item save. Loading category fields
from SQLite per save is cheap (~0.1ms) but unnecessary. A tiny
in-memory cache keyed by `categoryId`, invalidated whenever the
repo dispatches `FieldCreated` / `FieldUpdated` / `FieldDeleted`,
keeps the hot path zero-query. Cache lives on the
`SqliteItemRepository` instance (same lifetime as the request).

### D5. Migration `0005_searchable_defaults.sql`

```sql
-- Promote prose-typed fields to searchable on upgrade so existing
-- installs keep their current FTS coverage for human-readable
-- content. Side effects, deliberate:
--   * `password` fields (bcrypt hashes) leave the FTS index.
--   * `fileupload`/`imageupload`/`filepicker` paths leave FTS.
--   * Numeric, date, boolean and structured-data fields leave FTS.
-- Fields explicitly declared `searchable(true)` keep their flag.
UPDATE fields
   SET searchable = 1
 WHERE searchable = 0
   AND type IN ('text', 'longtext', 'editor', 'slug');
```

This is the load-bearing migration. It preserves the **Scriptor
text-field FTS coverage** by name — `slug`, `parent`, `pagetype`,
`menu_title`, `content`, `template`, `role`, `email` are all
`text`/`longtext`/`slug`-typed and get promoted.

The CLI `fts:rebuild` command should be re-run after upgrade so
the body column actually drops the now-excluded values. The
upgrade note in CHANGELOG must say this explicitly.

## What changes by file

### `src/Domain/Field.php` (~30 LOC)

- Each `Field::text|longText|editor|slug()` factory passes
  `searchable: true` to the constructor.
- Each `Field::password|integer|decimal|money|checkbox|dropdown|`
  `datepicker|hidden|arrayList|file|image|filePicker()` factory
  passes `searchable: false` (current default — explicit for
  clarity).
- Docstring on the `$searchable` property is updated: today it
  says "reserved for future use", needs to say "controls whether
  this field's value is written into the FTS5 `body` column".

### `src/Storage/Sqlite/SqliteItemRepository.php` (~50 LOC)

- Constructor gains optional `?FieldRepository $fields = null`
  param.
- New private `searchableKeysFor(int $categoryId): ?array<string>`
  returns the list of field `name`s with `searchable === true`,
  cached per category. Returns `null` if `$fields === null`
  (signals legacy "index everything" mode).
- `syncFts()` becomes:
  ```php
  $allowed = $this->searchableKeysFor($categoryId);
  $body = ($name ?? '') . ' ' . ($label ?? '') . ' '
        . self::flattenForSearch($data, $allowed);
  ```
- `flattenForSearch()` signature gains `?array $allowedKeys`. When
  non-null, only top-level entries whose key is in `$allowedKeys`
  are walked.
- Cache invalidation: subscribe to `FieldCreated`/`Updated`/
  `Deleted` events at construction time and reset the relevant
  category entry.
- One-time deprecation notice if `$fields === null` on first FTS
  write.

### `src/Search/FullTextSearch.php` (~40 LOC)

- `rebuild()` stops being a single bulk INSERT. New flow:
  1. `DELETE FROM items_fts`
  2. SELECT all items
  3. For each item, look up its category's searchable keys
     (re-using the same cache as the item repo, or rebuilding a
     local one) and INSERT the trimmed body.
- This is a CLI op, not a hot path — per-row iteration is fine
  for the install sizes iManager actually targets.
- Either: take a `FieldRepository` in the constructor too, OR
  keep using raw PDO and bulk-query `fields` once at the start of
  rebuild. **Recommendation**: bulk-query once at rebuild start
  to keep `FullTextSearch` decoupled from the storage layer.

### `src/Bootstrap/DefaultBootstrap.php` (~5 LOC)

- Pass the `FieldRepository` instance into
  `new SqliteItemRepository(...)`.

### `config/schema/0005_searchable_defaults.sql` (new, ~10 LOC)

The migration above.

### `tests/Unit/Search/FullTextSearchTest.php` and friends (~200 LOC)

- New: `testSaveExcludesNonSearchableFields()` — declare a
  category with two text fields, one `->searchable(false)`, save
  an item, assert MATCH on the excluded value returns 0 hits.
- New: `testUpdateRefreshesFtsWithCurrentSearchableSet()` — save,
  flip the flag, re-save, assert FTS reflects the new set.
- New: `testRebuildRespectsSearchableFlag()` — same setup,
  rebuild, assert hits trimmed.
- New: `testMultipleCategoriesIndependentSearchableSets()` —
  cross-category isolation.
- New: `testStructuralNameAndLabelAlwaysIndexed()` — declare zero
  searchable fields, assert `name`/`label` MATCHes still work.
- New: `testLegacyConstructorWithoutFieldRepoIndexesEverything()`
  — back-compat smoke; expect the deprecation notice.
- Migration unit test: applying `0005` to a seeded fields table
  flips the right rows and only the right rows.

### `tests/Unit/Domain/FieldTest.php` (~30 LOC)

- New data-provider entries: each factory returns the documented
  default `searchable` value.

### `docs/tutorial/schema.md`

- "Two flags" section: drop the "honest caveat" paragraph. Replace
  with a worked example showing the password field excluded by
  default and a slug field explicitly opted out.

### `docs/tutorial/search.md`

- New short paragraph: "what does and doesn't make it into the
  index", referencing the factory defaults and `searchable()`
  setter.

### `CHANGELOG.md`

- New `## [2.2.0] — 2026-05-??` entry. Must call out:
  - `searchable` flag now load-bearing.
  - Factory defaults table.
  - Upgrade step: run `vendor/bin/imanager fts:rebuild` after
    composer-update so existing index drops now-excluded values.
  - Side effect: `password`-typed and file-path fields stop
    appearing in FTS results. (Both desirable; password was a
    bcrypt hash, file paths weren't useful FTS content.)

### `src/Imanager.php`

- `VERSION` bump `2.1.0` → `2.2.0`. `ReleaseConsistencyTest`
  already asserts CHANGELOG/VERSION agreement.

## Open questions

These are calls I want explicit yes/no on before writing code.

### Q1. Do we ship the deprecation notice (D3) or just silently fall back?

Recommendation: ship it as `trigger_error(..., E_USER_DEPRECATED)`
once per process. Gives integrators a real signal without
breaking; loudly silent is worse than briefly noisy.

### Q2. Is the per-category cache invalidation worth the event-subscription complexity?

Recommendation: yes. Without it, an integrator who renames a field
in a long-running CLI process keeps writing stale FTS rows. The
event subscription is ~10 LOC and the existing event dispatcher
machinery makes it trivial.

### Q3. Do we add a `Field::searchable: bool` parameter to every factory for explicit override at construction time, or rely on the chained `->searchable()` setter?

Recommendation: rely on the chained setter. Adding the param to
every factory bloats the signature; the chain is more discoverable
(`Field::password($cat, 'pw')->searchable(true)` reads better
than `Field::password($cat, 'pw', searchable: true)` once we have
13 setters anyway).

### Q4. Migration scope — is `text/longtext/editor/slug` the right type list, or should we be more conservative (only `text/longtext`) or more aggressive (everything that isn't `password`)?

Recommendation: stick with `text/longtext/editor/slug`. Conservative
breaks Scriptor's `slug` and any rich-text-via-editor content.
Aggressive would re-index `dropdown` strings (often label noise)
and `hidden` payloads (often serialized IDs). The four-type list
matches what humans actually type and search for.

### Q5. Should `Field::editor()`'s default really be `searchable: true` even though the body is HTML?

Recommendation: yes, with a CHANGELOG note. The HTML markup
won't match useful queries (`<p>` isn't a search term), but the
prose between tags will. The FTS5 default tokenizer skips
punctuation, so `<p>Hello</p>` indexes `hello`. If anyone wants
to strip HTML before indexing, that's a future plugin point, not
a 2.2.0 concern.

### Q6. Do we need a runtime warning when the user runs `fts:rebuild` BEFORE upgrade but with code already on 2.2.0?

That ordering is impossible in practice — code update happens
before any CLI runs. Skip.

## Release sequence

Same shape as 2.1.0: design plan first (this PR), then 2–3
implementation PRs, then a release-cut PR that bumps VERSION +
CHANGELOG. Suggested split:

- **PR 1** — Field factories carry their per-type defaults +
  tests. Pure additive ergonomics, no behavior change yet
  (because syncFts still ignores the flag).
- **PR 2** — `SqliteItemRepository` honors the flag,
  `FullTextSearch::rebuild()` honors it, migration `0005`. The
  behavioral switch. Includes the deprecation shim.
- **PR 3** — Release cut: VERSION bump, CHANGELOG `## [2.2.0]`
  section, tutorial updates (`schema.md` caveat removal, short
  `search.md` paragraph). Tag `2.2.0` lands on merge.

After tag: bump `bigins/imanager` in Scriptor's composer.lock,
run `vendor/bin/imanager fts:rebuild` on the live install + the
demo server, verify a smoke search returns the expected hits.

## Estimated effort

~half a focused day of implementation + tests + docs, dominated by
the migration / back-compat decision (now answered above), not by
LOC.

| Piece | LOC | Time |
|---|---|---|
| Field factories per-type defaults | ~30 | 30 min |
| SqliteItemRepository plumbing | ~50 | 1 h |
| FullTextSearch rebuild rework | ~40 | 30 min |
| Migration `0005` | ~10 | 15 min |
| Tests | ~230 | 1.5 h |
| Tutorial + CHANGELOG updates | ~80 | 30 min |
| Release cut + tag + smoke | — | 30 min |
| **Total** | **~440** | **~4.5 h** |
