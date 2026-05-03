<?php

declare(strict_types=1);

namespace Imanager\Tests\Unit\Cli\Command;

use Imanager\Cli\Command\RepairCommand;
use Imanager\Cli\Support\DatabaseFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(RepairCommand::class)]
final class RepairCommandTest extends CliTestCase
{
    public function testReportsHealthOnFreshDatabase(): void
    {
        $pdo = DatabaseFactory::connect($this->dbPath);
        DatabaseFactory::schemaManager($pdo)->migrate();
        unset($pdo);

        $tester = new CommandTester(new RepairCommand());

        $exitCode = $tester->execute(['--db' => $this->dbPath]);

        self::assertSame(0, $exitCode);
        $display = $tester->getDisplay();
        self::assertStringContainsString('integrity_check', $display);
        self::assertStringContainsString('foreign_key_check', $display);
        self::assertStringContainsString('Database is healthy', $display);
    }

    public function testFailsWhenDbOptionIsMissing(): void
    {
        $tester = new CommandTester(new RepairCommand());

        self::assertSame(2, $tester->execute([]));
    }
}
