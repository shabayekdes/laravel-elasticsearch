<?php

namespace Shabayek\Elastic;

use Elastic\Elasticsearch\Client;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Artisan;
use Laravel\Scout\Builder;
use Laravel\Scout\Engines\Engine;
use Shabayek\Elastic\Builders\SearchBuilder;

class ElasticSearchEngine extends Engine
{
    /**
     * The Elasticsearch client.
     *
     * @var \Elastic\Elasticsearch\Client
     */
    protected $client;

    /**
     * Determines if soft deleted models are kept in the index.
     *
     * @var bool
     */
    protected $softDelete;

    /**
     * Create a new engine instance.
     *
     * @param  \Elastic\Elasticsearch\Client  $client
     * @param  bool  $softDelete
     */
    public function __construct(Client $client, bool $softDelete = false)
    {
        $this->client = $client;
        $this->softDelete = $softDelete;
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

        if ($this->softDelete && $this->usesSoftDelete($models->first())) {
            $models->each->pushSoftDeleteMetadata();
        }

        $models->each(function ($model) {
            if (empty($searchableData = $model->toSearchableArray())) {
                return;
            }

            $this->client->index([
                'index' => $model->indexableAs(),
                'id' => $model->getScoutKey(),
                'body' => array_merge($searchableData, $model->scoutMetadata()),
            ]);
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
        $models->each(function ($model) {
            $this->client->delete([
                'index' => $model->indexableAs(),
                'id' => $model->getScoutKey(),
            ]);
        });
    }

    /**
     * Perform the given search on the engine.
     *
     * @param  \Laravel\Scout\Builder  $builder
     * @return mixed
     */
    public function search(Builder $builder)
    {
        $searchBuilder = new SearchBuilder($builder);

        return $this->client->search($searchBuilder->toArray())->asArray();
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
        $searchBuilder = new SearchBuilder($builder);

        $searchBuilder->setFrom(($page - 1) * $perPage);
        $searchBuilder->setSize($perPage);

        return $this->client->search($searchBuilder->toArray())->asArray();
    }

    /**
     * Pluck and return the primary keys of the given results.
     *
     * @param  mixed  $results
     * @return \Illuminate\Support\Collection
     */
    public function mapIds($results)
    {
        return collect(data_get($results, 'hits.hits'))->pluck('_id')->values();
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
     * Map the given results to instances of the given model.
     *
     * @param  \Laravel\Scout\Builder  $builder
     * @param  mixed  $results
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function map(Builder $builder, $results, $model)
    {
        $hits = data_get($results, 'hits.hits');

        if (count($hits) === 0) {
            return $model->newCollection();
        }

        return $model->getScoutModelsByIds(
            $builder,
            collect($hits)->pluck('_id')->values()->all()
        );
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
        $this->client->indices()->delete([
            'index' => $model->searchableAs(),
        ]);

        Artisan::call('scout:elasticsearch:create', ['model' => get_class($model)]);
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
     * Determine if the given model uses soft deletes.
     *
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @return bool
     */
    protected function usesSoftDelete($model): bool
    {
        return in_array(SoftDeletes::class, class_uses_recursive($model));
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
