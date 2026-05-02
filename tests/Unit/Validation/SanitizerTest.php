<?php

declare(strict_types=1);

namespace Imanager\Tests\Unit\Validation;

use Imanager\Validation\Sanitizer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(Sanitizer::class)]
final class SanitizerTest extends TestCase
{
    private Sanitizer $sanitizer;

    protected function setUp(): void
    {
        $this->sanitizer = new Sanitizer();
    }

    // ─────────────────────────────── text ───────────────────────────────

    public function testTextStripsControlCharacters(): void
    {
        self::assertSame('hello world', $this->sanitizer->text("hello\x00 \x07world"));
    }

    public function testTextCollapsesWhitespaceIncludingNewlinesAndTabs(): void
    {
        self::assertSame('a b c', $this->sanitizer->text("a   b\n\tc"));
    }

    public function testTextTrimsLeadingAndTrailingWhitespace(): void
    {
        self::assertSame('hello', $this->sanitizer->text('  hello  '));
    }

    public function testTextTruncatesByUnicodeCharacterCount(): void
    {
        // 10 emoji chars, each multi-byte in UTF-8.
        $emoji = str_repeat('🙂', 10);
        $result = $this->sanitizer->text($emoji, 5);

        self::assertSame(5, mb_strlen($result, 'UTF-8'));
    }

    public function testTextOnEmptyInputReturnsEmpty(): void
    {
        self::assertSame('', $this->sanitizer->text(''));
    }

    // ───────────────────────────── multiline ────────────────────────────

    public function testMultilinePreservesNewlines(): void
    {
        self::assertSame("line one\nline two", $this->sanitizer->multiline("line one\nline two"));
    }

    public function testMultilineNormalizesCRLFAndCRToLF(): void
    {
        self::assertSame(
            "a\nb\nc",
            $this->sanitizer->multiline("a\r\nb\rc"),
        );
    }

    public function testMultilineStripsControlCharsExceptWhitespace(): void
    {
        self::assertSame("a\nb", $this->sanitizer->multiline("a\x00\nb"));
    }

    // ─────────────────────────────── slug ───────────────────────────────

    /**
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function slugCases(): iterable
    {
        yield 'simple words'         => ['Hello World',     'hello-world'];
        yield 'mixed case'           => ['MyBlogPost',      'myblogpost'];
        yield 'punctuation collapse' => ['foo!?@#bar',      'foo-bar'];
        yield 'multiple dashes'      => ['foo---bar',       'foo-bar'];
        yield 'leading dashes'       => ['---foo---',       'foo'];
        yield 'numbers preserved'    => ['Article 42',      'article-42'];
    }

    #[DataProvider('slugCases')]
    public function testSlugProducesUrlSafeIdentifiers(string $input, string $expected): void
    {
        self::assertSame($expected, $this->sanitizer->slug($input));
    }

    public function testSlugTransliteratesNonAsciiToAsciiWhereLibcSupportsIt(): void
    {
        // libc transliteration coverage varies — we accept either the
        // transliterated form ("naive") or its locale-collapsed equivalent
        // ("nave"). Both are unambiguously slug-shaped.
        $result = $this->sanitizer->slug('naïve résumé');

        self::assertMatchesRegularExpression('/^[a-z0-9-]+$/', $result);
        self::assertGreaterThan(0, \strlen($result));
    }

    public function testSlugTruncatesAndStripsTrailingDashes(): void
    {
        $result = $this->sanitizer->slug('hello-world-this-is-a-very-long-title', 11);

        self::assertSame('hello-world', $result);
    }

    public function testSlugReturnsEmptyForFullyUnsanitizableInput(): void
    {
        self::assertSame('', $this->sanitizer->slug('---'));
    }

    // ───────────────────────────── identifier ───────────────────────────

    /**
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function identifierCases(): iterable
    {
        yield 'alphanumeric'        => ['blog_post',     'blog_post'];
        yield 'punctuation'         => ['blog-post',     'blog_post'];
        yield 'spaces'              => ['blog post',     'blog_post'];
        yield 'leading digit'       => ['42posts',       '_42posts'];
        yield 'collapse underscore' => ['blog___post',   'blog_post'];
        yield 'trim underscore'     => ['__title__',     'title'];
        yield 'empty after strip'   => ['---',           ''];
    }

    #[DataProvider('identifierCases')]
    public function testIdentifierProducesValidPhpIdentifiers(string $input, string $expected): void
    {
        self::assertSame($expected, $this->sanitizer->identifier($input));
    }

    public function testIdentifierRespectsMaxLength(): void
    {
        $result = $this->sanitizer->identifier('a_very_long_field_name_indeed', 10);

        self::assertSame('a_very_lon', $result);
    }

    // ─────────────────────────────── filename ───────────────────────────

    public function testFilenameStripsDirectoryComponents(): void
    {
        self::assertSame('photo.jpg', $this->sanitizer->filename('/etc/passwd/../photo.jpg'));
    }

    public function testFilenameStripsResidualPathSeparators(): void
    {
        // basename() only respects the platform separator; we explicitly
        // strip both \ and / afterwards so a Unix-side `evil\\path.exe`
        // doesn't smuggle a backslash through.
        self::assertSame('shell.exe', $this->sanitizer->filename('shell\\.exe'));
    }

    public function testFilenameStripsControlCharacters(): void
    {
        self::assertSame('photo.jpg', $this->sanitizer->filename("photo\x00.jpg"));
    }

    // ─────────────────────────────── email ──────────────────────────────

    public function testEmailReturnsValidAddress(): void
    {
        self::assertSame('user@example.com', $this->sanitizer->email('user@example.com'));
    }

    public function testEmailReturnsNullForInvalid(): void
    {
        self::assertNull($this->sanitizer->email('not-an-email'));
        self::assertNull($this->sanitizer->email('user@'));
        self::assertNull($this->sanitizer->email('@example.com'));
    }

    // ─────────────────────────────── url ────────────────────────────────

    public function testUrlReturnsValidHttpUrls(): void
    {
        self::assertSame(
            'https://example.com/path?q=1',
            $this->sanitizer->url('https://example.com/path?q=1'),
        );
        self::assertSame('http://example.com', $this->sanitizer->url('http://example.com'));
    }

    public function testUrlRejectsNonHttpSchemes(): void
    {
        self::assertNull($this->sanitizer->url('ftp://example.com/file'));
        self::assertNull($this->sanitizer->url('javascript:alert(1)'));
        self::assertNull($this->sanitizer->url('file:///etc/passwd'));
    }

    public function testUrlReturnsNullForMalformedInput(): void
    {
        self::assertNull($this->sanitizer->url('not a url'));
        self::assertNull($this->sanitizer->url(''));
    }

    // ───────────────────────────── int / float ──────────────────────────

    public function testIntCoercesNumericStringsAndBoolsAndFloats(): void
    {
        self::assertSame(42, $this->sanitizer->int('42'));
        self::assertSame(42, $this->sanitizer->int(42.9));
        self::assertSame(1, $this->sanitizer->int(true));
        self::assertSame(0, $this->sanitizer->int(false));
    }

    public function testIntFallsBackToZeroForUnparseableInput(): void
    {
        self::assertSame(0, $this->sanitizer->int('not a number'));
        self::assertSame(0, $this->sanitizer->int(null));
        self::assertSame(0, $this->sanitizer->int([1, 2, 3]));
    }

    public function testIntClampsToBounds(): void
    {
        self::assertSame(5, $this->sanitizer->int(100, max: 5));
        self::assertSame(0, $this->sanitizer->int(-10, min: 0));
        self::assertSame(7, $this->sanitizer->int(7, min: 0, max: 10));
    }

    public function testFloatCoercesAndClamps(): void
    {
        self::assertSame(3.14, $this->sanitizer->float('3.14'));
        self::assertSame(0.0, $this->sanitizer->float('not a number'));
        self::assertSame(1.0, $this->sanitizer->float(2.5, max: 1.0));
    }

    // ─────────────────────────────── bool ───────────────────────────────

    /**
     * @return iterable<string, array{0: mixed, 1: bool}>
     */
    public static function boolCases(): iterable
    {
        yield 'true literal'  => [true,    true];
        yield 'false literal' => [false,   false];
        yield 'string true'   => ['true',  true];
        yield 'string yes'    => ['yes',   true];
        yield 'string on'     => ['on',    true];
        yield 'string 1'      => ['1',     true];
        yield 'int 1'         => [1,       true];
        yield 'string false'  => ['false', false];
        yield 'string no'     => ['no',    false];
        yield 'string off'    => ['off',   false];
        yield 'string 0'      => ['0',     false];
        yield 'int 0'         => [0,       false];
        yield 'gibberish'     => ['maybe', false];
        yield 'null'          => [null,    false];
    }

