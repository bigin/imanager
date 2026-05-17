# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [2.2.1] — 2026-05-17

Hotfix for the 2.2.0 upgrade path: `fts:rebuild` and `optimize` now
apply pending schema migrations before doing their work.

### Fixed

- **`fts:rebuild` now auto-migrates.** The 2.2.0 upgrade recipe said
  to run `vendor/bin/imanager fts:rebuild` after `composer update`,
  but the command opened a raw PDO without running pending
  migrations. The rebuild then fired against the pre-2.2.0 schema
  (`fields.searchable = 0` everywhere) and silently produced an
  empty/incorrect FTS body for every item. Hit while bumping
  Scriptor; fixed before reaching the Hetzner demo. (#60)
- **`optimize` now auto-migrates too.** Same surprise was latent:
  running `VACUUM` before migrations wastes cycles because the next
  command applies new tables/columns anyway. (#60)
- **`DatabaseFactory::migrateIfNeeded()`** added as the shared
  primitive. Announces each pending migration on the provided
  output before applying, so users see what just happened. (#60)

### Notes for upgraders

- Anyone who already ran 2.2.0's recipe verbatim (`fts:rebuild`
  only) and saw their FTS index go empty: install 2.2.1 and re-run
  `vendor/bin/imanager fts:rebuild --db=<your.db>`. The command
  now applies migration `0005` first, then rebuilds against the
  correct schema.
- Diagnostic commands (`dump`, `repair`, `schema:status`) are
  unchanged — they're expected to report on the actual on-disk
  state, so they must NOT auto-migrate.

## [2.2.0] — 2026-05-17

The per-field `searchable` flag, present on `Field` since 1.x, was a
documented-but-ignored no-op until this release. It is now load-bearing:
SQLite-FTS5 indexing respects it on every save and rebuild.
Design spec: [`docs/imanager-2.2-plan.md`](docs/imanager-2.2-plan.md).

### Changed

- **`SqliteItemRepository::syncFts()` honors `Field::$searchable`.**
  Only values from fields with `searchable = true` are written into
  `items_fts.body`. `name` and `label` remain structural FTS columns
  and are always indexed regardless. (#58)
- **`FullTextSearch::rebuild()` honors `Field::$searchable`.**
  Now iterates items in PHP after a single bulk-query over the
  `fields` table; the previous single bulk `INSERT … SELECT` could
  not apply the per-category filter. CLI op only, no hot-path
  performance change. (#58)
- **`Field` factory smart defaults for `searchable`.** Prose-typed
  factories (`Field::text()`, `longText()`, `editor()`, `slug()`)
  default to `searchable: true`. Every other factory defaults to
  `false`. Constructor default stays `false` so direct construction
  is backward-compatible. Callers always override via the
  `->searchable(true|false)` fluent setter. (#57)

### Added

- **Migration `0005_searchable_defaults.sql`.** Promotes existing
  `text`/`longtext`/`editor`/`slug` field rows to `searchable = 1`
  on upgrade so installs keep their existing FTS coverage for
  prose content. Verified against the live Scriptor schema: all 8
  text-typed fields (slug, parent, pagetype, menu_title, content,
  template, role, email) promote; password + fileupload fields
  stay at 0. (#58)
- **`FtsBody::compose()`** — single source of truth for the body
  column written into `items_fts`. Used by both the per-save writer
  and the bulk rebuilder so they cannot drift. (#58)
- **`SqliteItemRepository` constructor accepts optional
  `?FieldRepository`** as the third argument. The 2-arg signature
  from 2.0/2.1 keeps working — falls back to "index everything"
  with a one-time `E_USER_DEPRECATED` notice on first FTS write.
  The no-arg form will become an error in 3.0. (#58)

### Upgrading from 2.1.x

After `composer update`, run:

```bash
vendor/bin/imanager fts:rebuild --db=<your.db>
```

> **2.2.1 correction.** As originally written, this command was
> incomplete: on 2.2.0 the rebuild ran *before* the new migration,
> so the body column came out empty. Either upgrade to **2.2.1**
> (where `fts:rebuild` auto-migrates) or run the two steps
> explicitly on 2.2.0:
>
> ```bash
> vendor/bin/imanager schema:migrate --db=<your.db>
> vendor/bin/imanager fts:rebuild   --db=<your.db>
> ```

The migration promotes existing field rows for prose-typed content
so per-save indexing keeps the same coverage, but pre-existing rows
in `items_fts` were written under the old "index everything" rule
and still contain values from fields that are now opted out.
`fts:rebuild` reconciles them.

Side effects of honoring the flag (all deliberate):

- `password`-typed field values stop appearing in FTS (was a bcrypt
  hash; not useful as search content).
- `fileupload`/`imageupload`/`filepicker` paths stop appearing in
  FTS (paths weren't useful search content).
- `integer`/`decimal`/`money`/`datepicker`/`checkbox`/`dropdown`/
  `hidden`/`arrayList` values stop appearing in FTS.

To keep one of those types in FTS, opt back in explicitly:

```php
$fields->ensure(Field::integer($cat->id, 'sku')->searchable(true));
```

### Deprecated

- **`new SqliteItemRepository($pdo, $events)`** (2-arg form).
  Triggers `E_USER_DEPRECATED` on first FTS write. Pass the
  `FieldRepository` as the third argument (or use
  `SqliteStorage::items()`, which wires it for you). The no-arg
  form will be removed in 3.0.

## [2.1.0] — 2026-05-16

Schema-setup ergonomics release. Additive only — every 2.0.x caller
keeps working. Design spec: [`docs/imanager-2.1-plan.md`](docs/imanager-2.1-plan.md).

### Added

- **16 static factories on `Field`** for declarative schema setup —
  `Field::text()`, `longText()`, `editor()`, `slug()`, `password()`,
  `integer()`, `decimal()`, `money()`, `checkbox()`, `dropdown()`,
  `datepicker()`, `hidden()`, `arrayList()`, `file()`, `image()`,
  `filePicker()`. Each returns a fresh (`id = null`) `Field` with the
  corresponding `FieldType` set and default flags. (#47)
- **Fluent setters on `Field` — general (6)**: `required(bool=true)`,
  `indexed(bool=true)`, `searchable(bool=true)`, `position(int)`,
  `label(string)`, `config(array)`. Each returns a new
  `final readonly` clone, preserving the value-object semantics. (#47)
- **Fluent setters on `Field` — type-aware (7)**: `maxLength(int)`,
  `minLength(int)`, `placeholder(string)`, `maxBytes(int)`,
  `mimes(string ...)`, `options(array)`, `format(string)`. Each writes
  one documented key into `config`; unrecognised keys are silently
  ignored by built-in plugins, so a setter that doesn't apply to a
  given `FieldType` is a no-op. (#47)
- **`CategoryRepository::ensure(Category): Category`** — upsert by
  natural key (`slug`). Insert-on-miss, return-existing-on-hit.
  Emits `CategoryCreated` only on insert. (#48)
- **`FieldRepository::ensure(Field): Field`** — upsert by natural key
  `(categoryId, name)`. Same semantics as `CategoryRepository::ensure()`.
  Emits `FieldCreated` only on insert. (#48)
- **`docs/imanager-2.1-plan.md`** — design plan with the full surface
  + naming rationale + open-questions log. (#46)

### Fixed

- **`Imanager::VERSION` is now bumped in lockstep with the git tag.**
  Previously the constant was set to `2.0.0` at 2.0 release and never
  moved at 2.0.1 or 2.0.2 — `vendor/bin/imanager --version` reported
  the wrong value against any newer install. New `ReleaseConsistencyTest`
  asserts the constant matches the top-most `[X.Y.Z]` entry in
  CHANGELOG.md so this can't silently rot again.

### Migration notes

`ensure()` is a new interface method on `CategoryRepository` and
`FieldRepository`. Direct callers of the existing methods need no
changes. **Third-party implementers** of these interfaces (no known
implementers in the wild as of this release) need to add `ensure()`
— the canonical 4-line implementation is documented in the JSDoc
of each interface method.

## [2.0.2] — 2026-05-16

### Added

- **`DefaultBootstrap::boot()` creates missing filesystem roots.**
  `dirname($databasePath)`, `$uploadsPath`, and `$cachePath` are
  `mkdir`'d recursively if they don't exist, so a fresh-project
  copy-paste of the README quickstart no longer trips on PDO's
  `unable to open database file` error. Hosts that hand-wire via
  `Imanager\Bootstrap::boot()` keep full control of directory
  lifecycle — the convenience is specific to the copy-paste factory.
  (#40)

### Fixed

- **`ConnectionFactory::create()` error message names the missing
  parent directory** and suggests `mkdir -p <dir>` (or bootstrapping
  via `DefaultBootstrap`, which now does that itself) when the
  SQLite open fails because the parent doesn't exist. The previous
  error surfaced as the raw PDO "unable to open database file" —
  opaque even to seasoned PHP devs. (#40)

### Changed

- **`composer.json` metadata refreshed**: dropped the stale
  `"flat file"` keyword (2.0 has been SQLite-backed since the
  rewrite) and the redundant self-tag `"imanager"`; replaced with
  `sqlite`, `fts5`, `php`, `framework`, `repository-pattern`,
  `psr-14`. `homepage` switched from a stale third-party domain to
  the GitHub repository. (#36)
- **README tightened**: Status section reduced to a single line plus
  a one-sentence 1.x reference; Concepts → File now describes the
  upload root as `<uploadsPath>/<itemId>/<fieldId>/` (the argument
  passed to `DefaultBootstrap::boot()`) instead of a hardcoded
  `data/uploads-2.0/...` path; "Roadmap & docs" renamed to "Docs"
  and reordered so API reference + cookbooks + deployment lead, with
  the multi-phase implementation plan moved to the bottom under
  "Implementation history". (#35)
- **README quickstart polish**: inline comments on the `Item`
  constructor positional args call out `name` (URL-friendly
  identifier) vs `label` (human-readable title); the read-back loop
  echoes `$item->label` with a `// → Hello, world` trailing
  comment so the expected output is visible at a glance. The
  auto-mkdir paragraph notes the three filesystem roots are created
  on first boot. (#39, #41)
- **`docs/api/domain.md`**: `File` description switched from
  `data/uploads-2.0/...` to `<uploadsPath>/<itemId>/<fieldId>/`,
  aligning the API reference with the library's actual configurable
  surface. (#42)
- **CHANGELOG 2.0.0 history cleaned for consistency**: two upload-
  root references switched to `<uploadsPath>/...`, and the Phase 16
  header dropped its stale "(in progress)" parenthetical. (#38)

### Removed

- **All in-tree references to a specific consumer dropped.** Library
  docs, code comments, and migration test fixtures now read as
  host-neutral, in line with the standing independence rule that
  iManager never names its consumers. Fixture class names
  generalised from a real-world host's namespace to a placeholder
  `\LegacyHost\Page`. Test surface unchanged. (#37)

## [2.0.1] — 2026-05-15

### Fixed

- `imanager dump` silently skipped FTS5 virtual tables. The dump
  emitted `schema_version` rows for the FTS migration but no
  `CREATE VIRTUAL TABLE items_fts` statement, so a freshly restored
  database had no `items_fts` table — and the next item write blew
  up with `SQLSTATE[HY000]: General error: 1 no such table: items_fts`.
  The dump now includes the parent virtual-table CREATE plus its rows
  (preserving `rowid`, which links FTS entries to their items), and
  excludes the auto-managed shadow tables (`*_data`, `*_idx`,
  `*_config`, `*_docsize`, `*_content` for FTS5; the same
  `<parent>_<suffix>` pattern for any future RTREE/etc. virtual
  tables) — those re-create themselves on restore from the parent
  CREATE.

## [2.0.0] — 2026-05-13

Initial stable release of iManager as a standalone library — extracted
and rewritten from a previous-generation embedded library. This entry
collects every phase of the rewrite into a single release note;
subsequent versions will add their own headers above this one.

### Phase 0 — Infrastructure & CI

- Initial repository scaffold.
- Composer package `bigins/imanager`, PSR-4 autoload `Imanager\\` → `src/`.
- Dev tooling: PHPUnit 11, PHPStan level 8, Psalm level 3, PHP-CS-Fixer.
- Runtime dependencies: `league/container`, `symfony/console`,
  `nikic/php-parser`, `erusev/parsedown`, `ezyang/htmlpurifier`, `psr/log`.
- GitHub Actions CI: PHP 8.2/8.3 matrix on Linux.
- Dockerfile (PHP 8.3 CLI + SQLite + Composer) and `docker-compose.yml`.
- Smoke test for autoload + version constant.
- CLI entrypoint stub at `bin/imanager`.

### Phase 2 — Architectural foundations

- Typed exception hierarchy under `Imanager\Exception\` (root
  `ImanagerException` + leaf types for storage, query, validation,
  field, migration).
- Domain enums (`Imanager\Enum\`) replacing the loose string/int
  constants of 1.x.
- Immutable `Imanager\Config` value object loaded by `Bootstrap`.
- `Imanager\Bootstrap` and `Imanager\Imanager` facade wiring the
  container; `league/container` as the PSR-11 DI implementation.

### Phase 3 — Storage abstraction

- Repository contracts: `CategoryRepository`, `FieldRepository`,
  `ItemRepository`, `FileRepository` (read + write halves separated).
- `Imanager\Storage\InMemory\*` reference implementation used as the
  contract test bed and during early unit tests.
- `SchemaManager` and migration-runner skeleton — drives versioned
  schema files (`config/schema/0001_…`).
- Repository contract test suite re-used by every backend.

### Phase 4 — SQLite storage backend

- Production `Imanager\Storage\Sqlite\*` repositories on top of `PDO`.
- JSON `items.data` column + generated columns for hot fields;
  generated-column index lifecycle managed alongside field
  add/remove/rename.
- Atomic write transactions replacing 1.x's flock-based persistence.
- Schema migration `0001_initial` shipped.

### Phase 5 — Query layer

- Immutable query AST in `Imanager\Query\Ast`.
- Fluent `QueryBuilder` and `SelectorParser` covering the 1.x
  selector syntax (clauses with `=`/`!=`/`>`/`<`/`>=`/`<=`).
- Repository `find()` consumes the AST; pagination + ordering are AST
  nodes, not stringly-typed flags.

### Phase 6 — Domain layer

- Typed `Item`, `Category`, `Field`, `File` value objects with
  constructor invariants — no more partially-constructed entities.
- `FieldValueBag` with explicit `has`/`get`/`getAs<T>` access; replaces
  1.x's array-as-bag idiom.
- `Imanager\Domain\Event\*` value objects (Created, Updated, Deleted
  per aggregate) emitted via the repositories in Phase 14e-1.

### Phase 7 — Field-type system

- **7a** — clean-room `Sanitizer` (no ProcessWire residue), typed
  per-context methods (text, slug, email, html, template-name, …).
- **7b** — `FieldTypePlugin` interface, `FieldTypeRegistry`, six
  built-in types ported (text, textarea, slug, email, integer, date).
- **7c** — remaining 10 built-in types (page, image/fileupload,
  select, multiselect, datetime, boolean, decimal, url, color, json),
  closing the 16-type 1.x parity.

### Phase 8 — Full-text search

- SQLite FTS5 virtual table `items_fts`, kept in sync with `items` via
  triggers (schema migration `0002_fts`).
- `Imanager\Search\FullTextSearch` service: per-category search with
  result ranking, snippet, and AND/OR token parsing.
- `fts:rebuild` CLI command (initially under Phase 15) reseeds the
  index from `items`.

### Phase 9 — 1.x → 2.0 migration tool

- `Imanager\Migration\V1FileParser` reads 1.x's flat `buffers/` PHP
  files via `nikic/php-parser` (AST-safe, no `eval`/`include` of user
  data); `Imanager\Migration\JsonV1Importer` walks the parsed buffers
  and writes the new schema inside a single transaction.
- Field-type-aware value translation; `--dry-run` produces a report.
- Asset copy from legacy `data/uploads/<cat>.<id>.<field>/` into the
  new `<uploadsPath>/<itemId>/<fieldId>/` layout
  (`uploadsPath` = whatever the host passes to `DefaultBootstrap::boot()`).

### Phase 10 — HTTP / input layer

- Typed `Imanager\Http\Request` (immutable, lazy parameter access).
- `UrlSegments`, `Csrf` (per-form token store), `NativeSessionStore`.
- Replaces 1.x's `$_GET`/`$_POST` direct reads and `Util::redirect`
  CRLF surface.

### Phase 11 — Templates & pagination

- Typed `{{var}}` template renderer (`Imanager\Templating\TemplateRenderer`)
  with explicit `render(string $template, array $vars = []): string`
  contract, plus `renderFile()` for path-based templates.
- Clean `PaginationRenderer` decoupled from `Items` (1.x had pagination
  state on the collection itself).

### Phase 12 — Section cache (PSR-16)

- `Imanager\Cache\FilesystemCache` implementing PSR-16
  `CacheInterface`; HTML snippet caching with TTL + atomic write.
- Invalidation hook published as a domain event so host listeners
  can clear cached entries on writes without monkey-patching the
  storage layer.

### Phase 13 — Files & upload

- `Imanager\Files\UploadHandler`, `UploadedFile`, `UploadConstraints`
  (size / mime / extension); typed validation results.
- `Imanager\Files\ImageProcessor` (GD): resize, crop, on-demand
  thumbnail materialisation.
- `LocalFileStorage` writes originals + `thumbnail/<W>x<H>_<file>`
  under `<uploadsPath>/<itemId>/<fieldId>/`.
- Schema migration `0003_files` adds the `files` table.

### Phase 14e-1 — Domain event dispatcher

- PSR-14 `EventDispatcherInterface` adopted; `SyncEventDispatcher` and
  `NullEventDispatcher` provided.
- All repositories emit `*Created` / `*Updated` / `*Deleted` events
  through the dispatcher; subscribers register via
  `SubscriberListenerProvider`.

### Phase 15 — CLI

- `vendor/bin/imanager` powered by `symfony/console`,
  `Imanager\Cli\Application` wiring.
- Commands: `schema:status`, `schema:migrate`, `migrate:from-v1`,
  `fts:rebuild`, `optimize`, `repair`, `dump`.
- Each command has an integration test running against a temporary
  SQLite file.

### Post-Phase 14e — Image titles + file ordering

- Schema migration `0004_files_title` adds `files.title` (image
  captions / alt text); `File` domain object + `FileRepository`
  updated; full test coverage (`feature/file-title-column`).
- `feat(files): File::withPosition()` helper for ordered file
  re-numbering — supports drag-and-drop reordering in host editor
  UIs without re-saving the surrounding item (#21).

### Phase 16 — Docs & examples

- `DefaultBootstrap` factory wires the full standard service graph
  (PDO + schema migrations, Storage + 4 repositories, FieldTypeRegistry
  with all 16 built-ins, FilesystemCache, LocalFileStorage,
  ImageProcessor, NativeSessionStore, Csrf, PSR-14 dispatcher trio).
  `Bootstrap::boot()` stays minimal for hosts that want to swap any
  layer. Full test coverage in `tests/Unit/DefaultBootstrapTest`.
- `README.md` rewrite — quickstart with runnable Blog example
  (verified against contract tests), concepts section, CLI table,
  roadmap pointers.
- `docs/migration-guide.md` — 1.x → 2.0 walkthrough with dry-run,
  real, verify, and app-switch steps. Documents the deferred
  parent-id re-mapping issue and its SQL workaround.
- `docs/api/` — API reference: index of 13 subsystems plus core
  detail pages for Domain (`domain.md`), Storage (`storage.md`),
  Query (`query.md`), and Field types (`field-types.md`). Examples
  lifted from `tests/Unit/Storage/*Contract.php` so every snippet
  compiles and is exercised on CI.
- `docs/field-types.md` — how-to cookbook companion to the
  Field-types reference: anatomy, validation pipeline, render
  patterns, end-to-end custom-plugin walkthrough
  (money-with-currency), registration, testing, common pitfalls.
- `docs/query-cookbook.md` — how-to cookbook companion to the
  Query reference: predicate recipes (structural columns vs JSON
  fields, ranges, `LIKE` semantics, the "OR-shaped" problem),
  sorting with stable tiebreakers, pagination with `count()` +
  `Pagination`, selector strings, FTS hand-off pattern, and a
  performance section covering hot/cold fields, wildcard cost,
  offset depth, and partial indexes.
- `docs/deployment.md` — production deployment guide: host
  requirements, recommended `var/{data,uploads,cache}` filesystem
  layout, copy-pasteable Caddy and nginx + PHP-FPM configs, a
  starter production Dockerfile (PHP 8.3-FPM-Alpine with
  production opcache + composer install), SQLite-at-runtime notes
  (the four PRAGMAs the library applies on connect; WAL
  sidecars), backup strategy with `sqlite3 .backup`, scheduled
  maintenance (`optimize` weekly, `optimize --vacuum` quarterly,
  `repair` monthly), PSR-3 logger hookup, and a pre-launch
  checklist.
- `docs(api/storage,field-types,domain): correct the
  "ItemRepository validates" claim` — the API reference, the
  field-types cookbook, and the domain reference all claimed
  `ItemRepository::save()` routes values through the registered
  `FieldTypePlugin`s. It doesn't. `save()` writes `$item->data`
  verbatim; validation is a host responsibility. Replaced the false
  claims with the canonical "validate then save" loop showing how
  hosts wire `FieldTypeRegistry::get($field->type)->validate(...)`
  before constructing the `Item`. Adjacent statements about
  `$field->required` and the lifecycle of `ValidationResult` /
  `ValidationException` corrected to match.
- `docs: drop references to a specific consumer throughout` —
  library docs read as host-neutral; Phase 14 reframed as
  "First-Consumer-Cutover (Release-Gate)"; consumer-side cutover
  detail plan moved out of this repo.
- `docs(api/field-types): correct InputErrorCode case list` — the
  initial reference page invented enum cases (`Required`,
  `OutOfRange`, `PatternMismatch`, …); the actual enum has
  `EmptyRequired`, `MinLengthExceeded`, `MaxLengthExceeded`,
  `WrongValueFormat`, `ComparisonFailed`, `UndefinedCategoryId`.
  Reference now quotes the enum verbatim.

### Phase 17 prep — deferred fixes

- `fix(migration): re-map cross-item id references via
  --remap-fields` — `migrate:from-v1` now accepts a JSON config
  declaring `{ categorySlug: { fieldName: targetCategorySlug } }`.
  After the standard import pass, the importer walks each declared
  field and rewrites stored values from old item ids to the new ids
  assigned during the import — the canonical self-referential
  `parent` field on a tree of items now round-trips cleanly. The
  remap runs inside the same transaction as the rest of the import,
  preserves the `0` root sentinel, skips non-numeric values, warns
  on dangling pointers, and is idempotent against already-mapped
  data. `ImportReport` gains an `itemsRemapped` counter that
  surfaces in the CLI report. Replaces the SQL-`json_set`
  workaround documented in `migration-guide.md`.
