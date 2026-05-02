<?php

declare(strict_types=1);

namespace Imanager\Tests\Unit\Query;

use Imanager\Query\Clause;
use Imanager\Query\Direction;
use Imanager\Query\Operator;
use Imanager\Query\OrderBy;
use Imanager\Query\Query;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Query::class)]
#[CoversClass(Clause::class)]
#[CoversClass(OrderBy::class)]
#[CoversClass(Direction::class)]
final class QueryTest extends TestCase
{
    public function testEmptyQueryHasNoCategoryNoClausesNoOrderingNoLimit(): void
    {
        $q = new Query();

        self::assertNull($q->categoryId);
        self::assertSame([], $q->where);
        self::assertSame([], $q->orderBy);
        self::assertSame(0, $q->limit);
        self::assertSame(0, $q->offset);
    }

    public function testInCategoryReturnsANewQueryWithTheCategoryScopeApplied(): void
    {
        $original = new Query();
        $scoped = $original->inCategory(7);

        self::assertNotSame($original, $scoped);
        self::assertNull($original->categoryId);
        self::assertSame(7, $scoped->categoryId);
    }

    public function testWhereAcceptsBothStringAndEnumOperators(): void
    {
        $q = (new Query())
            ->where('active', '=', true)
            ->where('position', Operator::Gte, 3);

        self::assertCount(2, $q->where);
        self::assertSame(Operator::Eq, $q->where[0]->op);
        self::assertSame(Operator::Gte, $q->where[1]->op);
        self::assertSame('active', $q->where[0]->field);
        self::assertSame(3, $q->where[1]->value);
    }

    public function testOrderByDefaultsToAscendingAndAcceptsBothStringAndEnumDirection(): void
    {
        $q = (new Query())
            ->orderBy('created')
            ->orderBy('position', 'desc')
            ->orderBy('id', Direction::Asc);

        self::assertCount(3, $q->orderBy);
        self::assertSame(Direction::Asc, $q->orderBy[0]->direction);
        self::assertSame(Direction::Desc, $q->orderBy[1]->direction);
        self::assertSame(Direction::Asc, $q->orderBy[2]->direction);
    }

    public function testLimitAndOffsetReturnNewInstances(): void
    {
        $q = (new Query())->limit(20)->offset(40);

        self::assertSame(20, $q->limit);
        self::assertSame(40, $q->offset);
    }

    public function testNegativeLimitIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Query(limit: -1);
    }

    public function testNegativeOffsetIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Query(offset: -1);
    }

    public function testQueryIsImmutableAcrossChainedCalls(): void
    {
        $base = (new Query())->inCategory(1);
        $a = $base->where('position', '>=', 3);
        $b = $base->where('active', '=', true);

        self::assertCount(0, $base->where);
        self::assertCount(1, $a->where);
        self::assertCount(1, $b->where);
        self::assertSame('position', $a->where[0]->field);
        self::assertSame('active', $b->where[0]->field);
    }
}
