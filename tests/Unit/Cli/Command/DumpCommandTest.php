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
        // FTS5 virtual table itself must be in the dump …
        self::assertStringContainsString('CREATE VIRTUAL TABLE items_fts', $display);
        // … but its auto-managed shadow tables must NOT appear, since
        // FTS5 re-creates them on the destination from the parent
        // virtual-table CREATE.
        self::assertStringNotContainsString('CREATE TABLE "items_fts_data"', $display);
        self::assertStringNotContainsString('CREATE TABLE "items_fts_idx"', $display);
        self::assertStringNotContainsString('CREATE TABLE "items_fts_config"', $display);
        self::assertStringNotContainsString('CREATE TABLE "items_fts_docsize"', $display);
        self::assertStringNotContainsString('sqlite_', $display);
    }

    public function testFtsVirtualTableRoundTripsItsRowid(): void
    {
        // Seed: a single FTS row whose rowid points at item id 42 — the
        // schema's own writer (SqliteItemRepository::syncFts) uses that
        // linkage. The dump must preserve it.
        $pdo = DatabaseFactory::connect($this->dbPath);
        DatabaseFactory::schemaManager($pdo)->migrate();
        $pdo->exec(
            'INSERT INTO items_fts (rowid, name, label, body) '
                . "VALUES (42, 'hello', 'world', 'body text')",
        );
        unset($pdo);

        $tester = new CommandTester(new DumpCommand());
        self::assertSame(0, $tester->execute(['--db' => $this->dbPath]));
        $dump = $tester->getDisplay();

        // Restore into a fresh DB and verify the FTS row + rowid survive.
        $restorePath = $this->dbPath . '.restore.sqlite';
        $restore = new \PDO('sqlite:' . $restorePath);
        $restore->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $restore->exec($dump);

        $stmt = $restore->query(
            "SELECT rowid, name, label, body FROM items_fts WHERE name = 'hello'",
        );
        self::assertInstanceOf(\PDOStatement::class, $stmt);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        unset($stmt, $restore);
        @unlink($restorePath);

        self::assertIsArray($row);
        self::assertSame(42, (int) $row['rowid']);
        self::assertSame('world', $row['label']);
        self::assertSame('body text', $row['body']);
    }

    public function testFailsWhenDbOptionIsMissing(): void
    {
        $tester = new CommandTester(new DumpCommand());

        self::assertSame(2, $tester->execute([]));
    }
}
