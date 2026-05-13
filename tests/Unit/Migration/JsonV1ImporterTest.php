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

    // -----------------------------------------------------------------
    // Remap pass — `$remapFields` argument on import()
    // -----------------------------------------------------------------

    public function testWithoutRemapItemReferencesStayAtOldIds(): void
    {
        $fixture = \dirname(__DIR__, 2) . '/Fixtures/v1-references';
        $report = $this->importer->import($fixture);

        self::assertSame(3, $report->itemsImported);
        self::assertSame(0, $report->itemsRemapped);

        // The fixture's parent values (100, 200) are *old* item ids — the
        // new storage assigns 1, 2, 3 via AUTOINCREMENT. Without a remap
        // pass, the stored parent stays at the dangling old value.
        $pages = $this->storage->categories()->findBySlug('pages');
        \assert($pages !== null && $pages->id !== null);
        $items = $this->byName($this->storage->items()->findByCategory($pages->id));

        self::assertSame(0, (int) $items['root']->data->get('parent'));
        self::assertSame(100, (int) $items['child']->data->get('parent'));
        self::assertSame(200, (int) $items['grandchild']->data->get('parent'));
    }

    public function testRemapRewritesSelfReferentialParentField(): void
    {
        $fixture = \dirname(__DIR__, 2) . '/Fixtures/v1-references';
        $report = $this->importer->import(
            $fixture,
            remapFields: ['pages' => ['parent' => 'pages']],
        );

        self::assertSame(3, $report->itemsImported);
        // Two rewrites: child → root's new id, grandchild → child's new id.
        // The root item's parent is 0 (the standard root sentinel) and is
        // left alone.
        self::assertSame(2, $report->itemsRemapped);

        $pages = $this->storage->categories()->findBySlug('pages');
        \assert($pages !== null && $pages->id !== null);
        $items = $this->byName($this->storage->items()->findByCategory($pages->id));

        $rootNewId  = $items['root']->id ?? 0;
        $childNewId = $items['child']->id ?? 0;

        self::assertSame(0, (int) $items['root']->data->get('parent'));
        self::assertSame($rootNewId, (int) $items['child']->data->get('parent'));
        self::assertSame($childNewId, (int) $items['grandchild']->data->get('parent'));
    }

    public function testRemapWithUnknownCategoryEmitsWarningWithoutErroring(): void
    {
        $fixture = \dirname(__DIR__, 2) . '/Fixtures/v1-references';
        $report = $this->importer->import(
            $fixture,
            remapFields: ['nope' => ['parent' => 'pages']],
        );

        self::assertFalse($report->hasErrors());
        self::assertSame(0, $report->itemsRemapped);
        self::assertNotEmpty($report->warnings);
        self::assertStringContainsString('nope', implode(' | ', $report->warnings));
    }

    public function testRemapWithDanglingOldIdEmitsWarningAndLeavesValueAlone(): void
    {
        // Fabricate an extra item whose parent points at an old id no
        // other item carries. The remap pass should warn and leave the
        // value untouched rather than blanking it.
        $tempFixture = $this->makeReferencesFixtureWithExtra('orphan', oldParent: 999);
        try {
            $report = (new JsonV1Importer(new V1FileParser(), SqliteStorageFactory::inMemory()))
                ->import($tempFixture, remapFields: ['pages' => ['parent' => 'pages']]);

            self::assertFalse($report->hasErrors());
            self::assertNotEmpty($report->warnings);
            self::assertStringContainsString('999', implode(' | ', $report->warnings));
            self::assertStringContainsString('no new mapping', implode(' | ', $report->warnings));
        } finally {
            $this->cleanDir($tempFixture);
        }
    }

    public function testRemapDryRunRollsBackTheRewrites(): void
    {
        $fixture = \dirname(__DIR__, 2) . '/Fixtures/v1-references';
        $report = $this->importer->import(
            $fixture,
            dryRun: true,
            remapFields: ['pages' => ['parent' => 'pages']],
        );

        self::assertTrue($report->rolledBack);
        self::assertSame(2, $report->itemsRemapped);
        // Nothing committed.
        self::assertSame([], $this->storage->categories()->findAll());
    }

    /**
     * @param list<\Imanager\Domain\Item> $items
     *
     * @return array<string, \Imanager\Domain\Item>
     */
    private function byName(array $items): array
    {
        $out = [];
        foreach ($items as $item) {
            if ($item->name !== null) {
                $out[$item->name] = $item;
            }
        }
        return $out;
    }

    /**
     * Builds a copy of the references fixture in a temp dir, with one
     * extra item appended to the items file whose `parent` points at
     * `$oldParent`. Returns the temp source-dir path.
     */
    private function makeReferencesFixtureWithExtra(string $extraName, int $oldParent): string
    {
        $src = \dirname(__DIR__, 2) . '/Fixtures/v1-references';
        $dst = sys_get_temp_dir() . '/imanager-remap-' . uniqid();
        $this->copyDirRecursively($src, $dst);

        $itemsFile = $dst . '/datasets/buffers/items/1.items.php';
        $rewritten = $this->itemsFileWithExtra($extraName, $oldParent);
        file_put_contents($itemsFile, $rewritten);

        return $dst;
    }

    private function itemsFileWithExtra(string $extraName, int $oldParent): string
    {
        return <<<PHP
        <?php return array (
          100 =>
          \\Scriptor\\Core\\Page::__set_state(array(
             'categoryid' => 1,
             'id' => 100,
             'name' => 'root',
             'label' => NULL,
             'position' => 1,
             'active' => true,
             'created' => 1519052101,
             'updated' => 1696768396,
             'slug' => 'root',
             'parent' => 0,
             'content' => 'Top-level page.',
          )),
          200 =>
          \\Scriptor\\Core\\Page::__set_state(array(
             'categoryid' => 1,
             'id' => 200,
             'name' => 'child',
             'label' => NULL,
             'position' => 2,
             'active' => true,
             'created' => 1519052200,
             'updated' => 1519052200,
             'slug' => 'child',
             'parent' => 100,
             'content' => 'Child of root.',
          )),
          300 =>
          \\Scriptor\\Core\\Page::__set_state(array(
             'categoryid' => 1,
             'id' => 300,
             'name' => 'grandchild',
             'label' => NULL,
             'position' => 3,
             'active' => true,
             'created' => 1519052300,
             'updated' => 1519052300,
             'slug' => 'grandchild',
             'parent' => 200,
             'content' => 'Child of child.',
          )),
          400 =>
          \\Scriptor\\Core\\Page::__set_state(array(
             'categoryid' => 1,
             'id' => 400,
             'name' => '{$extraName}',
             'label' => NULL,
             'position' => 99,
             'active' => true,
             'created' => 1519052999,
             'updated' => 1519052999,
             'slug' => '{$extraName}',
             'parent' => {$oldParent},
             'content' => 'orphan content',
          )),
        ); ?>

        PHP;
    }

    private function copyDirRecursively(string $src, string $dst): void
    {
        if (! is_dir($dst)) {
            mkdir($dst, 0o755, true);
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($src, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST,
        );
        foreach ($iterator as $entry) {
            if (! $entry instanceof \SplFileInfo) {
                continue;
            }
            $relative = substr($entry->getPathname(), \strlen($src) + 1);
            $target = $dst . '/' . $relative;
            if ($entry->isDir()) {
                if (! is_dir($target)) {
                    mkdir($target, 0o755, true);
                }
            } else {
                copy($entry->getPathname(), $target);
            }
        }
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
