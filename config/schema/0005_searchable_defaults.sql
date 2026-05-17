-- 2.2.0 — the per-field `searchable` flag becomes load-bearing.
--
-- Prior to 2.2.0, the FTS5 writer ignored `searchable` and flattened every
-- string/numeric value from `items.data` into `items_fts.body`. The
-- `Field` constructor defaulted `searchable` to false, so an honest read
-- of the column on an existing install would say "no field is searchable"
-- — which, applied as a behavioral switch, would silently drop ALL FTS
-- body coverage on upgrade.
--
-- This migration preserves the de-facto coverage for prose-typed content
-- so existing installs keep finding the same items via search. The four
-- promoted types are exactly those whose 2.2.0 factories
-- (`Field::text|longText|editor|slug()`) default to `searchable: true`.
--
-- Side effects (all deliberate, documented in CHANGELOG):
--   * `password` fields stop being indexed (was a bcrypt hash anyway).
--   * `fileupload`/`imageupload`/`filepicker` paths stop being indexed.
--   * `integer`/`decimal`/`money`/`datepicker`/`checkbox`/`dropdown`/
--     `hidden`/`arrayList` values stop being indexed.
--
-- After this migration, callers should run `vendor/bin/imanager fts:rebuild`
-- so the body column actually drops the now-excluded values. The flag is
-- already honored by per-save syncFts from this release onward; the
-- rebuild reconciles pre-existing rows.

UPDATE fields
   SET searchable = 1
 WHERE searchable = 0
   AND type IN ('text', 'longtext', 'editor', 'slug');
