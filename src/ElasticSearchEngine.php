<?php

namespace Shabayek\Elastic;

use Elastic\Elasticsearch\Client;
use Elastic\Elasticsearch\Exception\ClientResponseException;
use Elastic\Elasticsearch\Response\Elasticsearch;
use Illuminate\Container\Container;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Arr;
use Illuminate\Support\LazyCollection;
use InvalidArgumentException;
use LogicException;
use Laravel\Scout\Builder;
use Laravel\Scout\Engines\Engine;
use Shabayek\Elastic\Builders\SearchBuilder;
use Shabayek\Elastic\Exceptions\BulkOperationException;
use Shabayek\Elastic\Exceptions\IncompleteSearchResultsException;
use Throwable;

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
     * The maximum number of documents sent in one bulk request.
     *
     * @var int
     */
    protected $chunkSize;

    /**
     * The refresh mode of bulk requests: false, true or "wait_for".
     *
     * @var bool|string
     */
    protected $refresh;

    /**
     * The number of hits fetched per page by cursor().
     *
     * @var int
     */
    protected $cursorBatchSize;

    /**
     * How long Elasticsearch keeps a cursor's point in time between pages.
     *
     * @var string
     */
    protected $cursorKeepAlive;

    /**
     * Create a new engine instance.
     *
     * @param  \Elastic\Elasticsearch\Client  $client
     * @param  bool  $softDelete
     * @param  int  $chunkSize
     * @param  bool|string  $refresh
     * @param  int  $cursorBatchSize
     * @param  string  $cursorKeepAlive
     *
     * @throws \InvalidArgumentException
     */
    public function __construct(
        Client $client,
        bool $softDelete = false,
        int $chunkSize = 500,
        bool|string $refresh = false,
        int $cursorBatchSize = 1000,
        string $cursorKeepAlive = '1m'
    ) {
        if ($chunkSize < 1) {
            throw new InvalidArgumentException('The Elasticsearch bulk chunk size must be at least 1.');
        }

        if (! is_bool($refresh) && $refresh !== 'wait_for') {
            throw new InvalidArgumentException('The Elasticsearch refresh option must be true, false or "wait_for".');
        }

        if ($cursorBatchSize < 1) {
            throw new InvalidArgumentException('The Elasticsearch cursor batch size must be at least 1.');
        }

        if ($cursorKeepAlive === '') {
            throw new InvalidArgumentException('The Elasticsearch cursor keep alive must not be empty.');
        }

        $this->client = $client;
        $this->softDelete = $softDelete;
        $this->chunkSize = $chunkSize;
        $this->refresh = $refresh;
        $this->cursorBatchSize = $cursorBatchSize;
        $this->cursorKeepAlive = $cursorKeepAlive;
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

        $this->bulk((function () use ($models) {
            foreach ($models as $model) {
                if (empty($searchableData = $model->toSearchableArray())) {
                    continue;
                }

                yield [
                    'action' => ['index' => ['_index' => $model->indexableAs(), '_id' => (string) $model->getScoutKey()]],
                    'source' => array_merge($searchableData, $model->scoutMetadata()),
                ];
            }
        })());
    }

    /**
     * Remove the given model from the index.
     *
     * @param  \Illuminate\Database\Eloquent\Collection  $models
     * @return void
     */
    public function delete($models)
    {
        if ($models->isEmpty()) {
            return;
        }

        $this->bulk((function () use ($models) {
            foreach ($models as $model) {
                yield [
                    'action' => ['delete' => ['_index' => $model->indexableAs(), '_id' => (string) $model->getScoutKey()]],
                ];
            }
        })());
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

        return $this->performSearch($builder, $searchBuilder->toArray());
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

        return $this->performSearch($builder, $searchBuilder->toArray());
    }

    /**
     * Run the search request, or hand it to the Builder's search callback.
     *
     * The callback receives the Elasticsearch client, the query string and the
     * request parameters, and returns the raw results (an array or a client
     * response).
     *
     * @param  \Laravel\Scout\Builder  $builder
     * @param  array  $params
     * @return mixed
     */
    protected function performSearch(Builder $builder, array $params)
    {
        if ($builder->callback) {
            $results = call_user_func($builder->callback, $this->client, $builder->query, $params);

            return $results instanceof Elasticsearch ? $results->asArray() : $results;
        }

        return $this->client->search($params)->asArray();
    }

    /**
     * Run one page of a cursor search, making sure a search callback did not
     * change the parameters that drive the cursor.
     *
     * A cursor search callback may change the query and other request options,
     * but must send its search through the given client, as the last request
     * of the callback, to "_search" without an index and with the pit,
     * search_after, sort, size and from body keys unchanged (none of them in
     * the query string). Otherwise the page is discarded and a LogicException
     * is thrown, before any of its hits are used.
     *
     * @param  \Laravel\Scout\Builder  $builder
     * @param  array  $params
     * @return mixed
     *
     * @throws \LogicException
     */
    protected function performCursorSearch(Builder $builder, array $params)
    {
        if (! $builder->callback) {
            return $this->performSearch($builder, $params);
        }

        $transport = $this->client->getTransport();
        $previous = $transport->getLastRequest();

        $results = $this->performSearch($builder, $params);

        $request = $transport->getLastRequest();

        if ($request === null || $request === $previous) {
            throw new LogicException('A cursor search callback must send its search through the given client.');
        }

        parse_str($request->getUri()->getQuery(), $query);
        $sent = json_decode((string) $request->getBody(), true) ?: [];
        $expected = json_decode(json_encode($params['body']), true);
        $changed = [];

        foreach (SearchBuilder::CURSOR_MANAGED_PARAMETERS as $key) {
            if (array_key_exists($key, $query) || ($sent[$key] ?? null) !== ($expected[$key] ?? null)) {
                $changed[] = $key;
            }
        }

        if ($request->getMethod() !== 'POST' || $request->getUri()->getPath() !== '/_search') {
            $changed[] = 'index';
        }

        if ($changed) {
            throw new LogicException(sprintf(
                'The search callback changed the cursor-managed [%s] parameter(s). A cursor search callback may change '.
                'the query and other options, but must send pit, search_after, sort, size and from unchanged.',
                implode(', ', $changed)
            ));
        }

        return $results;
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
     * Scout passes the model's Scout key name. Documents are stored with their
     * Scout key as the document id, so the id is returned rather than a source
     * field, which toSearchableArray() may have changed or left out.
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
        $keys = $this->mapIdsFrom($this->search($builder), $builder->model->getScoutKeyName());

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
     * Get a lazy collection for the given query mapped onto models.
     *
     * Hits are read page by page from a point in time (PIT) with search_after,
     * so large result sets (beyond index.max_result_window) are streamed in
     * relevance order (or the orderBy() order) without loading them all. Each
     * page is mapped to models separately. The PIT is closed when iteration
     * ends, fails or is stopped early.
     *
     * @param  \Laravel\Scout\Builder  $builder
     * @return \Illuminate\Support\LazyCollection
     */
    public function cursor(Builder $builder)
    {
        $searchBuilder = new SearchBuilder($builder);

        return LazyCollection::make(function () use ($builder, $searchBuilder) {
            $remaining = $builder->limit === null ? null : (int) $builder->limit;

            if ($remaining !== null && $remaining <= 0) {
                return;
            }

            $pointInTimeId = $this->client->openPointInTime([
                'index' => $searchBuilder->index,
                'keep_alive' => $this->cursorKeepAlive,
            ])->asArray()['id'];

            try {
                $searchAfter = null;

                do {
                    $size = $remaining === null ? $this->cursorBatchSize : min($this->cursorBatchSize, $remaining);

                    $results = $this->performCursorSearch(
                        $builder,
                        $searchBuilder->toPointInTimeArray($pointInTimeId, $this->cursorKeepAlive, $size, $searchAfter)
                    );

                    $pointInTimeId = $results['pit_id'] ?? $pointInTimeId;

                    if (! empty($results['timed_out']) || ! empty($results['terminated_early']) || ($results['_shards']['failed'] ?? 0) > 0) {
                        throw IncompleteSearchResultsException::fromResults($results);
                    }

                    $hits = $this->hits($results);

                    foreach ($this->lazyMap($builder, $builder->applyAfterRawSearchCallback($results), $builder->model) as $model) {
                        yield $model;
                    }

                    if ($remaining !== null) {
                        $remaining -= count($hits);
                    }

                    $searchAfter = $hits === [] ? null : (end($hits)['sort'] ?? null);

                    if ($hits !== [] && $searchAfter === null && count($hits) === $size) {
                        throw new IncompleteSearchResultsException('Elasticsearch returned hits without sort values; the cursor cannot continue.');
                    }
                } while (count($hits) === $size && ($remaining === null || $remaining > 0));
            } finally {
                $this->closePointInTime($pointInTimeId);
            }
        });
    }

    /**
     * Close a point in time, reporting (not throwing) failures: an unclosed
     * point in time expires after its keep alive.
     *
     * @param  string  $id
     * @return void
     */
    protected function closePointInTime(string $id): void
    {
        try {
            $this->client->closePointInTime(['body' => ['id' => $id]]);
        } catch (Throwable $e) {
            $container = Container::getInstance();

            if ($container->bound(ExceptionHandler::class)) {
                $container->make(ExceptionHandler::class)->report($e);
            }
        }
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
     * Supported options are "settings", "mappings" and "aliases". Without
     * "settings", the package's default analysis settings are used. When the
     * index already exists it is left untouched.
     *
     * @param  string  $name
     * @param  array  $options
     * @return array
     */
    public function createIndex($name, array $options = [])
    {
        if ($this->client->indices()->exists(['index' => $name])->asBool()) {
            return $this->client->indices()->get(['index' => $name])->asArray();
        }

        $body = Arr::only($options, ['settings', 'mappings', 'aliases']);
        $body['settings'] ??= $this->defaultIndexSettings();

        try {
            return $this->client->indices()->create(['index' => $name, 'body' => $body])->asArray();
        } catch (ClientResponseException $e) {
            if ($this->errorType($e) !== 'resource_already_exists_exception') {
                throw $e;
            }

            return $this->client->indices()->get(['index' => $name])->asArray();
        }
    }

    /**
     * Delete a search index.
     *
     * @param  string  $name
     * @return array
     */
    public function deleteIndex($name)
    {
        return $this->client->indices()->delete(['index' => $name])->asArray();
    }

    /**
     * Send the operations in bulk requests of at most "chunk size" operations.
     *
     * Every chunk is sent; the failures of all chunks are then reported together.
     *
     * @param  iterable<int, array{action: array, source?: array}>  $operations
     * @return void
     *
     * @throws \Shabayek\Elastic\Exceptions\BulkOperationException
     */
    protected function bulk(iterable $operations): void
    {
        $failures = [];
        $total = 0;
        $chunk = [];

        foreach ($operations as $operation) {
            $chunk[] = $operation;

            if (count($chunk) === $this->chunkSize) {
                array_push($failures, ...$this->sendBulkChunk($chunk));
                $total += count($chunk);
                $chunk = [];
            }
        }

        if ($chunk !== []) {
            array_push($failures, ...$this->sendBulkChunk($chunk));
            $total += count($chunk);
        }

        if ($failures !== []) {
            throw new BulkOperationException($failures, $total);
        }
    }

    /**
     * Send one bulk request and return its failed operations.
     *
     * A bulk request answers HTTP 200 even when operations fail, so the result
     * of every operation is checked.
     *
     * @param  array<int, array{action: array, source?: array}>  $operations
     * @return array<int, array>
     */
    protected function sendBulkChunk(array $operations): array
    {
        $body = [];

        foreach ($operations as $operation) {
            $body[] = $operation['action'];

            if (isset($operation['source'])) {
                $body[] = $operation['source'];
            }
        }

        $params = ['body' => $body];

        if ($this->refresh !== false) {
            $params['refresh'] = $this->refresh === true ? 'true' : $this->refresh;
        }

        $items = $this->client->bulk($params)->asArray()['items'] ?? [];
        $failures = [];

        foreach ($operations as $position => $operation) {
            $type = array_key_first($operation['action']);
            $result = $items[$position][$type] ?? null;

            if ($this->bulkOperationSucceeded($type, $result)) {
                continue;
            }

            $error = $result['error'] ?? null;

            [$errorType, $reason] = match (true) {
                ! is_array($result) => ['missing_result', 'The bulk response has no result for this operation.'],
                is_array($error) => [$error['type'] ?? null, $error['reason'] ?? null],
                is_string($error) => [null, $error],
                default => [null, $result['result'] ?? null],
            };

            $failures[] = [
                'operation' => $type,
                'index' => $operation['action'][$type]['_index'],
                'id' => $operation['action'][$type]['_id'],
                'status' => isset($result['status']) ? (int) $result['status'] : null,
                'type' => $errorType,
                'reason' => $reason,
            ];
        }

        return $failures;
    }

    /**
     * Determine if a bulk operation result is a success.
     *
     * Deleting a document that is already missing is a success; a missing
     * index or any other error is not.
     *
     * @param  string  $type
     * @param  mixed  $result
     * @return bool
     */
    protected function bulkOperationSucceeded(string $type, $result): bool
    {
        if (! is_array($result) || isset($result['error'])) {
            return false;
        }

        $status = (int) ($result['status'] ?? 0);

        if ($type === 'delete' && $status === 404) {
            return ($result['result'] ?? null) === 'not_found';
        }

        return $status >= 200 && $status < 300;
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
    }

    /**
     * The analysis settings used when an index is created without settings.
     *
     * @return array
     */
    protected function defaultIndexSettings(): array
    {
        return [
            'index' => [
                'analysis' => [
                    'analyzer' => [
                        'default' => [
                            'type' => 'custom',
                            'tokenizer' => 'standard',
                            'char_filter' => ['html_strip'],
                            'filter' => ['lowercase', 'words_splitter'],
                        ],
                    ],
                    'filter' => [
                        'words_splitter' => [
                            'type' => 'word_delimiter',
                            'catenate_all' => true,
                            'preserve_original' => false,
                        ],
                    ],
                ],
            ],
        ];
    }
}
