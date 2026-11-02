<?php

namespace Shabayek\Elastic\Builders;

use BackedEnum;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use Laravel\Scout\Builder;
use Laravel\Scout\Exceptions\NotSupportedException;
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
     * Request body keys that cannot be set through Builder::options().
     */
    private const RESERVED_OPTIONS = ['from', 'size', 'query'];

    /**
     * The range query parameter of each comparison operator.
     */
    private const RANGE_OPERATORS = ['>' => 'gt', '>=' => 'gte', '<' => 'lt', '<=' => 'lte'];

    /**
     * The sort clauses.
     *
     * @var array
     */
    private $sort = [];

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
        $this->guardAgainstUnsupportedFeatures($builder);
        $this->buildRequest($builder, $builder->model);
    }

    /**
     * Reject Builder features this engine cannot honour.
     *
     * @param  Builder  $builder
     * @return void
     *
     * @throws \Laravel\Scout\Exceptions\NotSupportedException
     * @throws \InvalidArgumentException
     */
    private function guardAgainstUnsupportedFeatures($builder)
    {
        // Scout 11 only: Builder::engine() already rejects semantic(), but not hybrid().
        if (! empty($builder->semanticSearch) || ($builder->hybridSearch ?? null) !== null) {
            throw new NotSupportedException('The Elasticsearch engine does not support semantic or hybrid searches.');
        }

        if ($reserved = array_intersect(array_keys($builder->options), self::RESERVED_OPTIONS)) {
            throw new InvalidArgumentException(sprintf(
                'The [%s] search option is managed by the engine; use take() or paginate() for from/size, '.
                'and a search callback to replace the query.',
                implode(', ', $reserved)
            ));
        }

        if (array_key_exists('sort', $builder->options) && $builder->orders) {
            throw new InvalidArgumentException('Use either orderBy() or the [sort] search option, not both.');
        }
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
        $this->index = $builder->index ?: $model->searchableAs();

        if ($builder->limit !== null) {
            $this->size = (int) $builder->limit;
        }

        foreach ($builder->orders as $order) {
            $this->sort[] = [$order['column'] => ['order' => $order['direction']]];
        }

        if ($builder->query !== null && $builder->query !== '') {
            $this->multiMatch($this->searchableFields($model), (string) $builder->query);
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
     * Get the fields to search from the model's optional searchableFields() method.
     *
     * @param  Model  $model
     * @return array
     */
    private function searchableFields($model): array
    {
        return method_exists($model, 'searchableFields')
            ? array_values((array) $model->searchableFields())
            : [];
    }

    /**
     * Add a multi match query.
     *
     * Without fields, Elasticsearch searches the index's "index.query.default_field"
     * (all eligible fields by default); "lenient" then ignores fields whose type
     * cannot parse the query text, such as numbers and dates.
     *
     * @param  array  $fields
     * @param  string  $term
     * @return void
     */
    private function multiMatch(array $fields, $term)
    {
        $params = $fields === []
            ? ['query' => $term, 'type' => 'phrase_prefix', 'lenient' => true]
            : ['query' => $term, 'fields' => $fields, 'type' => 'phrase_prefix'];

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
     * Build a request for one page of a point in time (PIT) search.
     *
     * Hits are sorted by the requested order (relevance by default) with the
     * "_shard_doc" tiebreaker, giving a stable total order for search_after.
     * The request has no index: the PIT determines it.
     *
     * @param  string  $pointInTimeId
     * @param  string  $keepAlive
     * @param  int  $size
     * @param  array|null  $searchAfter
     * @return array
     *
     * @throws \InvalidArgumentException
     */
    public function toPointInTimeArray(string $pointInTimeId, string $keepAlive, int $size, ?array $searchAfter = null): array
    {
        $options = $this->builder->options;

        if ($managed = array_intersect(array_keys($options), ['pit', 'search_after'])) {
            throw new InvalidArgumentException(sprintf(
                'The [%s] search option is managed by the engine when iterating a cursor.',
                implode(', ', $managed)
            ));
        }

        $sort = $options['sort'] ?? ($this->sort ?: [['_score' => ['order' => 'desc']]]);
        $sort = is_array($sort) && array_is_list($sort) ? $sort : [$sort];
        $sort[] = ['_shard_doc' => ['order' => 'asc']];

        $body = array_merge($options, [
            'size' => $size,
            'track_total_hits' => false,
            'sort' => $sort,
            'pit' => ['id' => $pointInTimeId, 'keep_alive' => $keepAlive],
        ]);

        if ($this->query) {
            $body['query'] = $this->query;
        }

        if ($searchAfter !== null) {
            $body['search_after'] = $searchAfter;
        }

        return ['body' => $body];
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

        if ($this->sort) {
            $request['body']['sort'] = $this->sort;
        }

        $request['body'] = array_merge($request['body'], $this->builder->options);

        return $request;
    }
}
