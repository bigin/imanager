<?php

declare(strict_types=1);

namespace Imanager\Tests\Unit\Cli\Command;

use Imanager\Cli\Command\OptimizeCommand;
use Imanager\Cli\Support\DatabaseFactory;
use Imanager\Storage\SchemaManager;
use Imanager\Storage\Sqlite\MigrationLoader;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(OptimizeCommand::class)]
final class OptimizeCommandTest extends CliTestCase
{
    public function testRunsOptimizeOnMigratedDatabase(): void
    {
        $pdo = DatabaseFactory::connect($this->dbPath);
        DatabaseFactory::schemaManager($pdo)->migrate();
        unset($pdo);

        $tester = new CommandTester(new OptimizeCommand());

        $exitCode = $tester->execute(['--db' => $this->dbPath]);

        self::assertSame(0, $exitCode);
        $display = $tester->getDisplay();
        self::assertStringContainsString('PRAGMA optimize', $display);
        self::assertStringContainsString('Optimization complete', $display);
        self::assertStringNotContainsString('VACUUM', $display);
    }

    public function testRunsVacuumWhenFlagIsSet(): void
    {
        $pdo = DatabaseFactory::connect($this->dbPath);
        DatabaseFactory::schemaManager($pdo)->migrate();
        unset($pdo);

        $tester = new CommandTester(new OptimizeCommand());

        $exitCode = $tester->execute(['--db' => $this->dbPath, '--vacuum' => true]);

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('VACUUM', $tester->getDisplay());
    }

    public function testFailsWhenDbOptionIsMissing(): void
    {
        $tester = new CommandTester(new OptimizeCommand());

        self::assertSame(2, $tester->execute([]));
    }

    public function testAppliesPendingMigrationsBeforeOptimizing(): void
    {
        $pdo = DatabaseFactory::connect($this->dbPath);
        $all = (new MigrationLoader(DatabaseFactory::schemaDir()))->load();
        $upToFour = array_values(array_filter(
            $all,
            static fn($m) => $m->version() <= 4,
        ));
        (new SchemaManager($pdo, $upToFour))->migrate();
        unset($pdo);

        $tester = new CommandTester(new OptimizeCommand());
        $exitCode = $tester->execute(['--db' => $this->dbPath]);

        self::assertSame(0, $exitCode);
        $display = $tester->getDisplay();
        self::assertStringContainsString('Applying 1 pending migration(s)', $display);
        self::assertStringContainsString('Optimization complete', $display);

        $pdo2 = DatabaseFactory::connect($this->dbPath);
        self::assertSame(5, DatabaseFactory::schemaManager($pdo2)->currentVersion());
    }
}
