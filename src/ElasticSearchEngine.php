<?php

namespace Shabayek\Elastic;

use Laravel\Scout\Builder;
use Laravel\Scout\Engines\Engine;
use Elastic\Elasticsearch\Client;

class ElasticSearchEngine extends Engine
{
    protected $client;

    public function __construct(Client $client)
    {
        $this->client = $client;
    }
    /**
     * Update the given model in the index.
     *
     * @param  \Illuminate\Database\Eloquent\Collection  $models
     * @return void
     */
    public function update($models)
    {
        if ($models->isEmpty()) {
            return;
        }

        $models->each(function ($model) {
            $params = [
                'index' => $model->searchableAs(),
                'id' => $model->id,
                'body' => $model->toSearchableArray(),
            ];

            $this->client->index($params);
        });
    }

    /**
     * Remove the given model from the index.
     *
     * @param  \Illuminate\Database\Eloquent\Collection  $models
     * @return void
     */
    public function delete($models)
    {
        //
    }

    /**
     * Perform the given search on the engine.
     *
     * @param  \Laravel\Scout\Builder  $builder
     * @return mixed
     */
    public function search(Builder $builder)
    {
        //
    }
    /**
     * Perform the given search on the engine.
     *
     * @param  \Laravel\Scout\Builder  $builder
     * @param  int  $perPage
     * @param  int  $page
     * @return mixed
     */
    public function paginate(Builder $builder, $perPage, $page)
    {
        //
    }

    /**
     * Pluck and return the primary keys of the given results.
     *
     * @param  mixed  $results
     * @return \Illuminate\Support\Collection
     */
    public function mapIds($results)
    {
        //
    }

    /**
     * Map the given results to instances of the given model.
     *
     * @param  \Laravel\Scout\Builder  $builder
     * @param  mixed  $results
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function map(Builder $builder, $results, $model)
    {
        //
    }

    /**
     * Map the given results to instances of the given model via a lazy collection.
     *
     * @param  \Laravel\Scout\Builder  $builder
     * @param  mixed  $results
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @return \Illuminate\Support\LazyCollection
     */
    public function lazyMap(Builder $builder, $results, $model)
    {
    }

    /**
     * Get the total count from a raw result returned by the engine.
     *
     * @param  mixed  $results
     * @return int
     */
    public function getTotalCount($results)
    {
        return data_get($results, 'hits.total.value', 0);
    }

    /**
     * Flush all of the model's records from the engine.
     *
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @return void
     */
    public function flush($model)
    {
        //
    }

    /**
     * Create a search index.
     *
     * @param  string  $name
     * @param  array  $options
     * @return mixed
     */
    public function createIndex($name, array $options = [])
    {
    }

    /**
     * Delete a search index.
     *
     * @param  string  $name
     * @return mixed
     */
    public function deleteIndex($name)
    {
    }

    /**
     * Pluck and return the primary keys of the given results using the given key name.
     *
     * @param  mixed  $results
     * @param  string  $key
     * @return \Illuminate\Support\Collection
     */
    public function mapIdsFrom($results, $key)
    {
        return $this->mapIds($results);
    }

    /**
     * Get the results of the query as a Collection of primary keys.
     *
     * @param  \Laravel\Scout\Builder  $builder
     * @return \Illuminate\Support\Collection
     */
    public function keys(Builder $builder)
    {
        return $this->mapIds($this->search($builder));
    }

    /**
     * Get the results of the given query mapped onto models.
     *
     * @param  \Laravel\Scout\Builder  $builder
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function get(Builder $builder)
    {
        return $this->map(
            $builder,
            $this->search($builder),
            $builder->model
        );
    }

    /**
     * Get a lazy collection for the given query mapped onto models.
     *
     * @param  \Laravel\Scout\Builder  $builder
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function cursor(Builder $builder)
    {
        return $this->lazyMap(
            $builder,
            $this->search($builder),
            $builder->model
        );
    }
}
