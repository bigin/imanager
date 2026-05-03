<?php

declare(strict_types=1);

namespace Imanager\Tests\Unit\Cli\Command;

use Imanager\Cli\Command\SchemaStatusCommand;
use Imanager\Cli\Support\DatabaseFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(SchemaStatusCommand::class)]
#[CoversClass(DatabaseFactory::class)]
final class SchemaStatusCommandTest extends CliTestCase
{
    public function testReportsPendingMigrationsOnFreshDatabase(): void
    {
        $tester = new CommandTester(new SchemaStatusCommand());

        $exitCode = $tester->execute(['--db' => $this->dbPath]);

        self::assertSame(0, $exitCode);
        $display = $tester->getDisplay();
        self::assertStringContainsString('Current version: 0', $display);
        self::assertStringContainsString('Pending migrations', $display);
    }

    public function testReportsUpToDateAfterMigration(): void
    {
        $pdo = DatabaseFactory::connect($this->dbPath);
        DatabaseFactory::schemaManager($pdo)->migrate();
        unset($pdo);

        $tester = new CommandTester(new SchemaStatusCommand());
        $tester->execute(['--db' => $this->dbPath]);

        $display = $tester->getDisplay();
        self::assertStringContainsString('No pending migrations', $display);
    }

    public function testFailsWhenDbOptionIsMissing(): void
    {
        $tester = new CommandTester(new SchemaStatusCommand());

        $exitCode = $tester->execute([]);

        self::assertSame(2, $exitCode); // Command::INVALID
        self::assertStringContainsString('--db is required', $tester->getDisplay());
    }
}
