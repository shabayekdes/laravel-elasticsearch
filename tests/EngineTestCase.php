<?php

namespace Shabayek\Elastic\Tests;

use Elastic\Elasticsearch\Client;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Laravel\Scout\EngineManager;
use Psr\Http\Message\RequestInterface;
use Shabayek\Elastic\Connection\ClientFactory;
use Shabayek\Elastic\ElasticSearchEngine;
use Shabayek\Elastic\Tests\Fixtures\Article;
use Shabayek\Elastic\Tests\Fixtures\Document;
use Shabayek\Elastic\Tests\Fixtures\Post;
use Shabayek\Elastic\Tests\Fixtures\Product;
use Shabayek\Elastic\Tests\Support\FakeHttpClient;

/**
 * Runs the engine through Scout against an in-memory database and a fake
 * Elasticsearch HTTP transport.
 */
abstract class EngineTestCase extends TestCase
{
    protected FakeHttpClient $http;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('posts', function (Blueprint $table) {
            $table->increments('id');
            $table->string('title')->nullable();
        });

        Schema::create('products', function (Blueprint $table) {
            $table->string('sku')->primary();
            $table->string('name')->nullable();
        });

        Schema::create('documents', function (Blueprint $table) {
            $table->increments('id');
            $table->string('slug')->unique();
            $table->string('title')->nullable();
        });

        Schema::create('articles', function (Blueprint $table) {
            $table->increments('id');
            $table->string('title')->nullable();
            $table->softDeletes();
        });

        $this->http = new FakeHttpClient();
        $this->app->instance(Client::class, (new ClientFactory())->make(['host' => 'localhost'], $this->http));
        $this->app->make(EngineManager::class)->forgetEngines();
    }

    protected function engine(): ElasticSearchEngine
    {
        return $this->app->make(EngineManager::class)->engine('elasticsearch');
    }

    /**
     * Build a raw search response with hits in the given order.
     */
    protected function searchResponse(array $ids, ?int $total = null, array $sources = []): array
    {
        $hits = [];

        foreach (array_values($ids) as $position => $id) {
            $hits[] = [
                '_index' => 'posts',
                '_id' => (string) $id,
                '_score' => round(10 - $position * 0.1, 2),
                '_source' => $sources[$id] ?? [],
            ];
        }

        return [
            'took' => 1,
            'timed_out' => false,
            'hits' => [
                'total' => ['value' => $total ?? count($ids), 'relation' => 'eq'],
                'max_score' => $hits[0]['_score'] ?? null,
                'hits' => $hits,
            ],
        ];
    }

    /**
     * Run the callback without syncing to the search index.
     */
    protected function withoutSearchSync(callable $callback): mixed
    {
        $result = null;

        foreach ([Post::class, Product::class, Document::class, Article::class] as $class) {
            $class::disableSearchSyncing();
        }

        try {
            $result = $callback();
        } finally {
            foreach ([Post::class, Product::class, Document::class, Article::class] as $class) {
                $class::enableSearchSyncing();
            }
        }

        return $result;
    }

    /**
     * Decode a bulk request into [action type, action metadata, source|null] operations.
     */
    protected function bulkOperations(RequestInterface $request): array
    {
        $lines = array_map(
            fn ($line) => json_decode($line, true),
            array_values(array_filter(explode("\n", (string) $request->getBody()), fn ($line) => $line !== ''))
        );

        $operations = [];

        while (($action = array_shift($lines)) !== null) {
            $type = array_key_first($action);
            $source = in_array($type, ['index', 'create', 'update']) ? array_shift($lines) : null;
            $operations[] = [$type, $action[$type], $source];
        }

        return $operations;
    }

    /**
     * Respond to the next bulk requests as Elasticsearch would, with per-operation results.
     *
     * The resolver receives (type, metadata) and returns an item result; by default every
     * operation succeeds.
     */
    protected function respondToBulk(int $times = 1, ?callable $resolver = null): void
    {
        $resolver ??= fn (string $type, array $meta) => $this->bulkItem($type, $meta);

        for ($i = 0; $i < $times; $i++) {
            $this->http->pushCallback(function (RequestInterface $request) use ($resolver) {
                $items = [];

                foreach ($this->bulkOperations($request) as [$type, $meta]) {
                    $items[] = [$type => $resolver($type, $meta)];
                }

                $errors = (bool) array_filter($items, fn ($item) => isset(reset($item)['error']));

                return FakeHttpClient::response(['took' => 3, 'errors' => $errors, 'items' => $items]);
            });
        }
    }

    /**
     * A successful (or, with a status and error, failed) bulk item result.
     */
    protected function bulkItem(string $type, array $meta, ?int $status = null, ?array $error = null, ?string $result = null): array
    {
        $item = [
            '_index' => $meta['_index'],
            '_id' => $meta['_id'],
            'status' => $status ?? ($type === 'delete' ? 200 : 201),
        ];

        if ($error !== null) {
            return $item + ['error' => $error];
        }

        return $item + ['result' => $result ?? ($type === 'delete' ? 'deleted' : 'created')];
    }

    protected function requestBody(RequestInterface $request): ?array
    {
        return json_decode((string) $request->getBody(), true);
    }

    /**
     * Requests as "METHOD /path?query" strings.
     */
    protected function sentRequests(): array
    {
        return array_map(function (RequestInterface $request) {
            $uri = $request->getUri();

            return $request->getMethod().' '.$uri->getPath().($uri->getQuery() !== '' ? '?'.$uri->getQuery() : '');
        }, $this->http->requests);
    }
}
