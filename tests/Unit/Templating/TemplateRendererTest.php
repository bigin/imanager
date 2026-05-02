<?php

declare(strict_types=1);

namespace Imanager\Tests\Unit\Templating;

use Imanager\Templating\TemplateException;
use Imanager\Templating\TemplateRenderer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(TemplateRenderer::class)]
#[CoversClass(TemplateException::class)]
final class TemplateRendererTest extends TestCase
{
    private TemplateRenderer $renderer;

    protected function setUp(): void
    {
        $this->renderer = new TemplateRenderer();
    }

    public function testSubstitutesSimpleVariable(): void
    {
        $html = $this->renderer->render('Hello {{name}}!', ['name' => 'World']);

        self::assertSame('Hello World!', $html);
    }

    public function testSubstitutesMultipleVariables(): void
    {
        $html = $this->renderer->render(
            '{{greeting}}, {{name}}!',
            ['greeting' => 'Hi', 'name' => 'iManager'],
        );

        self::assertSame('Hi, iManager!', $html);
    }

    public function testSubstitutesTheSameVariableInMultiplePlaces(): void
    {
        $html = $this->renderer->render(
            '<a href="/{{slug}}">{{slug}}</a>',
            ['slug' => 'demo'],
        );

        self::assertSame('<a href="/demo">demo</a>', $html);
    }

    public function testStringifiesScalarValues(): void
    {
        $html = $this->renderer->render(
            'page {{n}} of {{ratio}}',
            ['n' => 3, 'ratio' => 0.5],
        );

        self::assertSame('page 3 of 0.5', $html);
    }

    public function testRendersBoolAsBinaryFlag(): void
    {
        self::assertSame('1', $this->renderer->render('{{flag}}', ['flag' => true]));
        self::assertSame('', $this->renderer->render('{{flag}}', ['flag' => false]));
    }

    public function testNullVariableRendersAsEmptyString(): void
    {
        self::assertSame('value: ', $this->renderer->render('value: {{x}}', ['x' => null]));
    }

    public function testSupportsStringableObjects(): void
    {
        $stringable = new class implements \Stringable {
            public function __toString(): string
            {
                return 'computed';
            }
        };

        self::assertSame('computed', $this->renderer->render('{{x}}', ['x' => $stringable]));
    }

    public function testStripsUnmatchedPlaceholdersSilently(): void
    {
        $html = $this->renderer->render('hello {{name}}, you are at {{location}}', ['name' => 'Bob']);

        self::assertSame('hello Bob, you are at ', $html);
    }

    public function testIgnoresUnknownVariableTypes(): void
    {
        // Arrays and arbitrary objects are skipped — placeholder gets stripped.
        $html = $this->renderUntyped('{{a}}{{b}}', [
            'a' => [1, 2],
            'b' => new \stdClass(),
        ]);

        self::assertSame('', $html);
    }

    public function testPlaceholdersWithInvalidNamesAreNotSubstituted(): void
    {
        // Identifier rule: [A-Za-z_][A-Za-z0-9_]*. Anything else stays literal.
        $html = $this->renderer->render(
            '{{ name }} {{na me}} {{normal}}',
            ['normal' => 'ok'],
        );

        self::assertSame('{{ name }} {{na me}} ok', $html);
    }

    public function testRenderFileReadsFromDisk(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'imanager-tpl-');
        \assert(\is_string($path));
        file_put_contents($path, 'hello {{name}}');

        try {
            self::assertSame('hello World', $this->renderer->renderFile($path, ['name' => 'World']));
        } finally {
            @unlink($path);
        }
    }

    public function testRenderFileThrowsForMissingFile(): void
    {
        $this->expectException(TemplateException::class);
        $this->expectExceptionMessage('Cannot read template');
        $this->renderer->renderFile('/this/path/does/not/exist');
    }

    /**
     * Test helper that intentionally bypasses the static-typed `$vars` shape
     * on `TemplateRenderer::render()`. Used to feed deliberately wrong types
     * into the silent-skip fallback path without triggering analyzer errors
     * at the call site (which is the exact behavior under test).
     *
     * @param array<string, mixed> $vars
     *
     * @psalm-suppress InvalidArgument, ArgumentTypeCoercion
     */
    private function renderUntyped(string $template, array $vars): string
    {
        return $this->renderer->render($template, $vars);
    }
}
