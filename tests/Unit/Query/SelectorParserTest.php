<?php

declare(strict_types=1);

namespace Imanager\Tests\Unit\Query;

use Imanager\Exception\InvalidSelectorException;
use Imanager\Query\Operator;
use Imanager\Query\SelectorParser;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(SelectorParser::class)]
#[CoversClass(InvalidSelectorException::class)]
final class SelectorParserTest extends TestCase
{
    private SelectorParser $parser;

    protected function setUp(): void
    {
        $this->parser = new SelectorParser();
    }

    public function testEmptyStringYieldsAnEmptyQuery(): void
    {
        $q = $this->parser->parse('');

        self::assertSame([], $q->where);
        self::assertNull($q->categoryId);
    }

    /**
     * @return iterable<string, array{0: string, 1: string, 2: Operator, 3: string}>
     */
    public static function singleClauseCases(): iterable
    {
        yield 'equality'    => ['name=foo',        'name', Operator::Eq, 'foo'];
        yield 'inequality'  => ['name!=foo',       'name', Operator::Neq, 'foo'];
        yield 'less than'   => ['position<3',      'position', Operator::Lt, '3'];
        yield 'less or eq'  => ['position<=3',     'position', Operator::Lte, '3'];
        yield 'greater'     => ['position>3',      'position', Operator::Gt, '3'];
        yield 'greater eq'  => ['position>=3',     'position', Operator::Gte, '3'];
        yield 'starts with' => ['name=foo%',       'name', Operator::Like, 'foo%'];
        yield 'ends with'   => ['name=%foo',       'name', Operator::Like, '%foo'];
        yield 'contains'    => ['name=%foo%',      'name', Operator::Like, '%foo%'];
    }

    #[DataProvider('singleClauseCases')]
    public function testParsesEachSupportedOperator(
        string $selector,
        string $field,
        Operator $op,
        string $value,
    ): void {
        $q = $this->parser->parse($selector);

        self::assertCount(1, $q->where);
        self::assertSame($field, $q->where[0]->field);
        self::assertSame($op, $q->where[0]->op);
        self::assertSame($value, $q->where[0]->value);
    }

    public function testWhitespaceAroundFieldOperatorAndValueIsTolerated(): void
    {
        $q = $this->parser->parse('  position   >=   3  ');

        self::assertCount(1, $q->where);
        self::assertSame('position', $q->where[0]->field);
        self::assertSame(Operator::Gte, $q->where[0]->op);
        self::assertSame('3', $q->where[0]->value);
    }

    public function testMultipleClausesAreCommaSeparatedAndCombineAsAnd(): void
    {
        $q = $this->parser->parse('name=foo, position>=3, active=1');

        self::assertCount(3, $q->where);
        self::assertSame('name', $q->where[0]->field);
        self::assertSame('position', $q->where[1]->field);
        self::assertSame('active', $q->where[2]->field);
    }

    public function testValueContainingALessThanSignDoesNotConfuseTheOperatorScanner(): void
    {
        // Regression: a naive operator scan would split on `<` here.
        $q = $this->parser->parse('name=foo<bar');

        self::assertCount(1, $q->where);
        self::assertSame('name', $q->where[0]->field);
        self::assertSame(Operator::Eq, $q->where[0]->op);
        self::assertSame('foo<bar', $q->where[0]->value);
    }

    public function testEmptyValueAfterOperatorIsRejected(): void
    {
        $this->expectException(InvalidSelectorException::class);
        $this->parser->parse('name=');
    }

    public function testSelectorWithoutAnOperatorIsRejected(): void
    {
        $this->expectException(InvalidSelectorException::class);
        $this->parser->parse('not a clause');
    }

    public function testFieldNameMustBeAValidIdentifier(): void
    {
        $this->expectException(InvalidSelectorException::class);
        $this->parser->parse('123field=foo');
    }

    public function testEmptyClausesBetweenCommasAreSkipped(): void
    {
        $q = $this->parser->parse('name=foo,, position>=3,,');

        self::assertCount(2, $q->where);
    }
}
