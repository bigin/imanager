<?php

declare(strict_types=1);

namespace Imanager\Tests\Unit;

use Imanager\Imanager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Imanager::class)]
final class SmokeTest extends TestCase
{
    public function testVersionConstantIsStableTag(): void
    {
        self::assertSame('2.0.0', Imanager::VERSION);
    }
}
