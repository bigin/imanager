<?php

declare(strict_types=1);

namespace Imanager\Tests\Unit\Cli\Command;

use Imanager\Cli\Command\FtsRebuildCommand;
use Imanager\Cli\Support\DatabaseFactory;
use Imanager\Storage\SchemaManager;
use Imanager\Storage\Sqlite\MigrationLoader;
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

    /**
     * 2.2.1 regression guard: a 2.2.0 install's upgrade recipe says to run
     * `fts:rebuild` after composer-update. If `fts:rebuild` doesn't migrate
     * first, the rebuild fires against a stale schema (e.g. before 0005's
     * searchable promotion) and silently produces an empty/wrong index.
     */
    public function testAppliesPendingMigrationsBeforeRebuilding(): void
    {
        // Seed a DB at version 4 (skip 0005). Bypass DatabaseFactory so we
        // can hand a curated migration list to SchemaManager.
        $pdo = DatabaseFactory::connect($this->dbPath);
        $all = (new MigrationLoader(DatabaseFactory::schemaDir()))->load();
        $upToFour = array_values(array_filter(
            $all,
            static fn($m) => $m->version() <= 4,
        ));
        (new SchemaManager($pdo, $upToFour))->migrate();

        // Sanity: version is 4, 0005 is pending.
        self::assertSame(4, DatabaseFactory::schemaManager($pdo)->currentVersion());
        unset($pdo);

        $tester = new CommandTester(new FtsRebuildCommand());
        $exitCode = $tester->execute(['--db' => $this->dbPath]);

        self::assertSame(0, $exitCode);
        $display = $tester->getDisplay();
        self::assertStringContainsString('Applying 1 pending migration(s)', $display);
        self::assertStringContainsString('0005 — searchable defaults', $display);
        self::assertStringContainsString('FTS index rebuilt', $display);

        // Schema is now at head; re-running the command applies zero
        // migrations and stays silent on that front.
        $pdo2 = DatabaseFactory::connect($this->dbPath);
        self::assertSame(5, DatabaseFactory::schemaManager($pdo2)->currentVersion());
    }
}
