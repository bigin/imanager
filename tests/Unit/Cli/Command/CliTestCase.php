<?php

declare(strict_types=1);

namespace Imanager\Tests\Unit\Cli\Command;

use PHPUnit\Framework\TestCase;

/**
 * Shared scaffolding for CLI command tests.
 *
 * Each test gets a fresh on-disk SQLite file (the commands open the database
 * by path through {@see \Imanager\Cli\Support\DatabaseFactory::connect()}, so
 * `:memory:` would give every PDO connection its own private universe).
 */
abstract class CliTestCase extends TestCase
{
    protected string $dbPath;

    protected function setUp(): void
    {
        $this->dbPath = sys_get_temp_dir() . '/imanager-cli-' . uniqid() . '.sqlite';
    }

    protected function tearDown(): void
    {
        foreach (['', '-wal', '-shm'] as $suffix) {
            $candidate = $this->dbPath . $suffix;
            if (is_file($candidate)) {
                @unlink($candidate);
            }
        }
    }
}
