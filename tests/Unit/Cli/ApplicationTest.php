<?php

declare(strict_types=1);

namespace Imanager\Tests\Unit\Cli;

use Imanager\Cli\Application;
use Imanager\Imanager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Application::class)]
final class ApplicationTest extends TestCase
{
    public function testRegistersExpectedCommands(): void
    {
        $app = new Application();

        $expected = [
            'schema:status',
            'schema:migrate',
            'migrate:from-v1',
            'fts:rebuild',
            'optimize',
            'repair',
            'dump',
        ];
        foreach ($expected as $name) {
            self::assertTrue($app->has($name), \sprintf('Command "%s" must be registered', $name));
        }
    }

    public function testReportsImanagerVersion(): void
    {
        $app = new Application();

        self::assertSame('iManager', $app->getName());
        self::assertSame(Imanager::VERSION, $app->getVersion());
    }
}
