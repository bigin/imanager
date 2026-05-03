<?php

declare(strict_types=1);

namespace Imanager\Tests\Unit\Cli\Command;

use Imanager\Cli\Command\FtsRebuildCommand;
use Imanager\Cli\Support\DatabaseFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(FtsRebuildCommand::class)]
final class FtsRebuildCommandTest extends CliTestCase
{
    public function testRebuildsIndexAfterMigration(): void
    {
        $pdo = DatabaseFactory::connect($this->dbPath);
        DatabaseFactory::schemaManager($pdo)->migrate();
        unset($pdo);

        $tester = new CommandTester(new FtsRebuildCommand());

        $exitCode = $tester->execute(['--db' => $this->dbPath]);

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('FTS index rebuilt', $tester->getDisplay());
    }

    public function testFailsWhenDbOptionIsMissing(): void
    {
        $tester = new CommandTester(new FtsRebuildCommand());

        self::assertSame(2, $tester->execute([]));
    }
}
