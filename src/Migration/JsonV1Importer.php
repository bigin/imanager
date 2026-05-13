<?php

declare(strict_types=1);

namespace Imanager\Migration;

use Imanager\Domain\Category;
use Imanager\Domain\Field;
use Imanager\Domain\Item;
use Imanager\Enum\FieldType;
use Imanager\Storage\Storage;

/**
 * Walks an iManager 1.x `data/` directory and writes its contents into a
 * 2.0 `Storage` backend (typically SQLite) inside a single transaction.
 *
 * Steps, in order:
 *   1. Parse `datasets/buffers/categories/categories.php` and save each
 *      Category to the new storage; record the old-id → new-id mapping.
 *   2. For every imported category, parse `datasets/buffers/fields/<old>.fields.php`
 *      and save each Field. The 1.x source keys fields by name; the 2.0
 *      `(category_id, name)` UNIQUE constraint enforces the same shape.
 *   3. For every imported category, parse `datasets/buffers/items/<old>.items.php`
 *      and save each Item with structural columns separated from the dynamic
 *      field values that go into `data`.
 *   4. Copy `uploads/` recursively into the target's upload directory.
 *
 * Field config follows the **hybrid** strategy agreed on the kickoff:
 * verbatim copy of every non-structural top-level Field property plus every
 * key from the nested `FieldConfigs::__set_state()` payload. Plugins read
 * what they understand and ignore the rest; nothing is silently dropped.
 *
 * `--dry-run` runs the whole import inside a transaction that's always
 * rolled back, so the report shows what *would* have happened without
 * mutating either the database or the upload directory.
 */
final class JsonV1Importer
{
    /**
     * Item attributes that 1.x carried alongside dynamic field values. These
     * never become field-data; everything else does.
     *
     * @var list<string>
     */
    private const ITEM_STRUCTURAL_KEYS = [
        '__class', 'id', 'categoryid', 'name', 'label',
        'position', 'active', 'created', 'updated',
        'fields', 'errorCode', 'imanager', 'total', 'path',
    ];

    /**
     * Field attributes that map directly onto 2.0's Field shape (i.e. NOT
     * config). Everything else lives on `Field::$config`.
     *
     * @var list<string>
     */
    private const FIELD_STRUCTURAL_KEYS = [
        '__class', 'id', 'categoryid', 'name', 'label', 'type',
        'position', 'required', 'created', 'updated',
        'configs',
    ];

    public function __construct(
        private readonly V1FileParser $parser,
        private readonly Storage $storage,
    ) {}

    /**
     * @param array<string, array<string, string>> $remapFields
     *                                                          Optional id-remap declaration, shape `categorySlug => fieldName
     *                                                          => targetCategorySlug`. After the standard item-import pass, the
     *                                                          importer walks every item in `categorySlug` and, for each
     *                                                          declared `fieldName`, treats the stored value as an old item id
     *                                                          from `targetCategorySlug` and rewrites it to the new id assigned
     *                                                          during this import. Solves the canonical "self-referential parent
     *                                                          field" problem (the 1.x value is the old item id; without the
     *                                                          remap that pointer ends up dangling in 2.0). Empty array (the
     *                                                          default) skips the second pass entirely.
     */
    public function import(
        string $sourceDir,
        ?string $targetUploadDir = null,
        bool $dryRun = false,
        array $remapFields = [],
    ): ImportReport {
        $report = new ImportReport();

        $categoriesFile = $sourceDir . '/datasets/buffers/categories/categories.php';
        if (! is_file($categoriesFile)) {
            $report->addError(\sprintf('Categories file not found at "%s"', $categoriesFile));
            return $report;
        }

        $work = function () use ($sourceDir, $categoriesFile, $targetUploadDir, $remapFields, $report): void {
            $categoryIdMap   = $this->importCategories($categoriesFile, $report);
            $categorySlugMap = $this->buildCategorySlugMap($categoryIdMap);
            $this->importFields($sourceDir, $categoryIdMap, $report);
            $itemIdMaps = $this->importItems($sourceDir, $categoryIdMap, $report);
            if ($remapFields !== []) {
                $this->remapItemReferences($remapFields, $categorySlugMap, $itemIdMaps, $report);
            }
            if ($targetUploadDir !== null) {
                $this->copyUploads($sourceDir . '/uploads', $targetUploadDir, $report);
            }
        };

        if ($dryRun) {
            try {
                $this->storage->transactional(function () use ($work): void {
                    $work();
                    throw new DryRunRollback();
                });
            } catch (DryRunRollback) {
                $report->rolledBack = true;
            }
        } else {
            $this->storage->transactional($work);
        }

        return $report;
    }

