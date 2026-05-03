<?php

declare(strict_types=1);

namespace Imanager\Tests\Unit\Cli\Command;

use Imanager\Cli\Command\MigrateFromV1Command;
use Imanager\Cli\Support\DatabaseFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(MigrateFromV1Command::class)]
final class MigrateFromV1CommandTest extends CliTestCase
{
    private string $sourceDir;
    private string $uploadTarget;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sourceDir = \dirname(__DIR__, 3) . '/Fixtures/v1';
        $this->uploadTarget = sys_get_temp_dir() . '/imanager-cli-uploads-' . uniqid();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->wipe($this->uploadTarget);
    }

    public function testImportsV1FixturesIntoTargetDatabase(): void
    {
        $tester = new CommandTester(new MigrateFromV1Command());

        $exitCode = $tester->execute([
            '--source'        => $this->sourceDir,
            '--target'        => $this->dbPath,
            '--upload-target' => $this->uploadTarget,
        ]);

        self::assertSame(0, $exitCode, $tester->getDisplay());
        $display = $tester->getDisplay();
        self::assertStringContainsString('iManager migration', $display);
        self::assertStringContainsString('Categories', $display);
        self::assertStringContainsString('Fields', $display);
        self::assertStringContainsString('Items', $display);

        self::assertGreaterThan(0, $this->categoryCount());
    }

    public function testDryRunRollsBackButPrintsCounts(): void
    {
        $tester = new CommandTester(new MigrateFromV1Command());

        $exitCode = $tester->execute([
            '--source'        => $this->sourceDir,
            '--target'        => $this->dbPath,
            '--upload-target' => $this->uploadTarget,
            '--dry-run'       => true,
        ]);

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('dry run', $tester->getDisplay());

        self::assertSame(0, $this->categoryCount());
    }

    public function testFailsWhenSourceDoesNotExist(): void
    {
        $tester = new CommandTester(new MigrateFromV1Command());

        $exitCode = $tester->execute([
            '--source' => '/nonexistent/path',
            '--target' => $this->dbPath,
        ]);

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('does not exist', $tester->getDisplay());
    }

    public function testFailsWhenSourceMissing(): void
    {
        $tester = new CommandTester(new MigrateFromV1Command());

        $exitCode = $tester->execute(['--target' => $this->dbPath]);

        self::assertSame(2, $exitCode);
    }

    public function testFailsWhenTargetMissing(): void
    {
        $tester = new CommandTester(new MigrateFromV1Command());

        $exitCode = $tester->execute(['--source' => $this->sourceDir]);

        self::assertSame(2, $exitCode);
    }

    private function categoryCount(): int
    {
        $pdo = DatabaseFactory::connect($this->dbPath);
        $stmt = $pdo->query('SELECT COUNT(*) FROM categories');
        return $stmt === false ? 0 : (int) $stmt->fetchColumn();
    }

    private function wipe(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $entry) {
            \assert($entry instanceof \SplFileInfo);
            if ($entry->isDir()) {
                @rmdir($entry->getPathname());
            } else {
                @unlink($entry->getPathname());
            }
        }
        @rmdir($dir);
    }
}
