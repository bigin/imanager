# Migration Guide — iManager 1.x → 2.0

> **Time budget:** about 30 minutes for a typical install (a handful of
> categories, a few hundred items, a few MB of assets).
> **Scope:** this guide covers **data migration** only. iManager 2.0 is a
> ground-up rewrite: the application code that consumes the library
> needs separate work (see [README §Quickstart](../README.md#quickstart)
> for the new API shape).

---

## Before you start

1. **Take a full backup of your 1.x install.** At minimum:

   ```bash
   cp -a data data.bak.$(date +%F)
   ```

   The migrator never writes back to `data/`, but the surrounding work
   (composer updates, app-code switch) does. The backup is your one
   piece of insurance.

2. **Check the host environment.** iManager 2.0 requires:

   - PHP **8.2+**
   - Extensions: `pdo_sqlite`, `mbstring`, `gd`, `dom`, `json`
   - Composer 2

3. **Install iManager 2.0** (separate from the running 1.x install
   until you're ready to switch):

   ```bash
   composer require bigins/imanager:^2.0
   ```

---

## Step 1 — Identify the 1.x data directory

The migrator expects a directory shaped like iManager 1.x's `data/`:

```
data/
├── datasets/
│   └── buffers/
│       ├── categories/categories.php
│       ├── fields/<categoryId>.fields.php
│       └── items/<categoryId>.items.php
└── uploads/
    └── <categoryId>.<itemId>.<fieldId>/<file>
```

Pass the path to `data/` itself (not to `data/datasets/buffers/`) as
`--source`.

---

## Step 2 — Dry run first

A dry run executes the full import inside a transaction and rolls it
back at the end. No SQLite file is written, no assets are copied:
you only get the report.

```bash
vendor/bin/imanager migrate:from-v1 \
  --source ./data \
  --target ./data-new/imanager.db \
  --dry-run
```

Expected output (numbers will differ for your install):

```
 iManager migration — dry run
 ============================

 Source:        ./data
 Target DB:     ./data-new/imanager.db
 Upload target: ./data-new/uploads
 Remap fields:  (none)

 ! [NOTE] Applied 5 schema migration(s) to target

 Result
 ------

 Categories  : 2
 Fields      : 10
 Items       : 9
 Remapped    : 0
 Assets      : 12
 Errors      : 0
 Warnings    : 0
 Rolled back : yes

 [OK] Imported 2 categories, 10 fields, 9 items, 12 assets (rolled back)
```

> If your install has cross-item id references (the typical case is
> a self-referential `parent` field on a tree-shaped category), drop
> a JSON config and re-run the dry run with `--remap-fields`. See
> [Re-mapping cross-item id references](#re-mapping-cross-item-id-references---remap-fields)
> below.

**If `Errors > 0`:** read the `Errors` section the CLI prints and fix
the source data first. Common causes are listed under
[Common issues](#common-issues) below.

**If `Warnings > 0`:** read them but they don't block the real run.

---

## Step 3 — Real migration

When the dry run is clean, drop `--dry-run`:

```bash
vendor/bin/imanager migrate:from-v1 \
  --source ./data \
  --target ./data-new/imanager.db
```

What happens:

1. The target SQLite file is created (or opened) and any pending
   schema migrations are applied.
2. `Imanager\Migration\V1FileParser` reads the legacy `*.php` files
   using `nikic/php-parser`. The AST is walked for literal values
   only, so a tampered `var_export` file cannot execute host code.
3. `Imanager\Migration\JsonV1Importer` walks the buffers in order:
   categories → fields → items. Everything happens inside a single
   SQLite transaction, so a failure mid-way leaves the target DB
   untouched.
4. Assets are copied from `data/uploads/<cat>.<id>.<field>/` into the
   new layout `<upload-target>/<itemId>/<fieldId>/`. Default
   `--upload-target` is `dirname(<target-db>)/uploads`.

The final line is the success summary; non-zero exit code means
errors blocked the import.

---

## Step 4 — Verify

```bash
vendor/bin/imanager schema:status --db ./data-new/imanager.db
```

You should see all five migrations applied: `0001_initial`, `0002_fts`,
`0003_files`, `0004_files_title`, `0005_searchable_defaults`.

Spot-check item counts against the source. A quick way:

```bash
sqlite3 ./data-new/imanager.db \
  "SELECT c.slug, COUNT(*) FROM items i
   JOIN categories c ON c.id = i.category_id
   GROUP BY c.slug ORDER BY c.slug;"
```

Compare those counts against the number of entries inside each
`data/datasets/buffers/items/<id>.items.php` file.

---

## Step 5 — Switch the application

iManager 1.x and 2.0 share a name and a problem domain; they share
**no API**. Switching the consumer is application work, not data
work:

- Drop the 1.x `Imanager\…` includes / autoload paths.
- Boot the new container, typically a single
  `Imanager\DefaultBootstrap::boot(…)` call. See
  [README §Quickstart](../README.md#quickstart).
- Replace 1.x calls (`new Items()`, `getCategories()`, …) with the
  Repository API (`ItemRepository`, `CategoryRepository`, …).
- If you use the FTS, rebuild the index after the first successful
  boot (`vendor/bin/imanager fts:rebuild`).

The shape of this work is host-specific: what calls you replace,
in what order, and how you stage the cutover depends on the
application sitting on top of iManager. The repository / event /
field-type APIs are stable; the wiring around them is yours to
design.

---

## Re-mapping cross-item id references (`--remap-fields`)

The CLI **renumbers** item IDs as it imports: the new SQLite ID is
not the same as the 1.x ID. By default, item-id references stored
inside field values (e.g. a `parent` field whose value is the old
ID of another item) keep the **old** value after migration, which
leaves a dangling pointer.

The canonical case is a self-referential `parent` field on a tree
of items (pages, categories, comments). To rewrite those references
to the new IDs, pass `--remap-fields` with a small JSON config
file:

```json
{
    "pages":    { "parent": "pages" },
    "comments": { "post":   "blog"  }
}
```

The shape is `categorySlug → fieldName → targetCategorySlug`.
Reads as: "in the `pages` category, the `parent` field's value is
an item id in the `pages` category". Run:

```bash
vendor/bin/imanager migrate:from-v1 \
  --source ./data \
  --target ./data-new/imanager.db \
  --remap-fields ./remap.json
```

The importer:

1. Imports categories, fields, items as usual, recording the
   per-category `old item id → new item id` map along the way.
2. After all items are inserted, walks the items in each
   `categorySlug` declared in the config; for each `fieldName`, it
   reads the (still-old) value and rewrites it to the new id using
   the recorded map of `targetCategorySlug`.
3. Reports the number of rewrites in the `Remapped` row of the
   final report.

Both passes run inside one SQLite transaction: a failure in the
remap rolls the whole migration back, same as any other import
error.

### Values left untouched

The remap pass deliberately preserves:

- **`0` (or `'0'`, or `null`, or `''`):** the canonical "no
  parent / root" sentinel for tree-shaped data.
- **Non-numeric values:** anything that doesn't coerce to a
  positive integer is skipped (no false-positives on accidental
  matches).
- **Already-correct values:** re-running the remap against
  already-mapped data is a no-op.

### Dangling references

If a field value points at an old id that the new database doesn't
carry (because the referenced item was missing from the source, or
the source data was inconsistent), the importer **leaves the value
in place** and adds a warning to the final report. It's an
informational signal, not a fatal error: the value rounds back to
the old id, the same shape your data had before.

### When you don't need this

If your 1.x install has no cross-item id references in any field
values, the flag is unnecessary, omit it and the second pass is
skipped entirely. Most simple flat-file installs fall into this
bucket. The remap is a forward-fix for the **tree-shaped /
relational** subset of 1.x data.

---

## Rollback

- **After a dry run:** nothing to roll back, the transaction was
  reverted before commit.
- **After a real run:** the target DB and `--upload-target` directory
  are the only things the migrator created. Delete both and start
  over, or restore from your backup (the source `data/` was never
  touched).

---

## Common issues

- **"Source directory does not exist":** pass `--source` as the path
  to `data/` itself, not to `data/datasets/buffers/`.
- **"`--source` is required":** both `--source` and `--target` are
  mandatory; the CLI fails fast with `INVALID` if either is missing.
- **GD missing:** without `ext-gd` the import still copies assets,
  but on-demand thumbnails won't render on the new install. Install
  `ext-gd` and re-run; thumbnails are generated lazily on first
  request, so there's nothing to "re-import".
- **Migrating from a partial 1.x install** (e.g. an empty
  `data/datasets/buffers/items/`): the importer treats absent files
  as "zero items in that category" and continues. The final report
  will show `Categories > 0` with `Items: 0`, which is correct, not
  an error.
- **Different output target each time:** the CLI is **append-safe**
  against an existing 2.0 SQLite file: schema migrations are no-ops
  if applied, and item saves create fresh IDs. Re-running against
  the same target will therefore **duplicate** every item. Always
  point at a fresh target dir for a fresh import.
