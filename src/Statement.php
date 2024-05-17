<?php

/**
 * League.Csv (https://csv.thephpleague.com)
 *
 * (c) Ignace Nyamagana Butera <nyamsprod@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace League\Csv;

use ArrayIterator;
use CallbackFilterIterator;
use Closure;
use Iterator;
use OutOfBoundsException;
use ReflectionException;
use ReflectionFunction;

use function array_key_exists;
use function array_reduce;
use function array_search;
use function array_values;
use function is_string;

/**
 * Criteria to filter a {@link TabularDataReader} object.
 *
 * @phpstan-import-type ConditionExtended from \League\Csv\Query\PredicateCombinator
 * @phpstan-import-type OrderingExtended from \League\Csv\Query\SortCombinator
 */
class Statement
{
    /** @var array<ConditionExtended> Callables to filter the iterator. */
    protected array $where = [];
    /** @var array<OrderingExtended> Callables to sort the iterator. */
    protected array $order_by = [];
    /** iterator Offset. */
    protected int $offset = 0;
    /** iterator maximum length. */
    protected int $limit = -1;
    /** @var array<string|int> */
    protected array $select = [];

    /**
     * @throws Exception
     */
    public static function create(?callable $where = null, int $offset = 0, int $limit = -1): self
    {
        $stmt = new self();
        if (null !== $where) {
            $stmt = $stmt->where($where);
        }

        return $stmt->offset($offset)->limit($limit);
    }

    /**
     * Sets the Iterator element columns.
     */
    public function select(string|int ...$columns): self
    {
        if ($columns === $this->select) {
            return $this;
        }

        $clone = clone $this;
        $clone->select = $columns;

        return $clone;
    }

    /**
     * Sets the Iterator filter method.
     *
     * @param callable(array, array-key): bool $where
     *
     * @throws ReflectionException
     * @throws InvalidArgument
     */
    public function where(callable $where): self
    {
        $where = self::wrapSingleArgumentCallable($where);

        $clone = clone $this;
        $clone->where[] = $where;

        return $clone;
    }

    /**
     * Sanitize the number of required parameters for a predicate.
     *
     * To avoid BC break in 9.16+ version the predicate should have
     * at least 1 required argument.
     *
     * @throws InvalidArgument
     * @throws ReflectionException
     *
     * @return ConditionExtended
     */
    final protected static function wrapSingleArgumentCallable(callable $where): callable
    {
        if ($where instanceof Query\Predicate) {
            return $where;
        }

        $reflection = new ReflectionFunction($where instanceof Closure ? $where : $where(...));

        return match ($reflection->getNumberOfRequiredParameters()) {
            0 => throw new InvalidArgument('The where condition must be a callable with 2 required parameters.'),
            1 => fn (mixed $record, int $key) => $where($record),
            default => $where,
        };
    }

    public function andWhere(string|int $column, Query\Constraint\Comparison|string $operator, mixed $value): self
    {
        return $this->addCondition('and', Query\Constraint\Column::filterOn($column, $operator, $value));
    }

    public function orWhere(string|int $column, Query\Constraint\Comparison|string $operator, mixed $value): self
    {
        return $this->addCondition('or', Query\Constraint\Column::filterOn($column, $operator, $value));
    }

    public function whereNot(string|int $column, Query\Constraint\Comparison|string $operator, mixed $value): self
    {
        return $this->addCondition('not', Query\Constraint\Column::filterOn($column, $operator, $value));
    }

    public function xorWhere(string|int $column, Query\Constraint\Comparison|string $operator, mixed $value): self
    {
        return $this->addCondition('xor', Query\Constraint\Column::filterOn($column, $operator, $value));
    }

    public function andWhereColumn(string|int $first, Query\Constraint\Comparison|string $operator, array|int|string $second): self
    {
        return $this->addCondition('and', Query\Constraint\TwoColumns::filterOn($first, $operator, $second));
    }

    public function orWhereColumn(string|int $first, Query\Constraint\Comparison|string $operator, array|int|string $second): self
    {
        return $this->addCondition('or', Query\Constraint\TwoColumns::filterOn($first, $operator, $second));
    }

    public function xorWhereColumn(string|int $first, Query\Constraint\Comparison|string $operator, array|int|string $second): self
    {
        return $this->addCondition('xor', Query\Constraint\TwoColumns::filterOn($first, $operator, $second));
    }

    public function whereNotColumn(string|int $first, Query\Constraint\Comparison|string $operator, array|int|string $second): self
    {
        return $this->addCondition('not', Query\Constraint\TwoColumns::filterOn($first, $operator, $second));
    }

    /**
     * @param 'and'|'not'|'or'|'xor' $joiner
     */
    final protected function addCondition(string $joiner, Query\Predicate $predicate): self
    {
        if ([] === $this->where) {
            return $this->where(match ($joiner) {
                'and' => $predicate,
                'not' => Query\Constraint\Criteria::none($predicate),
                'or' => Query\Constraint\Criteria::any($predicate),
                'xor' => Query\Constraint\Criteria::xany($predicate),
            });
        }

        $predicates = Query\Constraint\Criteria::all(...$this->where);

        $clone = clone $this;
        $clone->where = [match ($joiner) {
            'and' => $predicates->and($predicate),
            'not' => $predicates->not($predicate),
            'or' => $predicates->or($predicate),
            'xor' => $predicates->xor($predicate),
        }];

        return $clone;
    }