    #[DataProvider('boolCases')]
    public function testBoolCoerces(mixed $input, bool $expected): void
    {
        self::assertSame($expected, $this->sanitizer->bool($input));
    }

    // ───────────────────────────── entities ─────────────────────────────

    public function testEntitiesEncodesAllDangerousCharacters(): void
    {
        self::assertSame(
            '&lt;script&gt;alert(&quot;xss&quot;)&lt;/script&gt;',
            $this->sanitizer->entities('<script>alert("xss")</script>'),
        );
    }

    public function testEntitiesEncodesBothQuoteStyles(): void
    {
        // ENT_HTML5 emits the named entity &apos; for the single quote
        // (HTML4 / default mode would emit &#039;).
        self::assertSame(
            '&apos;single&apos; and &quot;double&quot;',
            $this->sanitizer->entities('\'single\' and "double"'),
        );
    }

    // ───────────────────────────── markdown ─────────────────────────────

    public function testMarkdownRendersBasicSyntax(): void
    {
        $html = $this->sanitizer->markdown("# Title\n\nA paragraph with **bold**.");

        self::assertStringContainsString('<h1>Title</h1>', $html);
        self::assertStringContainsString('<strong>bold</strong>', $html);
    }

    public function testMarkdownEscapesRawHtmlInSafeMode(): void
    {
        $html = $this->sanitizer->markdown('Hello <script>alert(1)</script>');

        self::assertStringNotContainsString('<script>', $html);
    }

    // ─────────────────────────────── html ───────────────────────────────

    public function testHtmlPreservesAllowedTags(): void
    {
        $clean = $this->sanitizer->html('<p>Hello <strong>world</strong>!</p>');

        self::assertStringContainsString('<p>Hello <strong>world</strong>!</p>', $clean);
    }

    public function testHtmlStripsDisallowedTags(): void
    {
        $clean = $this->sanitizer->html('<p>safe</p><script>alert(1)</script><iframe src="x"></iframe>');

        self::assertStringContainsString('<p>safe</p>', $clean);
        self::assertStringNotContainsString('<script', $clean);
        self::assertStringNotContainsString('<iframe', $clean);
    }

    public function testHtmlStripsJavascriptHrefs(): void
    {
        $clean = $this->sanitizer->html('<a href="javascript:alert(1)">click</a>');

        self::assertStringNotContainsString('javascript:', $clean);
    }
}
