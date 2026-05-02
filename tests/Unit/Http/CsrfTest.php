<?php

declare(strict_types=1);

namespace Imanager\Tests\Unit\Http;

use Imanager\Http\ArraySessionStore;
use Imanager\Http\Csrf;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Csrf::class)]
final class CsrfTest extends TestCase
{
    public function testTokenIsStableAcrossCallsForTheSameName(): void
    {
        $csrf = new Csrf(new ArraySessionStore());

        $first = $csrf->token('form-1');
        $second = $csrf->token('form-1');

        self::assertSame($first, $second);
    }

    public function testDifferentNamesGetDifferentTokens(): void
    {
        $csrf = new Csrf(new ArraySessionStore());

        $a = $csrf->token('form-1');
        $b = $csrf->token('form-2');

        self::assertNotSame($a, $b);
    }

    public function testTokensAreLongHexStrings(): void
    {
        $csrf = new Csrf(new ArraySessionStore());

        $token = $csrf->token();

        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $token);
    }

    public function testValidateAcceptsTheCurrentToken(): void
    {
        $csrf = new Csrf(new ArraySessionStore());
        $token = $csrf->token('form-1');

        self::assertTrue($csrf->validate('form-1', $token));
    }

    public function testValidateRejectsAnUnknownName(): void
    {
        $csrf = new Csrf(new ArraySessionStore());
        $csrf->token('form-1');

        self::assertFalse($csrf->validate('form-2', 'whatever'));
    }

    public function testValidateRejectsAnEmptyToken(): void
    {
        $csrf = new Csrf(new ArraySessionStore());
        $csrf->token('form-1');

        self::assertFalse($csrf->validate('form-1', ''));
    }

    public function testValidateRejectsAMismatchedToken(): void
    {
        $csrf = new Csrf(new ArraySessionStore());
        $csrf->token('form-1');

        self::assertFalse($csrf->validate('form-1', 'definitely-wrong-token'));
    }

    public function testRotateInvalidatesThePreviousToken(): void
    {
        $csrf = new Csrf(new ArraySessionStore());
        $previous = $csrf->token('form-1');

        $next = $csrf->rotate('form-1');

        self::assertNotSame($previous, $next);
        self::assertFalse($csrf->validate('form-1', $previous));
        self::assertTrue($csrf->validate('form-1', $next));
    }

    public function testClearWipesEveryToken(): void
    {
        $csrf = new Csrf(new ArraySessionStore());
        $a = $csrf->token('form-1');
        $b = $csrf->token('form-2');

        $csrf->clear();

        self::assertFalse($csrf->validate('form-1', $a));
        self::assertFalse($csrf->validate('form-2', $b));
    }

    public function testEvictsOldestTokenWhenMaxIsExceeded(): void
    {
        $session = new ArraySessionStore();
        $csrf = new Csrf($session, maxTokens: 3);

        $first = $csrf->token('a');
        $csrf->token('b');
        $csrf->token('c');
        $csrf->token('d');  // evicts 'a'

        self::assertFalse($csrf->validate('a', $first));
        self::assertTrue($csrf->validate('d', $csrf->token('d')));
    }

    public function testRejectsZeroOrNegativeMaxTokens(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Csrf(new ArraySessionStore(), maxTokens: 0);
    }

    public function testIgnoresGarbageInTheSessionBucket(): void
    {
        $session = new ArraySessionStore();
        $session->set('csrf_tokens', 'not-an-array');
        $csrf = new Csrf($session);

        $token = $csrf->token('form-1');

        // Generation succeeded despite the garbage; the bucket got rebuilt.
        self::assertNotEmpty($token);
        self::assertTrue($csrf->validate('form-1', $token));
    }
}
