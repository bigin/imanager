<?php

declare(strict_types=1);

namespace Imanager\Tests\Unit\Field;

use Imanager\Field\RenderContext;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(RenderContext::class)]
final class RenderContextTest extends TestCase
{
    public function testCarriesInputNameAndOptionalItemId(): void
    {
        $ctx = new RenderContext('item[title]', 42);

        self::assertSame('item[title]', $ctx->inputName);
        self::assertSame(42, $ctx->itemId);
    }

    public function testItemIdDefaultsToNullForFreshItems(): void
    {
        $ctx = new RenderContext('item[title]');

        self::assertNull($ctx->itemId);
    }
}
