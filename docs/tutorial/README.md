# Tutorial

Task-oriented walkthroughs for iManager 2. Each chapter answers
**one** "how do I X?" question with a small, self-contained example.
They're meant for someone who has read the [project README](../../README.md)
and run the Quickstart, and now wants to do something *concrete*.

If you're looking for a class-by-class reference instead, the
encyclopedia lives under [`docs/api/`](../api/).

## Who this is for

PHP developers picking up iManager for the first time. The chapters
assume you've used Composer and `vendor/autoload.php` before, but
they spell out iManager-specific concepts (the DI container, the
field-type plugin system, the validation contract) the first time
they show up.

## Recommended reading order

If you're brand new, read top-down. Each chapter introduces the
machinery the next one builds on.

| Chapter | What you'll learn | Mini-case |
|---|---|---|
| [Setup your first install](setup.md) | What `DefaultBootstrap::boot()` wires up, what each of the four paths means, write and read your first item end-to-end. | A one-table notebook (`Note` category, one `body` field). |
| [Design a content schema](schema.md) | Pick the right `FieldType` for each column, what `indexed` and `searchable` actually do under the hood (generated columns + FTS5 with real SQL), idempotent setup patterns so re-running your migration is safe. | A blog (`Post` category with title, slug, body, publish date, cover image). |
| [Validate user input before saving](validation.md) | The canonical `FieldTypeRegistry::get($type)->validate()` loop, how to collect errors across a whole form, where iManager's contract ends and your application code takes over. | A contact form (name + email + subject + message), validated server-side. |
| [Mutate and delete data](lifecycle.md) | The three repository verbs (`save`, `ensure`, `delete`), event-firing order, what cascades automatically (and what doesn't: orphan generated columns, surviving file bytes), wiring file cleanup yourself, why iManager has no soft-delete. | A bookmark vault (`Bookmark` category with title, url, cover image). |
| [Upload files and generate thumbnails](files.md) | The four-part upload pipeline (`UploadedFile`, `UploadConstraints`, `UploadHandler`, `FileStorage`/`FileRepository`), MIME sniffing, the common "constraints in two places" gotcha, lazy thumbnail generation via `ImageProcessor`, swapping the storage backend. | A photo gallery (`Gallery` category with title + image upload). |
| [Full-text search](search.md) | `FullTextSearch::search()` + `count()` + `rebuild()`, the FTS5 query language (implicit AND, phrases, prefix, boolean, column-restricted), category scoping, pagination, the negative-`rank` quirk on `SearchHit`, why combining FTS hits with `Query` predicates is honestly clunky today, snippet rendering. | A knowledge base (`Article` category with title + body + tags). |

The event-system mechanics are already covered in the lifecycle
chapter, so a dedicated `events.md` is **not** planned right now.
Common listener patterns might land later as a small cookbook
appendix if a real use case asks for it.

## Going deeper

When a tutorial chapter mentions a method, you can always click
through to the reference page for the full surface:

- [`docs/api/domain.md`](../api/domain.md), `Category`, `Field`, `Item`,
  `FieldValueBag`, `File`, plus the nine domain events.
- [`docs/api/storage.md`](../api/storage.md), the three repositories
  and the `Query` type they consume.
- [`docs/api/field-types.md`](../api/field-types.md), every built-in
  field type, its config keys, and its coerced storage shape.
- [`docs/api/query.md`](../api/query.md), the `Query` builder API.
- [`docs/query-cookbook.md`](../query-cookbook.md), predicate
  recipes, pagination, selector strings, full-text-search hand-off.
- [`docs/field-types.md`](../field-types.md), how to write your own
  `FieldTypePlugin`.
- [`docs/migration-guide.md`](../migration-guide.md), upgrading a
  1.x install.
- [`docs/deployment.md`](../deployment.md), production hosting and
  scheduled maintenance.

The chapters here are the *guided tour*; those pages are the
*encyclopedia*. Pick whichever matches what you're doing.
