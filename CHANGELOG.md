# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

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
  new `data/uploads-2.0/<itemId>/<fieldId>/` layout.

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
  under `data/uploads-2.0/<itemId>/<fieldId>/`.
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

### Phase 16 — Docs & examples (in progress)

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
- `docs: drop references to a specific consumer (Scriptor)
  throughout` — library docs read as host-neutral; Phase 14
  reframed as "First-Consumer-Cutover (Release-Gate)"; consumer-
  side cutover detail plan moved out of this repo.
- `docs(api/field-types): correct InputErrorCode case list` — the
  initial reference page invented enum cases (`Required`,
  `OutOfRange`, `PatternMismatch`, …); the actual enum has
  `EmptyRequired`, `MinLengthExceeded`, `MaxLengthExceeded`,
  `WrongValueFormat`, `ComparisonFailed`, `UndefinedCategoryId`.
  Reference now quotes the enum verbatim.
