<?php

namespace Shabayek\Elastic\Builders;

use Laravel\Scout\Builder;
use Illuminate\Database\Eloquent\Model;

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
     * @param Builder $builder
     */
    public function __construct(Builder $builder)
    {
        $this->builder = $builder;
        $this->buildRequest($builder, $builder->model);
    }

    /**
     * Build the request.
     *
     * @param Builder $builder
     * @param Model $model
     * @return void
     */
    private function buildRequest($builder, $model)
    {
        $this->index = $model->searchableAs();

        if($builder->query){
            $this->multiMatch($model->searchableFields(), $builder->query);
        }

        if($builder->wheres){
            foreach($builder->wheres as $key => $value){
                $this->append('term', [$key => $value]);
            }
        }

        if($builder->whereIns){
            foreach($builder->whereIns as $key => $value){
                $this->append('terms', [$key => $value]);
            }
        }
    }

    /**
     * @param null|int $from
     *
     * @return $this
     */
    public function setFrom($from)
    {
        $this->from = $from;

        return $this;
    }
    /**
     * @param null|int $size
     *
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
     * @param array  $fields
     * @param string $term
     *
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
     *
     * @return void
     */
    private function append($type, $params)
    {
        $this->query['bool']['must'][][$type] = $params;
    }

    /**
     * Convert object to array
     *
     * @return array
     */
    public function toArray(): array
    {
        $request = [
            'index' => $this->index,
            'body' => [
                'from' => $this->from,
                'size' => $this->size
            ]
        ];
        if($this->query){
            $request['body']['query'] = $this->query;
        }
        return $request;
    }
}