    /**
     * @return array<int, int> old id → new id
     */
    private function importCategories(string $categoriesFile, ImportReport $report): array
    {
        try {
            $rows = $this->parser->parseFile($categoriesFile);
        } catch (MigrationParseException $e) {
            $report->addError($e->getMessage());
            return [];
        }

        $map = [];
        foreach ($rows as $oldId => $row) {
            if (! \is_array($row)) {
                $report->addWarning(\sprintf('Skipped non-array category row at key %s', (string) $oldId));
                continue;
            }
            try {
                $cat = new Category(
                    id: null,
                    name: (string) ($row['name'] ?? ''),
                    slug: (string) ($row['slug'] ?? ''),
                    position: (int) ($row['position'] ?? 0),
                    created: (int) ($row['created'] ?? 0),
                    updated: (int) ($row['updated'] ?? 0),
                );
                $saved = $this->storage->categories()->save($cat);
                if ($saved->id !== null && \is_int($oldId)) {
                    $map[$oldId] = $saved->id;
                }
                $report->categoriesImported++;
            } catch (\Throwable $e) {
                $report->addError(\sprintf(
                    'Category %s ("%s"): %s',
                    (string) $oldId,
                    (string) ($row['name'] ?? '?'),
                    $e->getMessage(),
                ));
            }
        }
        return $map;
    }

    /**
     * @param array<int, int> $categoryIdMap
     */
    private function importFields(string $sourceDir, array $categoryIdMap, ImportReport $report): void
    {
        foreach ($categoryIdMap as $oldCategoryId => $newCategoryId) {
            $fieldsFile = \sprintf('%s/datasets/buffers/fields/%d.fields.php', $sourceDir, $oldCategoryId);
            if (! is_file($fieldsFile)) {
                continue;
            }
            try {
                $rows = $this->parser->parseFile($fieldsFile);
            } catch (MigrationParseException $e) {
                $report->addError($e->getMessage());
                continue;
            }

            foreach ($rows as $name => $row) {
                if (! \is_array($row)) {
                    $report->addWarning(\sprintf(
                        'Skipped non-array field row "%s" in category %d',
                        (string) $name,
                        $oldCategoryId,
                    ));
                    continue;
                }
                $this->importOneField($newCategoryId, (string) $name, $row, $report);
            }
        }
    }

    /**
     * @param array<string, mixed> $row
     */
    private function importOneField(int $newCategoryId, string $name, array $row, ImportReport $report): void
    {
        $typeStr = (string) ($row['type'] ?? '');
        $type = FieldType::tryFrom($typeStr);
        if ($type === null) {
            $report->addError(\sprintf(
                'Field "%s" in category %d has unknown type "%s"',
                $name,
                $newCategoryId,
                $typeStr,
            ));
            return;
        }

        // Hybrid config: verbatim merge of nested FieldConfigs payload plus
        // every non-structural top-level Field property.
        /** @var array<string, mixed> $config */
        $config = [];
        if (isset($row['configs']) && \is_array($row['configs'])) {
            $configs = $row['configs'];
            unset($configs['__class']);
            foreach ($configs as $k => $v) {
                $config[(string) $k] = $v;
            }
        }
        foreach ($row as $k => $v) {
            $key = (string) $k;
            if (\in_array($key, self::FIELD_STRUCTURAL_KEYS, true)) {
                continue;
            }
            $config[$key] = $v;
        }

        try {
            $field = new Field(
                id: null,
                categoryId: $newCategoryId,
                name: (string) ($row['name'] ?? $name),
                label: isset($row['label']) ? (string) $row['label'] : null,
                type: $type,
                position: (int) ($row['position'] ?? 0),
                required: (bool) ($row['required'] ?? false),
                config: $config,
                created: (int) ($row['created'] ?? 0),
                updated: (int) ($row['updated'] ?? 0),
            );
            $this->storage->fields()->save($field);
            $report->fieldsImported++;
        } catch (\Throwable $e) {
            $report->addError(\sprintf(
                'Field "%s" in category %d: %s',
                $name,
                $newCategoryId,
                $e->getMessage(),
            ));
        }
    }

