# Query

The `Query` builder is the predicate language for
`ItemRepository::query()`. It's a small, immutable value object —
every builder method returns a new `Query` — and it compiles down to
a single parametrised SQL statement against the items table (using
generated columns for hot fields, `json_extract` for cold ones).

This page is the **reference**: signatures, value objects, enum
cases. The step-by-step how-to (pagination flows, selector strings,
FTS hand-off, performance) lives in the
[Query cookbook](../query-cookbook.md).

> Source: `src/Query/{Query,Clause,Operator,OrderBy,Direction,Pagination,SelectorParser}.php`.
> Contract: `tests/Unit/Storage/ItemQueryContract.php`.

---

## Query

```php
namespace Imanager\Query;

final readonly class Query
{
    public function __construct(
        public ?int $categoryId = null,
        public array $where = [],      // list<Clause>
        public array $orderBy = [],    // list<OrderBy>
        public int $limit = 0,
        public int $offset = 0,
    ) {}

    public function inCategory(int $id): self;
    public function where(string $field, string|Operator $op, mixed $value): self;
    public function orderBy(string $field, string|Direction $direction = Direction::Asc): self;
    public function limit(int $n): self;
    public function offset(int $n): self;
}
```

Every method returns a **new** `Query` — the receiver is unchanged.
You can pass the same base query to many call sites and refine each
independently without worrying about shared state.

### `inCategory()`

Restricts the result to one category. **A query without
`inCategory()` searches across all categories** — useful for
admin-side global searches, dangerous as the default for application
code. Set it.

### `where()`

Adds one predicate. The operator argument accepts either the
`Operator` enum or a literal string (`'='`, `'!='`, `'>'`, `'<'`,
`'>='`, `'<='`, `'LIKE'`); the string form coerces to the enum
internally. Multiple `where()` calls AND together — there is **no
OR**. Build OR-shaped logic with a `SelectorParser` source string or
union the results of two queries application-side.

The field name can be:

- A *structural column* — `id`, `name`, `label`, `position`,
  `active`, `created`, `updated`, `categoryId`.
- A *field name* declared on the category. Hot (indexed) fields hit
  the generated column; cold fields use `json_extract`.

### `orderBy()`

Adds one ordering step. Calls compose (left-to-right precedence —
first call wins ties). Direction accepts the `Direction` enum, the
strings `'asc'` / `'desc'` (case-insensitive), or its constants.

### `limit()` / `offset()`

`limit = 0` (the default) means **no limit**. Both are passed through
verbatim to SQLite; for offset-heavy pagination see `Pagination`
below.

### Example — composing a query

```php
use Imanager\Query\{Query, Direction, Operator};

$query = (new Query())
    ->inCategory($blog->id)
    ->where('active', '=', true)
    ->where('position', '>=', 3)
    ->where('name', Operator::Like, 'hello%')
    ->orderBy('created', Direction::Desc)
    ->limit(20);

$items = $storage->items()->query($query);
$total = $storage->items()->count($query);
```

---

## Clause

The AST node behind `where()`. You rarely construct these directly —
`Query::where()` builds them for you — but you can inspect them on
the query value object (`$query->where`):

```php
namespace Imanager\Query;

final readonly class Clause
{
    public function __construct(
        public string $field,
        public Operator $op,
        public mixed $value,
    ) {}
}
```

---

## Operator

```php
namespace Imanager\Query;

enum Operator: string
{
    case Eq   = '=';
    case Neq  = '!=';
    case Lt   = '<';
    case Lte  = '<=';
    case Gt   = '>';
    case Gte  = '>=';
    case Like = 'LIKE';
}
```

`Like` follows SQLite semantics: `%` matches any sequence, `_`
matches one character. Match is case-insensitive for ASCII; non-ASCII
follows the default SQLite collation.

There is intentionally **no `IN`** operator. Cases that want `IN
(...)` should split into multiple equality clauses application-side,
or use the FTS index (`Imanager\Search`) when the input is
unbounded.

---

## OrderBy

```php
namespace Imanager\Query;

final readonly class OrderBy
{
    public function __construct(
        public string $field,
        public Direction $direction = Direction::Asc,
    ) {}
}
```

