# API Reference

Reference documentation for the iManager 2.0 library — every public class
you can wire, extend, or call from a host application.

> **Where to start:** the [README quickstart](../../README.md#quickstart)
> shows the shortest path from `composer require` to a saved item using
> `Imanager\DefaultBootstrap::boot()`. This reference picks up from
> there. It documents the *contracts* you can program against, not the
> SQLite internals.

---

## Core (covered in detail here)

The four pages below cover ~90 % of what host code touches. Read them
in order if you're new — each one builds on the previous.

- **[Domain](domain.md)** — `Category`, `Field`, `Item`, `File`,
  `FieldValueBag`, and the nine domain events (`*Created`, `*Updated`,
  `*Deleted`). All domain objects are `final readonly`; you mutate by
  saving a new value.
- **[Storage](storage.md)** — the `Storage` interface and its four
  repositories (`CategoryRepository`, `FieldRepository`,
  `ItemRepository`, `FileRepository`), `SqliteStorage` (the only
  bundled implementation), and the `transactional()` boundary. Also
  covers `SchemaManager` and `Migration`.
- **[Query](query.md)** — the immutable `Query` builder, the
  `Clause` / `Operator` / `OrderBy` / `Direction` value objects,
  `Pagination`, and the `SelectorParser` shorthand
  (`name=Hello*, position>=5`).
- **[Field types](field-types.md)** — the `FieldTypePlugin`
  interface, `FieldTypeRegistry`, the 16 built-in plugins, and the
  `ValidationResult` / `RenderContext` value objects you return /
  receive when writing your own type.

---

## Subsystem index

Every namespace under `Imanager\` at a glance. The "Reference" column
either points to the detail page or — for the smaller subsystems —
tells you which source file to read next.

| Namespace | What it does | Reference |
|---|---|---|
| `Imanager\Domain` | Typed primitives (`Category`, `Field`, `Item`, `File`) and domain events. The whole model is `final readonly`. | [Domain](domain.md) |
| `Imanager\Storage` | Repository contracts and the SQLite implementation. Items are persisted as a `JSON` column with hot fields promoted to generated columns. | [Storage](storage.md) |
| `Imanager\Query` | Immutable query builder + `SelectorParser` string DSL. `ItemRepository::query()` is the single execution entrypoint. | [Query](query.md) |
| `Imanager\Field` | Field-type plugin system: 16 built-in types and the registry that resolves them by name. | [Field types](field-types.md) |
| `Imanager\Enum` | `FieldType` (the 16 built-in enum cases), `SqliteAffinity` (storage class hint), `InputErrorCode` (validation error codes). | `src/Enum/` |
| `Imanager\Files` | File-storage abstraction (`FileStorage` interface, `LocalFileStorage`), upload validation, and `ImageProcessor` for on-demand thumbnails. `FileRepository` (under `Storage`) tracks file *metadata*; this subsystem moves the bytes. | `src/Files/` |
| `Imanager\Http` | Small request-layer toolkit: `SessionStore` (with `NativeSessionStore` default), `Csrf` (per-form tokens, capped LRU), and request/URL helpers. iManager does *not* ship a router. | `src/Http/` |
| `Imanager\Cache` | PSR-16 cache contract and `FilesystemCache` (hash-keyed, two-level directory fanout, TTL metadata in-file). Hosts wire it to whatever they want to cache (rendered fragments, query results, …); the library itself stays uncached for predictability. | `src/Cache/` |
| `Imanager\Search` | `FullTextSearch` over a SQLite FTS5 mirror of `items`. CLI command `fts:rebuild` rebuilds the index from scratch. | `src/Search/` |
| `Imanager\Templating` | Single-purpose `{{var}}` substitution for short strings (pagination links, alerts). Caller is responsible for escaping. **Not** a view layer. | `src/Templating/` |
| `Imanager\Validation` | `Sanitizer` facade over HTMLPurifier (sanitize) and Parsedown (markdown). Pure functions; safe to call repeatedly. | `src/Validation/` |
| `Imanager\Events` | PSR-14 dispatcher (`SyncEventDispatcher`) and provider (`SubscriberListenerProvider`). Domain events flow through this; subscribe in your bootstrap. | `src/Events/` |
| `Imanager\Exception` | Hierarchy rooted at the `ImanagerException` marker interface: `StorageException`, `ValidationException`, `NotFoundException`, `SchemaException`, `FieldTypeNotRegisteredException`. Catch the marker if you want a single net. | `src/Exception/` |
| `Imanager\Cli` | Symfony Console application (`vendor/bin/imanager`). Commands: `schema:status`, `schema:migrate`, `migrate:from-v1`, `fts:rebuild`, `optimize`, `repair`, `dump`. | `src/Cli/` and the [README CLI table](../../README.md#cli) |

---

## Conventions used in this reference

- **`final readonly`** — every domain primitive and most value objects
  are immutable. Operations like `withId()`, `withTitle()`, or the
  builder methods on `Query` return a new instance.
- **Method signatures** are quoted **verbatim** from the source — same
  parameter names, defaults, and return types. If something looks
  surprising, check the file path at the top of each page; the source
  wins, this doc follows.
- **Examples** are lifted from the contract tests under
  `tests/Unit/Storage/*Contract.php`. Those tests run against both
  `SqliteStorage` and the `InMemory` storage shipped for testing — so
  every example you see compiles and is exercised on every CI run.
- **Exceptions thrown** are listed under each method that can throw.
  Methods without a "Throws" line never throw in normal operation
  (errors surface as `null` returns or empty lists where appropriate).

---

## Roadmap

The four core pages cover the public API any application boots
against. Still to come in Phase 16:

- `docs/field-types.md` — **cookbook** (distinct from
  `docs/api/field-types.md` reference): how to write a custom field
  type end-to-end, including form rendering and validation patterns.
- `docs/query-cookbook.md` — recipes for the `Query` builder and
  selector strings (faceted lookups, pagination flows, FTS hand-off).
- `docs/deployment.md` — production deployment (Caddy / nginx, file
  permissions, backup, scheduled `optimize`).

The smaller subsystems (Cache, Templating, Http, Events, Validation)
will get their own reference pages **only if** non-trivial host
extension is expected — for now their source files are short and
documented inline.
