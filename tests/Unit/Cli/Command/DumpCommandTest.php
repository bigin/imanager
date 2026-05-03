<?php

declare(strict_types=1);

namespace Imanager\Tests\Unit\Cli\Command;

use Imanager\Cli\Command\DumpCommand;
use Imanager\Cli\Support\DatabaseFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(DumpCommand::class)]
final class DumpCommandTest extends CliTestCase
{
    public function testDumpsSchemaAndRows(): void
    {
        $pdo = DatabaseFactory::connect($this->dbPath);
        DatabaseFactory::schemaManager($pdo)->migrate();
        $now = time();
        $stmt = $pdo->prepare(
            'INSERT INTO categories (name, slug, position, created, updated) VALUES (?, ?, 0, ?, ?)',
        );
        $stmt->execute(['Pages', 'pages', $now, $now]);
        unset($pdo);

        $tester = new CommandTester(new DumpCommand());

        $exitCode = $tester->execute(['--db' => $this->dbPath]);

        self::assertSame(0, $exitCode);
        $display = $tester->getDisplay();
        self::assertStringContainsString('PRAGMA foreign_keys = OFF;', $display);
        self::assertStringContainsString('BEGIN TRANSACTION;', $display);
        self::assertStringContainsString('COMMIT;', $display);
        self::assertStringContainsString('-- Table: categories', $display);
        self::assertStringContainsString('CREATE TABLE categories', $display);
        self::assertStringContainsString('INSERT INTO "categories"', $display);
        self::assertStringContainsString("'Pages'", $display);
        // FTS shadow tables must be excluded.
        self::assertStringNotContainsString('items_fts', $display);
        self::assertStringNotContainsString('sqlite_', $display);
    }

    public function testFailsWhenDbOptionIsMissing(): void
    {
        $tester = new CommandTester(new DumpCommand());

        self::assertSame(2, $tester->execute([]));
    }
}
