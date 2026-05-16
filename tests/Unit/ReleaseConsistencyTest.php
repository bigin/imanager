<?php

declare(strict_types=1);

namespace Imanager\Tests\Unit;

use Imanager\Imanager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Guards the invariant that `Imanager::VERSION` matches the topmost
 * `[X.Y.Z]` section in CHANGELOG.md. Catches the "forgot to bump the
 * constant" mistake at CI time instead of after release.
 *
 * 2.0.1 and 2.0.2 both shipped with `VERSION = '2.0.0'` before this
 * test existed; the 2.1.0 release introduced the test alongside the
 * one-off correction.
 */
#[CoversClass(Imanager::class)]
final class ReleaseConsistencyTest extends TestCase
{
    public function testVersionConstantMatchesTopmostChangelogEntry(): void
    {
        $changelog = file_get_contents(\dirname(__DIR__, 2) . '/CHANGELOG.md');
        self::assertNotFalse($changelog, 'CHANGELOG.md not readable');

        // Match the first "## [X.Y.Z]" — skip an optional "## [Unreleased]"
        // if a future release ever introduces that convention.
        preg_match_all('/^## \[(\d+\.\d+\.\d+)\]/m', $changelog, $matches);

        self::assertNotEmpty($matches[1], 'CHANGELOG.md has no versioned section');

        $latest = $matches[1][0];

        self::assertSame(
            $latest,
            Imanager::VERSION,
            \sprintf(
                'Imanager::VERSION (%s) does not match the topmost CHANGELOG entry (%s). '
                . 'Bump src/Imanager.php in lockstep with every release.',
                Imanager::VERSION,
                $latest,
            ),
        );
    }
}