`Query::orderBy()` constructs one of these per call. Multi-column
ordering is just multiple consecutive `orderBy()` calls — there is no
"add a tiebreaker" helper because chained calls already read that
way:

```php
(new Query())
    ->orderBy('position')          // primary
    ->orderBy('id', Direction::Asc); // tiebreaker
```

---

## Direction

```php
namespace Imanager\Query;

enum Direction: string
{
    case Asc  = 'asc';
    case Desc = 'desc';

    public static function coerce(string|self $value): self;
}
```

`coerce()` accepts the enum or a case-insensitive string and throws
`\ValueError` on unknown input. `Query::orderBy()` calls it on your
behalf — you can pass either form.

---

## Pagination

A small view-model used to compute page numbers and offsets from a
total row count. The `Query` builder doesn't consume it; you compute
the offset/limit yourself and pass them to `Query::offset()` /
`Query::limit()`.

```php
namespace Imanager\Query;

final readonly class Pagination
{
    public function __construct(
        public int $page,
        public int $perPage,
        public int $total,
    ) {}

    public function lastPage(): int;
    public function offset(): int;
    public function hasMore(): bool;
    public function isFirstPage(): bool;
    public function isLastPage(): bool;
}
```

### Typical use

```php
use Imanager\Query\{Pagination, Query};

$perPage = 20;
$page    = max(1, (int) ($_GET['page'] ?? 1));

$base  = (new Query())->inCategory($blog->id)->where('active', '=', true);
$total = $storage->items()->count($base);

$paginated = $base->offset(($page - 1) * $perPage)->limit($perPage);
$items     = $storage->items()->query($paginated);

$pager = new Pagination($page, $perPage, $total);
// $pager->lastPage(), $pager->hasMore(), …
```

`lastPage()` returns at least `1` even for empty result sets.
`offset()` is `($page - 1) * $perPage` clamped to non-negative.

---

## SelectorParser

A tiny string DSL for selectors entered by end users or stored in
configuration. Returns a fresh `Query` you can refine further:

```php
namespace Imanager\Query;

final readonly class SelectorParser
{
    public function parse(string $selector): Query;
}
```

### Grammar

```
selector  := clause (',' clause)*
clause    := identifier op value
op        := '>=' | '<=' | '!=' | '=' | '>' | '<'
identifier := [A-Za-z_][A-Za-z0-9_]*
value     := bare (no escaping) — leading/trailing whitespace stripped
```

A literal `%` inside a `value` paired with `=` is treated as a
`LIKE` wildcard. Examples:

| Selector | Equivalent Query |
|---|---|
| `name=Hello` | `where('name', '=', 'Hello')` |
| `name=Hello%` | `where('name', Operator::Like, 'Hello%')` |
| `position>=5, active=1` | `where('position', '>=', 5)->where('active', '=', 1)` |
| `categoryId=3, name=foo` | `where('categoryId', '=', 3)->where('name', '=', 'foo')` |

`SelectorParser` does **not** consume `inCategory()`,
`orderBy()`, `limit()`, or `offset()` — keep those in code. The
parser exists so you can express filters declaratively (in a config
file, a URL parameter, an editor preset) without exposing PHP
construction syntax.

### Example

```php
$parser = new SelectorParser();
$query  = $parser->parse('active=1, position>=10')
    ->inCategory($blog->id)
    ->orderBy('created', 'desc')
    ->limit(50);
```

---

## Performance notes

- **Hot vs cold fields.** Queries on `indexed = true` fields hit a
  generated column; queries on cold fields scan and run
  `json_extract` per row. Mark fields you predicate on as hot when
  defining the schema.
- **Wildcards in `LIKE`.** A trailing wildcard (`'Hello%'`) can use
  an index. A leading wildcard (`'%Hello'`) cannot and will scan the
  table — prefer `Imanager\Search\FullTextSearch` for free-text
  prefixes.
- **`count()` does its own SELECT.** Calling `count()` and `query()`
  is two queries; cache `count()` if you also call `query()` against
  the same predicate inside one request.

---

## Related

- Step-by-step recipes for building queries: [Query cookbook](../query-cookbook.md).
- The repository that executes queries: [Storage](storage.md).
- The domain values returned: [Domain](domain.md).
- Free-text search (beyond the equality / range operators here):
  `src/Search/FullTextSearch.php`.
