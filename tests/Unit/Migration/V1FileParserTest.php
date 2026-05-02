<?php

declare(strict_types=1);

namespace Imanager\Tests\Unit\Migration;

use Imanager\Migration\MigrationParseException;
use Imanager\Migration\V1FileParser;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(V1FileParser::class)]
#[CoversClass(MigrationParseException::class)]
final class V1FileParserTest extends TestCase
{
    private V1FileParser $parser;

    protected function setUp(): void
    {
        $this->parser = new V1FileParser();
    }

    public function testParsesCategoriesFixtureIntoFlatArray(): void
    {
        $data = $this->parser->parseFile($this->fixturePath('categories/categories.php'));

        self::assertCount(2, $data);
        self::assertSame('Pages', $data[1]['name']);
        self::assertSame('pages', $data[1]['slug']);
        self::assertSame(1, $data[1]['position']);
        self::assertSame('\Imanager\Category', $data[1]['__class']);
    }

    public function testParsesFieldsFixtureFlatteningNestedSetState(): void
    {
        $data = $this->parser->parseFile($this->fixturePath('fields/1.fields.php'));

        self::assertArrayHasKey('slug', $data);
        self::assertArrayHasKey('content', $data);
        self::assertArrayHasKey('images', $data);

        $slug = $data['slug'];
        self::assertSame('\Imanager\Field', $slug['__class']);
        self::assertSame('slug', $slug['type']);
        self::assertFalse($slug['required']);

        // Nested FieldConfigs flattened to a plain array with the class tag.
        self::assertIsArray($slug['configs']);
        self::assertSame('\Imanager\FieldConfigs', $slug['configs']['__class']);
        self::assertNull($slug['configs']['accept_types']);

        $images = $data['images'];
        self::assertSame('gif|jpe?g|png', $images['configs']['accept_types']);
    }

    public function testParsesItemsFixtureWithCustomSubclass(): void
    {
        $data = $this->parser->parseFile($this->fixturePath('items/1.items.php'));

        self::assertCount(2, $data);
        self::assertSame('\Scriptor\Core\Page', $data[1]['__class']);
        self::assertSame('Demo Page', $data[1]['name']);
        self::assertTrue($data[1]['active']);
        self::assertSame('demo-page', $data[1]['slug']);
        self::assertSame('Lorem ipsum dolor sit amet.', $data[1]['content']);
        self::assertNull($data[1]['images']);
    }

    public function testParseSourceHandlesScalarsAndUnaryMinus(): void
    {
        $data = $this->parser->parseSource("<?php return array('a' => -7, 'b' => -1.5, 'c' => true);");

        self::assertSame(-7, $data['a']);
        self::assertSame(-1.5, $data['b']);
        self::assertTrue($data['c']);
    }

    public function testRejectsArbitraryFunctionCalls(): void
    {
        $this->expectException(MigrationParseException::class);
        $this->parser->parseSource('<?php return array("x" => system("rm -rf /"));');
    }

    public function testRejectsFilesWithoutTopLevelReturn(): void
    {
        $this->expectException(MigrationParseException::class);
        $this->expectExceptionMessage('No top-level `return`');
        $this->parser->parseSource('<?php $x = 1;');
    }

    public function testReportsMissingFile(): void
    {
        $this->expectException(MigrationParseException::class);
        $this->expectExceptionMessage('Cannot read file');
        $this->parser->parseFile('/this/path/does/not/exist.php');
    }

    private function fixturePath(string $tail): string
    {
        return \dirname(__DIR__, 2) . '/Fixtures/v1/datasets/buffers/' . $tail;
    }
}
