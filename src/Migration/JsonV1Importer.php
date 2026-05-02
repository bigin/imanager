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

    public function import(string $sourceDir, ?string $targetUploadDir = null, bool $dryRun = false): ImportReport
    {
        $report = new ImportReport();

        $categoriesFile = $sourceDir . '/datasets/buffers/categories/categories.php';
        if (! is_file($categoriesFile)) {
            $report->addError(\sprintf('Categories file not found at "%s"', $categoriesFile));
            return $report;
        }

        $work = function () use ($sourceDir, $categoriesFile, $targetUploadDir, $report): void {
            $categoryIdMap = $this->importCategories($categoriesFile, $report);
            $this->importFields($sourceDir, $categoryIdMap, $report);
            $this->importItems($sourceDir, $categoryIdMap, $report);
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
     */
    private function importItems(string $sourceDir, array $categoryIdMap, ImportReport $report): void
    {
        foreach ($categoryIdMap as $oldCategoryId => $newCategoryId) {
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
                $this->importOneItem($newCategoryId, (string) $oldId, $row, $report);
            }
        }
    }

    /**
     * @param array<string, mixed> $row
     */
    private function importOneItem(int $newCategoryId, string $oldId, array $row, ImportReport $report): void
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
            $this->storage->items()->save($item);
            $report->itemsImported++;
        } catch (\Throwable $e) {
            $report->addError(\sprintf(
                'Item %s in category %d: %s',
                $oldId,
                $newCategoryId,
                $e->getMessage(),
            ));
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
