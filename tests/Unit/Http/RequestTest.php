<?php

declare(strict_types=1);

namespace Imanager\Tests\Unit\Http;

use Imanager\Http\Request;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Request::class)]
final class RequestTest extends TestCase
{
    public function testDefaultsForEmptyRequest(): void
    {
        $req = new Request();

        self::assertSame('GET', $req->method);
        self::assertSame('http', $req->scheme);
        self::assertSame('localhost', $req->host);
        self::assertSame('/', $req->uri);
        self::assertFalse($req->isSecure());
    }

    public function testRawGetReturnsValueOrDefault(): void
    {
        $req = new Request(get: ['page' => '3', 'tag' => 'php']);

        self::assertSame('3', $req->get('page'));
        self::assertSame('php', $req->get('tag'));
        self::assertNull($req->get('absent'));
        self::assertSame('fallback', $req->get('absent', default: 'fallback'));
    }

    public function testGetWithCastInt(): void
    {
        $req = new Request(get: ['page' => '42']);

        self::assertSame(42, $req->get('page', cast: 'int'));
    }

    public function testGetIntCoercesAndFallsBack(): void
    {
        $req = new Request(get: ['page' => '7', 'bad' => 'not-numeric']);

        self::assertSame(7, $req->getInt('page'));
        self::assertSame(0, $req->getInt('bad'));
        self::assertSame(99, $req->getInt('absent', 99));
    }

    public function testGetStringCoerces(): void
    {
        $req = new Request(get: ['name' => 'Hello', 'count' => 42]);

        self::assertSame('Hello', $req->getString('name'));
        self::assertSame('42', $req->getString('count'));
        self::assertSame('fallback', $req->getString('absent', 'fallback'));
    }

    public function testGetBoolHandlesAllCommonShapes(): void
    {
        $req = new Request(get: [
            'on' => '1',
            'off' => '0',
            'yes' => 'yes',
            'no' => 'no',
            'truthy' => 'true',
            'falsy' => 'false',
            'gibberish' => 'maybe',
        ]);

        self::assertTrue($req->getBool('on'));
        self::assertFalse($req->getBool('off'));
        self::assertTrue($req->getBool('yes'));
        self::assertFalse($req->getBool('no'));
        self::assertTrue($req->getBool('truthy'));
        self::assertFalse($req->getBool('falsy'));
        self::assertFalse($req->getBool('gibberish'));
        self::assertFalse($req->getBool('absent'));
    }

    public function testPostMirrorsGetSemantics(): void
    {
        $req = new Request(post: ['title' => 'Hello', 'pages' => '5']);

        self::assertSame('Hello', $req->postString('title'));
        self::assertSame(5, $req->postInt('pages'));
        self::assertNull($req->post('absent'));
    }

    public function testPutAndPatchAccessors(): void
    {
        $req = new Request(
            put: ['updated_at' => '2024-01-01'],
            patch: ['title' => 'New Title'],
            method: 'PATCH',
        );

        self::assertSame('2024-01-01', $req->put('updated_at'));
        self::assertSame('New Title', $req->patch('title'));
        self::assertNull($req->put('absent'));
    }

    public function testCookieAccess(): void
    {
        $req = new Request(cookies: ['session' => 'abc123']);

        self::assertSame('abc123', $req->cookie('session'));
        self::assertNull($req->cookie('absent'));
    }

    public function testFileAccessReturnsArrayOrNull(): void
    {
        $req = new Request(files: [
            'avatar' => ['name' => 'photo.jpg', 'size' => 1024],
        ]);

        self::assertSame(['name' => 'photo.jpg', 'size' => 1024], $req->file('avatar'));
        self::assertNull($req->file('absent'));
    }

    public function testIsMethodCaseInsensitive(): void
    {
        $req = new Request(method: 'POST');

        self::assertTrue($req->isMethod('POST'));
        self::assertTrue($req->isMethod('post'));
        self::assertFalse($req->isMethod('GET'));
    }

    public function testIsSecureWhenSchemeIsHttps(): void
    {
        $req = new Request(scheme: 'https');

        self::assertTrue($req->isSecure());
    }

    public function testHeaderReadsHttpServerVariables(): void
    {
        $req = new Request(server: [
            'HTTP_USER_AGENT' => 'PHPUnit',
            'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest',
            'CONTENT_TYPE' => 'application/json',
        ]);

        self::assertSame('PHPUnit', $req->header('User-Agent'));
        self::assertSame('XMLHttpRequest', $req->header('X-Requested-With'));
        self::assertSame('application/json', $req->header('Content-Type'));
        self::assertNull($req->header('Not-Set'));
    }

    public function testCastWithUnknownTypeThrows(): void
    {
        $req = new Request(get: ['x' => '1']);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown cast type "rocket"');
        $req->get('x', cast: 'rocket');
    }

    public function testCastPassesArrayValuesThroughUnchanged(): void
    {
        $req = new Request(post: ['tags' => ['php', 'cms']]);

        self::assertSame(['php', 'cms'], $req->post('tags', cast: 'string'));
    }

    public function testFromGlobalsBuildsFromSuperglobals(): void
    {
        $originalGet = $_GET;
        $originalServer = $_SERVER;

        try {
            $_GET = ['q' => 'hello'];
            $_SERVER['REQUEST_METHOD'] = 'GET';
            $_SERVER['HTTPS'] = 'on';
            $_SERVER['HTTP_HOST'] = 'example.com';
            $_SERVER['REQUEST_URI'] = '/blog?q=hello';

            $req = Request::fromGlobals();

            self::assertSame('GET', $req->method);
            self::assertSame('https', $req->scheme);
            self::assertSame('example.com', $req->host);
            self::assertTrue($req->isSecure());
            self::assertSame('hello', $req->getString('q'));
        } finally {
            $_GET = $originalGet;
            $_SERVER = $originalServer;
        }
    }

    public function testFromGlobalsRejectsHostWithIllegalCharacters(): void
    {
        $originalServer = $_SERVER;

        try {
            $_SERVER['REQUEST_METHOD'] = 'GET';
            $_SERVER['HTTP_HOST'] = "evil\nhost";  // CRLF injection attempt

            $req = Request::fromGlobals();

            self::assertSame('localhost', $req->host);
        } finally {
            $_SERVER = $originalServer;
        }
    }

    public function testFromGlobalsDetectsXForwardedProtoHttps(): void
    {
        $originalServer = $_SERVER;

        try {
            $_SERVER['REQUEST_METHOD'] = 'GET';
            $_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https';
            unset($_SERVER['HTTPS']);

            $req = Request::fromGlobals();

            self::assertSame('https', $req->scheme);
        } finally {
            $_SERVER = $originalServer;
        }
    }
}
