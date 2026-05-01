-- iManager initial schema. See docs/imanager-2.0-plan.md §6.
--
-- The `schema_version` table is created on demand by `Imanager\Storage\SchemaManager`
-- and is therefore not part of this migration.

CREATE TABLE categories (
    id        INTEGER PRIMARY KEY AUTOINCREMENT,
    name      TEXT    NOT NULL UNIQUE,
    slug      TEXT    NOT NULL UNIQUE,
    position  INTEGER NOT NULL DEFAULT 0,
    created   INTEGER NOT NULL,
    updated   INTEGER NOT NULL
);
CREATE INDEX idx_categories_position ON categories(position);

CREATE TABLE fields (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    category_id INTEGER NOT NULL REFERENCES categories(id) ON DELETE CASCADE,
    name        TEXT    NOT NULL,
    label       TEXT,
    type        TEXT    NOT NULL,
    position    INTEGER NOT NULL DEFAULT 0,
    required    INTEGER NOT NULL DEFAULT 0,
    indexed     INTEGER NOT NULL DEFAULT 0,
    searchable  INTEGER NOT NULL DEFAULT 0,
    config      TEXT    NOT NULL DEFAULT '{}' CHECK(json_valid(config)),
    created     INTEGER NOT NULL,
    updated     INTEGER NOT NULL,
    UNIQUE(category_id, name)
);
CREATE INDEX idx_fields_category ON fields(category_id, position);

CREATE TABLE items (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    category_id INTEGER NOT NULL REFERENCES categories(id) ON DELETE CASCADE,
    name        TEXT,
    label       TEXT,
    position    INTEGER NOT NULL DEFAULT 0,
    active      INTEGER NOT NULL DEFAULT 1,
    data        TEXT    NOT NULL DEFAULT '{}' CHECK(json_valid(data)),
    created     INTEGER NOT NULL,
    updated     INTEGER NOT NULL
);
CREATE INDEX idx_items_cat_pos    ON items(category_id, position);
CREATE INDEX idx_items_cat_active ON items(category_id, active);
