<?php

declare(strict_types=1);

namespace Imanager\Tests\Unit\Migration;

use Imanager\Migration\ImportReport;
use Imanager\Migration\JsonV1Importer;
use Imanager\Migration\V1FileParser;
use Imanager\Storage\Sqlite\SqliteStorage;
use Imanager\Tests\Unit\Storage\Sqlite\SqliteStorageFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(JsonV1Importer::class)]
#[CoversClass(ImportReport::class)]
final class JsonV1ImporterTest extends TestCase
{
    private SqliteStorage $storage;
    private JsonV1Importer $importer;
    private string $sourceDir;
    private string $uploadTarget;

    protected function setUp(): void
    {
        $this->storage = SqliteStorageFactory::inMemory();
        $this->importer = new JsonV1Importer(new V1FileParser(), $this->storage);
        $this->sourceDir = \dirname(__DIR__, 2) . '/Fixtures/v1';
        $this->uploadTarget = sys_get_temp_dir() . '/imanager-import-' . uniqid();
    }

    protected function tearDown(): void
    {
        $this->cleanDir($this->uploadTarget);
    }

    public function testImportsCategoriesIntoNewStorage(): void
    {
        $report = $this->importer->import($this->sourceDir);

        self::assertSame(2, $report->categoriesImported);
        self::assertFalse($report->hasErrors(), implode(' | ', $report->errors));

        $cats = $this->storage->categories()->findAll();
        self::assertCount(2, $cats);
        $names = array_map(static fn($c) => $c->name, $cats);
        sort($names);
        self::assertSame(['Pages', 'Users'], $names);
    }

    public function testPreservesOriginalSlugAndPosition(): void
    {
        $this->importer->import($this->sourceDir);

        $pages = $this->storage->categories()->findBySlug('pages');
        self::assertNotNull($pages);
        self::assertSame(1, $pages->position);
    }

    public function testImportsFieldsKeyedByNameAgainstNewCategoryIds(): void
    {
        $report = $this->importer->import($this->sourceDir);

        self::assertSame(3, $report->fieldsImported);

        $pages = $this->storage->categories()->findBySlug('pages');
        \assert($pages !== null && $pages->id !== null);

        $fields = $this->storage->fields()->findByCategory($pages->id);
        $names = array_map(static fn($f) => $f->name, $fields);
        sort($names);
        self::assertSame(['content', 'images', 'slug'], $names);
    }

    public function testHybridConfigPreservesOldFieldConfigsPayload(): void
    {
        $this->importer->import($this->sourceDir);

        $pages = $this->storage->categories()->findBySlug('pages');
        \assert($pages !== null && $pages->id !== null);
        $images = $this->storage->fields()->findByName($pages->id, 'images');

        self::assertNotNull($images);
        // The accept_types key from FieldConfigs flattens into config verbatim.
        self::assertSame('gif|jpe?g|png', $images->config['accept_types'] ?? null);
        // Top-level non-structural keys (default, options, info, …) also land
        // in config so plugins can consult them without losing data.
        self::assertArrayHasKey('options', $images->config);
        self::assertArrayHasKey('info', $images->config);
        self::assertArrayHasKey('minimum', $images->config);
    }

    public function testImportsItemsWithStructuralColumnsSplitFromDataPayload(): void
    {
        $report = $this->importer->import($this->sourceDir);

        self::assertSame(2, $report->itemsImported);

        $pages = $this->storage->categories()->findBySlug('pages');
        \assert($pages !== null && $pages->id !== null);

        $items = $this->storage->items()->findByCategory($pages->id);
        self::assertCount(2, $items);

        $first = $items[0];
        self::assertSame('Demo Page', $first->name);
        self::assertSame(1, $first->position);
        self::assertTrue($first->active);
        self::assertSame('demo-page', $first->data->get('slug'));
        self::assertSame('Lorem ipsum dolor sit amet.', $first->data->get('content'));
        // Structural keys must not leak into data.
        self::assertFalse($first->data->has('id'));
        self::assertFalse($first->data->has('categoryid'));
        self::assertFalse($first->data->has('name'));
    }

    public function testCopiesUploadDirectoryRecursively(): void
    {
        $report = $this->importer->import($this->sourceDir, $this->uploadTarget);

        self::assertGreaterThan(0, $report->assetsCopied);
        self::assertFileExists($this->uploadTarget . '/sample.txt');
        self::assertFileExists($this->uploadTarget . '/1.1.6/photo.jpg');
    }

    public function testDryRunRollsBackEverything(): void
    {
        $report = $this->importer->import($this->sourceDir, dryRun: true);

        self::assertTrue($report->rolledBack);
        // Counters report what *would* have happened.
        self::assertSame(2, $report->categoriesImported);
        self::assertSame(3, $report->fieldsImported);
        self::assertSame(2, $report->itemsImported);
        // But nothing actually persisted.
        self::assertSame([], $this->storage->categories()->findAll());
    }

    public function testReportsMissingCategoriesFile(): void
    {
        $report = $this->importer->import('/nonexistent/path');

        self::assertTrue($report->hasErrors());
        self::assertStringContainsString('not found', $report->errors[0]);
        self::assertSame(0, $report->categoriesImported);
    }

    public function testSummaryFormatsCounters(): void
    {
        $report = $this->importer->import($this->sourceDir);

        $summary = $report->summary();
        self::assertStringContainsString('2 categories', $summary);
        self::assertStringContainsString('3 fields', $summary);
        self::assertStringContainsString('2 items', $summary);
    }

    private function cleanDir(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $entry) {
            if (! $entry instanceof \SplFileInfo) {
                continue;
            }
            if ($entry->isDir()) {
                @rmdir($entry->getPathname());
            } else {
                @unlink($entry->getPathname());
            }
        }
        @rmdir($dir);
    }
}
