<?php

namespace Shabayek\Elastic\Builders;

use BackedEnum;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use Laravel\Scout\Builder;
use stdClass;
use UnitEnum;

class SearchBuilder
{
    /**
     * Scout Builder.
     *
     * @var Builder
     */
    public $builder;

    /**
     * DSL query.
     *
     * @var array
     */
    public $query;

    /**
     * The elastic index to query against.
     *
     * @var string
     */
    public $index;

    /**
     * To retrieve hits from a certain offset. Defaults to 0.
     *
     * @var int
     */
    private $from = 0;
    /**
     * The number of hits to return. Defaults to 5000. If you do not care about getting some
     * setting the value to 0 will help performance.
     *
     * @var int
     */
    private $size = 5000;

    /**
     * The supported "where" operators.
     */
    private const OPERATORS = ['=', '!=', '<>', '>', '>=', '<', '<='];

    /**
     * The range query parameter of each comparison operator.
     */
    private const RANGE_OPERATORS = ['>' => 'gt', '>=' => 'gte', '<' => 'lt', '<=' => 'lte'];

    /**
     * Scoring (full-text) clauses.
     *
     * @var array
     */
    private $must = [];

    /**
     * Non-scoring filter clauses.
     *
     * @var array
     */
    private $filter = [];

    /**
     * Excluding clauses.
     *
     * @var array
     */
    private $mustNot = [];

    /**
     * Builder constructor.
     *
     * @param  Builder  $builder
     */
    public function __construct(Builder $builder)
    {
        $this->builder = $builder;
        $this->buildRequest($builder, $builder->model);
    }

    /**
     * Build the request.
     *
     * @param  Builder  $builder
     * @param  Model  $model
     * @return void
     */
    private function buildRequest($builder, $model)
    {
        $this->index = $model->searchableAs();

        if ($builder->query) {
            $this->multiMatch($model->searchableFields(), $builder->query);
        }

        foreach ($this->wheres($builder->wheres) as [$field, $operator, $value]) {
            $this->where($field, $operator, $value);
        }

        foreach ($builder->whereIns as $field => $values) {
            $this->whereIn($field, $values);
        }

        foreach ($builder->whereNotIns as $field => $values) {
            $this->whereNotIn($field, $values);
        }
    }

    /**
     * Normalize the "where" constraints to [field, operator, value] rows.
     *
     * Scout 10 stores them as "field => value" (equality), Scout 11 as a list
     * of ['field' => ..., 'operator' => ..., 'value' => ...] rows.
     *
     * @param  array  $wheres
     * @return array
     */
    private function wheres(array $wheres): array
    {
        $rows = [];

        foreach ($wheres as $key => $where) {
            if (is_int($key) && is_array($where) && array_key_exists('field', $where) && array_key_exists('operator', $where)) {
                $rows[] = [$where['field'], $where['operator'], $where['value'] ?? null];
            } else {
                $rows[] = [$key, '=', $where];
            }
        }

        return $rows;
    }

    /**
     * Add a "where" constraint as a non-scoring filter.
     *
     * A null value matches documents where the field is missing or null.
     *
     * @param  string  $field
     * @param  string  $operator
     * @param  mixed  $value
     * @return void
     *
     * @throws \InvalidArgumentException
     */
    private function where($field, $operator, $value)
    {
        $value = $this->normalizeValue($value);

        switch ($operator) {
            case '=':
                $value === null
                    ? $this->mustNot(['exists' => ['field' => $field]])
                    : $this->filter(['term' => [$field => $value]]);

                return;
            case '!=':
            case '<>':
                $value === null
                    ? $this->filter(['exists' => ['field' => $field]])
                    : $this->mustNot(['term' => [$field => $value]]);

                return;
            case '>':
            case '>=':
            case '<':
            case '<=':
                if ($value === null) {
                    throw new InvalidArgumentException("The [{$operator}] operator cannot be used with a null value for [{$field}].");
                }

                $this->filter(['range' => [$field => [self::RANGE_OPERATORS[$operator] => $value]]]);

                return;
        }

        throw new InvalidArgumentException(sprintf(
            'The [%s] operator is not supported for [%s]. Supported operators: %s.',
            $operator,
            $field,
            implode(', ', self::OPERATORS)
        ));
    }

