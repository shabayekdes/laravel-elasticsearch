<?php

namespace Shabayek\Elastic;

use Elastic\Elasticsearch\Client;
use Elastic\Elasticsearch\Exception\ClientResponseException;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\LazyCollection;
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
        return collect($this->hits($results))->pluck('_id')->values();
    }

    /**
     * Pluck and return the primary keys of the given results using the given key name.
     *
     * The value is read from the document source, falling back to the document id.
     *
     * @param  mixed  $results
     * @param  string  $key
     * @return \Illuminate\Support\Collection
     */
    public function mapIdsFrom($results, $key)
    {
        return collect($this->hits($results))
            ->map(fn ($hit) => $hit['_source'][$key] ?? $hit['_id'])
            ->values();
    }

    /**
     * Get the results of the query as a Collection of primary keys.
     *
     * @param  \Laravel\Scout\Builder  $builder
     * @return \Illuminate\Support\Collection
     */
    public function keys(Builder $builder)
    {
        $keys = $this->mapIds($this->search($builder));

        return in_array($builder->model->getScoutKeyType(), ['int', 'integer'])
            ? $keys->map(fn ($key) => (int) $key)
            : $keys;
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
        $hits = $this->hits($results);

        if (count($hits) === 0) {
            return $model->newCollection();
        }

        $ids = array_column($hits, '_id');
        $positions = array_flip($ids);

        return $model->getScoutModelsByIds($builder, $ids)
            ->filter(fn ($model) => isset($positions[$model->getScoutKey()]))
            ->map(fn ($model) => $this->withHitMetadata($model, $hits[$positions[$model->getScoutKey()]]))
            ->sortBy(fn ($model) => $positions[$model->getScoutKey()])
            ->values();
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
        $hits = $this->hits($results);

        if (count($hits) === 0) {
            return LazyCollection::make($model->newCollection());
        }

        $ids = array_column($hits, '_id');
        $positions = array_flip($ids);

        return $model->queryScoutModelsByIds($builder, $ids)
            ->cursor()
            ->filter(fn ($model) => isset($positions[$model->getScoutKey()]))
            ->map(fn ($model) => $this->withHitMetadata($model, $hits[$positions[$model->getScoutKey()]]))
            ->sortBy(fn ($model) => $positions[$model->getScoutKey()])
            ->values();
    }

    /**
     * Get the total count from a raw result returned by the engine.
     *
     * @param  mixed  $results
     * @return int
     */
    public function getTotalCount($results)
    {
        $total = $results['hits']['total'] ?? 0;

        return (int) (is_array($total) ? ($total['value'] ?? 0) : $total);
    }

    /**
     * Flush all of the model's records from the engine.
     *
     * The index itself, with its settings and mappings, is kept.
     *
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @return void
     */
    public function flush($model)
    {
        try {
            $this->client->deleteByQuery([
                'index' => $model->indexableAs(),
                'conflicts' => 'proceed',
                'refresh' => true,
                'body' => [
                    'query' => ['match_all' => new \stdClass()],
                ],
            ]);
        } catch (ClientResponseException $e) {
            if ($this->errorType($e) !== 'index_not_found_exception') {
                throw $e;
            }
        }
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
     * Get the hits of a raw search result.
     *
     * @param  mixed  $results
     * @return array
     */
    protected function hits($results): array
    {
        $hits = $results['hits']['hits'] ?? [];

        return is_array($hits) ? array_values($hits) : [];
    }

    /**
     * Attach the hit metadata (such as "_score") to the model.
     *
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @param  array  $hit
     * @return \Illuminate\Database\Eloquent\Model
     */
    protected function withHitMetadata($model, array $hit)
    {
        foreach ($hit as $key => $value) {
            if (str_starts_with($key, '_') && $key !== '_source') {
                $model->withScoutMetadata($key, $value);
            }
        }

        return $model;
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
     * Get the Elasticsearch error type of a failed request.
     *
     * @param  \Elastic\Elasticsearch\Exception\ClientResponseException  $e
     * @return string|null
     */
    protected function errorType(ClientResponseException $e): ?string
    {
        $body = json_decode((string) $e->getResponse()->getBody(), true);

        return $body['error']['type'] ?? null;
    }}
