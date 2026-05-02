-- Full-text search over items via SQLite FTS5.
--
-- This is a *standalone* (non-content) FTS5 table — the body content is
-- written by the application layer, not derived via triggers, because
-- flattening the dynamic-key JSON `data` blob into a search-friendly string
-- is far cleaner in PHP than in SQL trigger bodies. See
-- `Imanager\Storage\Sqlite\SqliteItemRepository::syncFts()` for the writer.
--
-- The unicode61 tokenizer with `remove_diacritics 2` lets searches for
-- "naive" match content containing "naïve" — important for any non-English
-- content, and a sane default for international CMSes.

CREATE VIRTUAL TABLE items_fts USING fts5(
    name,
    label,
    body,
    tokenize = 'unicode61 remove_diacritics 2'
);
