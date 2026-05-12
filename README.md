# iManager

> Embeddable, SQLite-backed Content Management Framework for PHP.

[![CI](https://github.com/bigin/imanager/actions/workflows/ci.yml/badge.svg)](https://github.com/bigin/imanager/actions/workflows/ci.yml)

iManager is a small CMS **framework**, not a CMS application: you embed
it inside your own PHP app and get a typed domain model, a Repository
layer over SQLite (JSON columns + FTS5), a Field-Type plugin system,
file storage with on-demand thumbnails, and a CLI for schema and
migration ops. The reference consumer is
[Scriptor](https://github.com/bigin/Scriptor), a flat-file CMS that
boots iManager and adds an Editor UI on top of it.

---

## Status

**🚧 2.0 development in progress — not for production use.**

iManager 2.0 is a ground-up rewrite of the iManager library that
shipped with Scriptor ≤ 1.x. The 2.0 line replaces the flat
`var_export`-based persistence with SQLite (JSON `items.data` column +
generated columns + FTS5), introduces typed domain models, a Repository
/ Query layer, a CLI tool, and a clean field-type plugin system. The
2.0.0 Packagist tag is targeted for Phase 17 — until then,
`bigins/imanager:2.0.x-dev` is the way to consume it.

For the current production-ready 1.x line, use Scriptor ≤ 1.x.

---

## Quickstart

Install via Composer (you'll need [`minimum-stability: dev`](https://getcomposer.org/doc/04-schema.md#minimum-stability)
until 2.0.0 is tagged):

```bash
composer require bigins/imanager:2.0.x-dev
```

Boot the full standard service graph with `DefaultBootstrap::boot()`
and start using the repositories:

```php
require __DIR__ . '/vendor/autoload.php';

use Imanager\DefaultBootstrap;
use Imanager\Domain\Category;
use Imanager\Domain\Field;
use Imanager\Domain\Item;
use Imanager\Enum\FieldType;
use Imanager\Storage\CategoryRepository;
use Imanager\Storage\FieldRepository;
use Imanager\Storage\ItemRepository;

$container = DefaultBootstrap::boot(
    databasePath: __DIR__ . '/data/imanager.db',
    uploadsPath:  __DIR__ . '/data/uploads',
    uploadsUrl:   '/uploads',
    cachePath:    __DIR__ . '/data/cache',
);

$categories = $container->get(CategoryRepository::class);
$fields     = $container->get(FieldRepository::class);
$items      = $container->get(ItemRepository::class);

// One-time setup: declare a category and its fields.
$blog = $categories->save(new Category(null, 'Blog', 'blog'));
$fields->save(new Field(null, $blog->id, 'title', 'Title', FieldType::Text));
$fields->save(new Field(null, $blog->id, 'body',  'Body',  FieldType::LongText));

// Persist an item.
$items->save(new Item(
    null,
    $blog->id,
    'hello-world',
    'Hello, world',
    data: ['title' => 'Hello, world', 'body' => 'First post.'],
));

// Read back.
foreach ($items->findByCategory($blog->id) as $item) {
    echo $item->name . "\n";
}
```

`DefaultBootstrap` runs the SQLite schema migrations on first use, so
the database file is created and populated automatically. Subsequent
`composer update` runs pick up new migrations the same way.

Need a leaner container or want to swap PDO / FileStorage / the
event dispatcher? Use `Imanager\Bootstrap::boot()` instead and wire
the parts you want — `DefaultBootstrap` is just a copy-paste-saver
on top of it.

---

## Concepts

iManager models content as four primitives:

- **Category** — a kind of thing (e.g. *Blog*, *Page*, *User*). Each
  category has its own field schema and its own slug.
- **Field** — a typed column on a category. The built-in field types
  are: `text`, `longtext`, `editor`, `slug`, `password`, `integer`,
  `decimal`, `money`, `checkbox`, `dropdown`, `datepicker`, `hidden`,
  `array`, `fileupload`, `imageupload`, `filepicker`. Custom types
  register via the `FieldTypePlugin` interface.
- **Item** — an instance of a category. Field values live in a typed
  `FieldValueBag` exposed as `$item->data`; hot fields are also
  promoted to SQLite generated columns for indexable queries.
- **File** — a binary asset (upload). Files are stored under
  `data/uploads-2.0/<itemId>/<fieldId>/`, with on-demand thumbnails
  for image uploads under `thumbnail/<W>x<H>_<file>`.

Domain mutations (`*Created` / `*Updated` / `*Deleted` events) are
published through a PSR-14 dispatcher so host applications can hook
into them (cache invalidation, file cleanup, etc.) without monkey-
patching the storage layer.

---

## CLI

iManager ships a Symfony-Console CLI at `vendor/bin/imanager` for
operational tasks. The same commands run inside the Docker dev
container (`docker compose run --rm imanager vendor/bin/imanager …`).

| Command | What it does |
|---|---|
| `schema:status`  | Show applied + pending schema migrations. |
| `schema:migrate` | Apply pending migrations. |
| `migrate:from-v1`| One-shot import of a 1.x `data/datasets/buffers/` tree. Supports `--dry-run`. |
| `fts:rebuild`    | Drop & rebuild the FTS5 index from `items`. |
| `optimize`       | `PRAGMA optimize` + `VACUUM`. |
| `repair`         | Integrity checks (orphan items, broken FKs, FTS sync). |
| `dump`           | Portable SQL dump. |

---

## Requirements

- PHP **8.2+**
- Extensions: `pdo_sqlite`, `mbstring`, `gd`, `dom`, `json`
- Composer 2

---

## Development

The repo ships with a Docker-based dev environment (PHP 8.3 CLI +
SQLite + Composer). You don't need anything else on your host
machine.

```bash
docker compose build
docker compose run --rm imanager composer install
docker compose run --rm imanager composer ci
```

Available composer scripts:

| Script | Description |
|---|---|
| `composer test`   | Run PHPUnit. |
| `composer lint`   | Run PHP-CS-Fixer in dry-run. |
| `composer format` | Auto-format with PHP-CS-Fixer. |
| `composer stan`   | Static analysis (PHPStan, level 8). |
| `composer psalm`  | Static analysis (Psalm, level 3). |
| `composer ci`     | Full pipeline (lint + stan + psalm + test). |

---

## Roadmap & docs

- **Master plan** (multi-phase rewrite, phase status):
  [`docs/imanager-2.0-plan.md`](docs/imanager-2.0-plan.md).
- **Phase 14 detail plan** (Scriptor integration):
  [`docs/imanager-2.0-phase-14-plan.md`](docs/imanager-2.0-phase-14-plan.md).
- **Changelog**: [`CHANGELOG.md`](CHANGELOG.md).
- API reference, migration guide (1.x → 2.0), deployment guide,
  field-type cookbook, and query cookbook are landing during Phase 16.

---

## License

[MIT](LICENSE) — © bigin / Juri Ehret
