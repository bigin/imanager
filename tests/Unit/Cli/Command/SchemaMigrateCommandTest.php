<?php

declare(strict_types=1);

namespace Imanager\Tests\Unit\Cli\Command;

use Imanager\Cli\Command\SchemaMigrateCommand;
use Imanager\Cli\Support\DatabaseFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(SchemaMigrateCommand::class)]
final class SchemaMigrateCommandTest extends CliTestCase
{
    public function testAppliesAllPendingMigrations(): void
    {
        $tester = new CommandTester(new SchemaMigrateCommand());

        $exitCode = $tester->execute(['--db' => $this->dbPath]);

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('Applied', $tester->getDisplay());

        $pdo = DatabaseFactory::connect($this->dbPath);
        $version = DatabaseFactory::schemaManager($pdo)->currentVersion();
        self::assertGreaterThan(0, $version);
    }

    public function testIsIdempotent(): void
    {
        $tester = new CommandTester(new SchemaMigrateCommand());
        $tester->execute(['--db' => $this->dbPath]);

        // Second invocation: should report up-to-date.
        $tester->execute(['--db' => $this->dbPath]);

        self::assertStringContainsString('already up to date', $tester->getDisplay());
    }

    public function testFailsWhenDbOptionIsMissing(): void
    {
        $tester = new CommandTester(new SchemaMigrateCommand());

        $exitCode = $tester->execute([]);

        self::assertSame(2, $exitCode);
    }
}
