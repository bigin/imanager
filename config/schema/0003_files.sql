-- File metadata table.
--
-- One row per uploaded file (image, document, etc.). The actual bytes live
-- in the FileStorage backend (LocalFileStorage by default — see Phase 13
-- design notes); this table is the source of truth for "what files belong
-- to which item, in which order, with what mime/size".
--
-- FK ON DELETE CASCADE: when an item or field is removed, the metadata
-- rows go with it. The physical bytes still need to be cleaned up by a
-- higher-level coordinator that listens to domain events (Phase 14).

CREATE TABLE files (
    id        INTEGER PRIMARY KEY AUTOINCREMENT,
    item_id   INTEGER NOT NULL REFERENCES items(id) ON DELETE CASCADE,
    field_id  INTEGER NOT NULL REFERENCES fields(id) ON DELETE CASCADE,
    name      TEXT    NOT NULL,
    path      TEXT    NOT NULL,
    mime      TEXT    NOT NULL,
    size      INTEGER NOT NULL,
    width     INTEGER NOT NULL DEFAULT 0,
    height    INTEGER NOT NULL DEFAULT 0,
    position  INTEGER NOT NULL DEFAULT 0,
    created   INTEGER NOT NULL
);
CREATE INDEX idx_files_item       ON files(item_id, position);
CREATE INDEX idx_files_item_field ON files(item_id, field_id);