    /**
     * @param array<int, int> $categoryIdMap
     *
     * @return array<int, array<int, int>> newCategoryId → (oldItemId → newItemId)
     */
    private function importItems(string $sourceDir, array $categoryIdMap, ImportReport $report): array
    {
        $itemIdMaps = [];
        foreach ($categoryIdMap as $oldCategoryId => $newCategoryId) {
            $itemIdMaps[$newCategoryId] = [];
            $itemsFile = \sprintf('%s/datasets/buffers/items/%d.items.php', $sourceDir, $oldCategoryId);
            if (! is_file($itemsFile)) {
                continue;
            }
            try {
                $rows = $this->parser->parseFile($itemsFile);
            } catch (MigrationParseException $e) {
                $report->addError($e->getMessage());
                continue;
            }

            foreach ($rows as $oldId => $row) {
                if (! \is_array($row)) {
                    $report->addWarning(\sprintf(
                        'Skipped non-array item row at key %s in category %d',
                        (string) $oldId,
                        $oldCategoryId,
                    ));
                    continue;
                }
                $newId = $this->importOneItem($newCategoryId, (string) $oldId, $row, $report);
                if ($newId !== null && \is_int($oldId)) {
                    $itemIdMaps[$newCategoryId][$oldId] = $newId;
                }
            }
        }
        return $itemIdMaps;
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return int|null the new id assigned by storage, or null on failure
     */
    private function importOneItem(int $newCategoryId, string $oldId, array $row, ImportReport $report): ?int
    {
        $data = [];
        foreach ($row as $k => $v) {
            $key = (string) $k;
            if (\in_array($key, self::ITEM_STRUCTURAL_KEYS, true)) {
                continue;
            }
            $data[$key] = $v;
        }

        try {
            $item = new Item(
                id: null,
                categoryId: $newCategoryId,
                name: isset($row['name']) ? (string) $row['name'] : null,
                label: isset($row['label']) ? (string) $row['label'] : null,
                position: (int) ($row['position'] ?? 0),
                active: (bool) ($row['active'] ?? true),
                data: $data,
                created: (int) ($row['created'] ?? 0),
                updated: (int) ($row['updated'] ?? 0),
            );
            $saved = $this->storage->items()->save($item);
            $report->itemsImported++;
            return $saved->id;
        } catch (\Throwable $e) {
            $report->addError(\sprintf(
                'Item %s in category %d: %s',
                $oldId,
                $newCategoryId,
                $e->getMessage(),
            ));
            return null;
        }
    }

    /**
     * Builds `categorySlug → newCategoryId` from the already-imported
     * categories. Used by the remap pass so callers can address categories
     * by their (stable) slug rather than the ephemeral new SQLite id.
     *
     * @param array<int, int> $categoryIdMap old categoryId → new categoryId
     *
     * @return array<string, int> categorySlug → new categoryId
     */
    private function buildCategorySlugMap(array $categoryIdMap): array
    {
        $slugMap = [];
        foreach ($categoryIdMap as $newId) {
            foreach ($this->storage->categories()->findAll() as $cat) {
                if ($cat->id === $newId) {
                    $slugMap[$cat->slug] = $newId;
                    break;
                }
            }
        }
        return $slugMap;
    }

    /**
     * Second-pass id remap. For every entry in `$remap`, walks the host
     * category's items, finds the named field's value, looks it up in the
     * referenced category's old→new id map, and rewrites in place. Runs
     * inside the import transaction, so a remap failure rolls everything
     * back together with the rest of the import.
     *
     * Values left untouched in three cases:
     *  - the raw value is `null`, `''`, or numerically zero (typical "root"
     *    sentinel for self-referential parent fields);
     *  - the old id isn't in the reference category's map (dangling
     *    pointer — a warning is recorded);
     *  - the value, coerced to int, already equals the new id (idempotent
     *    re-runs against an already-remapped DB are a no-op).
     *
     * @param array<string, array<string, string>> $remap         categorySlug → fieldName → targetCategorySlug
     * @param array<string, int>                   $categorySlugs categorySlug → newCategoryId
     * @param array<int, array<int, int>>          $itemIdMaps    newCategoryId → (oldItemId → newItemId)
     */
    private function remapItemReferences(
        array $remap,
        array $categorySlugs,
        array $itemIdMaps,
        ImportReport $report,
    ): void {
        foreach ($remap as $hostSlug => $fieldMap) {
            $hostCategoryId = $categorySlugs[$hostSlug] ?? null;
            if ($hostCategoryId === null) {
                $report->addWarning(\sprintf(
                    'Remap: unknown category slug "%s" — skipping its field map',
                    $hostSlug,
                ));
                continue;
            }
            if (! \is_array($fieldMap)) {
                $report->addWarning(\sprintf(
                    'Remap: entry for "%s" is not a field map (got %s) — skipping',
                    $hostSlug,
                    get_debug_type($fieldMap),
                ));
                continue;
            }

            foreach ($fieldMap as $fieldName => $refSlug) {
                $refCategoryId = $categorySlugs[(string) $refSlug] ?? null;
                if ($refCategoryId === null) {
                    $report->addWarning(\sprintf(
                        'Remap: unknown reference-category slug "%s" for %s.%s — skipping',
                        (string) $refSlug,
                        $hostSlug,
                        (string) $fieldName,
                    ));
                    continue;
                }
                $refIdMap = $itemIdMaps[$refCategoryId] ?? [];
                $this->remapOneField(
                    (string) $fieldName,
                    $hostCategoryId,
                    $refIdMap,
                    $report,
                );
            }
        }
    }

    /**
     * @param array<int, int> $refIdMap oldItemId → newItemId in the referenced category
     */
    private function remapOneField(
        string $fieldName,
        int $hostCategoryId,
        array $refIdMap,
        ImportReport $report,
    ): void {
        foreach ($this->storage->items()->findByCategory($hostCategoryId) as $item) {
            $raw = $item->data->get($fieldName);
            if ($raw === null || $raw === '' || $raw === 0 || $raw === '0') {
                continue;
            }
            if (! is_numeric($raw)) {
                continue;
            }
            $oldId = (int) $raw;
            if ($oldId <= 0) {
                continue;
            }
            if (! isset($refIdMap[$oldId])) {
                $report->addWarning(\sprintf(
                    'Remap: item %d "%s".%s points at old id %d that has no new mapping — leaving as-is',
                    $item->id ?? 0,
                    $item->name ?? '',
                    $fieldName,
                    $oldId,
                ));
                continue;
            }
            $newId = $refIdMap[$oldId];
            if ($newId === $oldId) {
                continue;
            }

            $rewritten = $item->data->with($fieldName, $newId);
            $this->storage->items()->save(new Item(
                id: $item->id,
                categoryId: $item->categoryId,
                name: $item->name,
                label: $item->label,
                position: $item->position,
                active: $item->active,
                data: $rewritten,
                created: $item->created,
                updated: $item->updated,
            ));
            $report->itemsRemapped++;
        }
    }

    private function copyUploads(string $sourceDir, string $targetDir, ImportReport $report): void
    {
        if (! is_dir($sourceDir)) {
            return;
        }
        if (! is_dir($targetDir) && ! @mkdir($targetDir, 0o755, true) && ! is_dir($targetDir)) {
            $report->addError(\sprintf('Cannot create upload target "%s"', $targetDir));
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($sourceDir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST,
        );

        foreach ($iterator as $entry) {
            if (! $entry instanceof \SplFileInfo) {
                continue;
            }
            $relative = substr($entry->getPathname(), \strlen($sourceDir) + 1);
            $destination = $targetDir . '/' . $relative;
            if ($entry->isDir()) {
                if (! is_dir($destination) && ! @mkdir($destination, 0o755, true) && ! is_dir($destination)) {
                    $report->addError(\sprintf('Cannot create directory "%s"', $destination));
                }
                continue;
            }
            if (! @copy($entry->getPathname(), $destination)) {
                $report->addError(\sprintf(
                    'Cannot copy "%s" to "%s"',
                    $entry->getPathname(),
                    $destination,
                ));
                continue;
            }
            $report->assetsCopied++;
        }
    }
}

/**
 * Internal sentinel used by {@see JsonV1Importer::import()} to abort the
 * surrounding transaction when running with `--dry-run`. Never escapes the
 * importer.
 *
 * @internal
 */
final class DryRunRollback extends \RuntimeException {}