    /**
     * Add a "where in" constraint as a non-scoring filter.
     *
     * @param  string  $field
     * @param  array  $values
     * @return void
     */
    private function whereIn($field, array $values)
    {
        $values = $this->normalizeValues($field, $values);

        $values === []
            ? $this->filter(['match_none' => new stdClass()])
            : $this->filter(['terms' => [$field => $values]]);
    }

    /**
     * Add a "where not in" constraint.
     *
     * @param  string  $field
     * @param  array  $values
     * @return void
     */
    private function whereNotIn($field, array $values)
    {
        $values = $this->normalizeValues($field, $values);

        if ($values !== []) {
            $this->mustNot(['terms' => [$field => $values]]);
        }
    }

    /**
     * Normalize a list of values for a terms query.
     *
     * @param  string  $field
     * @param  array  $values
     * @return array
     *
     * @throws \InvalidArgumentException
     */
    private function normalizeValues($field, array $values): array
    {
        $values = array_map(fn ($value) => $this->normalizeValue($value), array_values($values));

        if (in_array(null, $values, true)) {
            throw new InvalidArgumentException(
                "The values for [{$field}] contain null; use where('{$field}', null) to match missing values."
            );
        }

        return $values;
    }

    /**
     * Convert enums and dates to values Elasticsearch understands.
     *
     * @param  mixed  $value
     * @return mixed
     */
    private function normalizeValue($value)
    {
        return match (true) {
            $value instanceof BackedEnum => $value->value,
            $value instanceof UnitEnum => $value->name,
            $value instanceof DateTimeInterface => $value->format(DateTimeInterface::ATOM),
            default => $value,
        };
    }

    /**
     * @param  null|int  $from
     * @return $this
     */
    public function setFrom($from)
    {
        $this->from = $from;

        return $this;
    }

    /**
     * @param  null|int  $size
     * @return $this
     */
    public function setSize($size)
    {
        $this->size = $size;

        return $this;
    }

    /**
     * Add a multi match query.
     *
     * @param  array  $fields
     * @param  string  $term
     * @return void
     */
    private function multiMatch(array $fields, $term)
    {
        $params = [
            'query' => $term ?? '',
            'fields' => $fields,
            'type' => 'phrase_prefix',
        ];

        $this->append('multi_match', $params);
    }

    /**
     * Add a scoring query clause.
     *
     * @param  string  $type
     * @param  array  $params
     * @return void
     */
    private function append($type, $params)
    {
        $this->must[] = [$type => $params];
        $this->compileQuery();
    }

    /**
     * Add a non-scoring filter clause.
     *
     * @param  array  $clause
     * @return void
     */
    private function filter(array $clause)
    {
        $this->filter[] = $clause;
        $this->compileQuery();
    }

    /**
     * Add an excluding clause.
     *
     * @param  array  $clause
     * @return void
     */
    private function mustNot(array $clause)
    {
        $this->mustNot[] = $clause;
        $this->compileQuery();
    }

    /**
     * Build the bool query from the collected clauses.
     *
     * @return void
     */
    private function compileQuery()
    {
        $this->query = ['bool' => array_filter([
            'must' => $this->must,
            'filter' => $this->filter,
            'must_not' => $this->mustNot,
        ])];
    }

    /**
     * Convert object to array.
     *
     * @return array
     */
    public function toArray(): array
    {
        $request = [
            'index' => $this->index,
            'body' => [
                'from' => $this->from,
                'size' => $this->size,
                'track_total_hits' => true,
            ],
        ];
        if ($this->query) {
            $request['body']['query'] = $this->query;
        }

        return $request;
    }
}
