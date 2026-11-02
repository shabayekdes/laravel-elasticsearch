<?php

namespace Shabayek\Elastic\Builders;

use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use Laravel\Scout\Builder;

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

        foreach ($this->equalityWheres($builder->wheres) as [$field, $value]) {
            $this->append('term', [$field => $value]);
        }

        if ($builder->whereIns) {
            foreach ($builder->whereIns as $key => $value) {
                $this->append('terms', [$key => $value]);
            }
        }
    }

    /**
     * Normalize the "where" constraints to [field, value] pairs.
     *
     * Scout 10 stores them as "field => value", Scout 11 as a list of
     * ['field' => ..., 'operator' => ..., 'value' => ...] rows.
     *
     * @param  array  $wheres
     * @return array
     *
     * @throws \InvalidArgumentException
     */
    private function equalityWheres(array $wheres): array
    {
        $pairs = [];

        foreach ($wheres as $key => $where) {
            if (! is_int($key) || ! is_array($where) || ! array_key_exists('field', $where) || ! array_key_exists('operator', $where)) {
                $pairs[] = [$key, $where];

                continue;
            }

            if ($where['operator'] !== '=') {
                throw new InvalidArgumentException(
                    "The [{$where['operator']}] operator is not supported in where clauses; only [=] is supported."
                );
            }

            $pairs[] = [$where['field'], $where['value'] ?? null];
        }

        return $pairs;
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
     * Append a query.
     *
     * @param $type
     * @param $params
     * @return void
     */
    private function append($type, $params)
    {
        $this->query['bool']['must'][][$type] = $params;
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