    /**
     * Sets an Iterator sorting callable function.
     *
     * @param OrderingExtended $order_by
     */
    public function orderBy(callable $order_by): self
    {
        $clone = clone $this;
        $clone->order_by[] = $order_by;

        return $clone;
    }

    /**
     * Ascending ordering of the tabular data according to a column value.
     *
     * The column value can be modified using the callback before ordering.
     */
    public function orderByAsc(string|int $column, ?Closure $callback = null): self
    {
        return $this->orderBy(Query\Ordering\Column::sortBy($column, 'asc', $callback));
    }

    /**
     * Descending ordering of the tabular data according to a column value.
     *
     * The column value can be modified using the callback before ordering.
     */
    public function orderByDesc(string|int $column, ?Closure $callback = null): self
    {
        return $this->orderBy(Query\Ordering\Column::sortBy($column, 'desc', $callback));
    }

    /**
     * Sets LimitIterator Offset.
     *
     * @throws Exception if the offset is less than 0
     */
    public function offset(int $offset): self
    {
        if (0 > $offset) {
            throw InvalidArgument::dueToInvalidRecordOffset($offset, __METHOD__);
        }

        if ($offset === $this->offset) {
            return $this;
        }

        $clone = clone $this;
        $clone->offset = $offset;

        return $clone;
    }

    /**
     * Sets LimitIterator Count.
     *
     * @throws Exception if the limit is less than -1
     */
    public function limit(int $limit): self
    {
        if (-1 > $limit) {
            throw InvalidArgument::dueToInvalidLimit($limit, __METHOD__);
        }

        if ($limit === $this->limit) {
            return $this;
        }

        $clone = clone $this;
        $clone->limit = $limit;

        return $clone;
    }

    /**
     * Executes the prepared Statement on the {@link Reader} object.
     *
     * @param array<string> $header an optional header to use instead of the CSV document header
     *
     * @throws InvalidArgument
     * @throws SyntaxError
     */
    public function process(TabularDataReader $tabular_data, array $header = []): TabularDataReader
    {
        if ([] === $header) {
            $header = $tabular_data->getHeader();
        }

        $iterator = $tabular_data->getRecords($header);
        $iterator = Query\Constraint\Criteria::all(...$this->where)->filter($iterator);
        $iterator = Query\Ordering\MultiSort::all(...$this->order_by)->uasort($iterator);
        $iterator = Query\Slice::value($iterator, $this->offset, $this->limit);

        return $this->applySelect($iterator, $header);
    }

    /**
     *
     * @throws InvalidArgument
     * @throws SyntaxError
     */
    protected function applySelect(Iterator $records, array $recordsHeader): TabularDataReader
    {
        if ([] === $this->select) {
            return new ResultSet($records, $recordsHeader);
        }

        $hasHeader = [] !== $recordsHeader;
        $selectColumn = function (array $header, string|int $field) use ($recordsHeader, $hasHeader): array {
            if (is_string($field)) {
                $index = array_search($field, $recordsHeader, true);
                if (false === $index) {
                    throw InvalidArgument::dueToInvalidColumnIndex($field, 'offset', __METHOD__);
                }

                $header[$index] = $field;

                return $header;
            }

            if ($hasHeader && !array_key_exists($field, $recordsHeader)) {
                throw InvalidArgument::dueToInvalidColumnIndex($field, 'offset', __METHOD__);
            }

            $header[$field] = $recordsHeader[$field] ?? $field;

            return $header;
        };

        /** @var array<string> $header */
        $header = array_reduce($this->select, $selectColumn, []);
        $records = new MapIterator($records, function (array $record) use ($header): array {
            $element = [];
            $row = array_values($record);
            foreach ($header as $offset => $headerName) {
                $element[$headerName] = $row[$offset] ?? null;
            }

            return $element;
        });

        return new ResultSet($records, $hasHeader ? $header : []);
    }

    /**
     * Filters elements of an Iterator using a callback function.
     *
     * DEPRECATION WARNING! This method will be removed in the next major point release.
     *
     * @see Statement::applyFilter()
     * @deprecated Since version 9.15.0
     * @codeCoverageIgnore
     */
    protected function filter(Iterator $iterator, callable $callable): CallbackFilterIterator
    {
        return new CallbackFilterIterator($iterator, $callable);
    }

    /**
     * Filters elements of an Iterator using a callback function.
     *
     * DEPRECATION WARNING! This method will be removed in the next major point release.
     *
     * @see Statement::process()
     * @deprecated Since version 9.16.0
     * @codeCoverageIgnore
     */
     protected function applyFilter(Iterator $iterator): Iterator
    {
        if ([] === $this->where) {
            return $iterator;
        }

        return new CallbackFilterIterator($iterator, Query\Constraint\Criteria::all(...$this->where));
    }

    /**
     * Sorts the Iterator.
     *
     * DEPRECATION WARNING! This method will be removed in the next major point release.
     *
     * @see Statement::process()
     * @deprecated Since version 9.16.0
     * @codeCoverageIgnore
     */
    protected function buildOrderBy(Iterator $iterator): Iterator
    {
        if ([] === $this->order_by) {
            return $iterator;
        }

        $class = new class () extends ArrayIterator {
            public function seek(int $offset): void
            {
                try {
                    parent::seek($offset);
                } catch (OutOfBoundsException) {
                    return;
                }
            }
        };

        /** @var ArrayIterator<array-key, array<string|null>> $it */
        $it = new $class([...$iterator]);
        $it->uasort(Query\Ordering\MultiSort::all(...$this->order_by));

        return $it;
    }
}
