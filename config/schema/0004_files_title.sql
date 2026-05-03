-- Per-file caption/title — replaces the 1.x `Item.data.images[].title`
-- payload that themes used to render as an image caption next to the
-- asset (Photo by …, alt text for accessibility, free-form notes).
--
-- Default '' keeps existing rows unchanged after the migration runs.

ALTER TABLE files ADD COLUMN title TEXT NOT NULL DEFAULT '';
