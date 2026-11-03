<?php

namespace Shabayek\Elastic\Tests\Integration;

use Elastic\Elasticsearch\Client;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Laravel\Scout\EngineManager;
use RuntimeException;
use Shabayek\Elastic\ElasticSearchEngine;
use Shabayek\Elastic\Tests\TestCase;

/**
 * Runs the engine against a real Elasticsearch server.
 *
 * The server is configured through environment variables: ELASTICSEARCH_URL
 * (required) and, for a secured cluster, ELASTICSEARCH_USERNAME and
 * ELASTICSEARCH_PASSWORD, ELASTICSEARCH_API_KEY and ELASTICSEARCH_CA_BUNDLE.
 *
 * Every test writes to indexes with its own random prefix, and every index
 * with that prefix is deleted after the test, whether it passed or not.
 */
abstract class IntegrationTestCase extends TestCase
{
    protected string $prefix;

    protected Client $client;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $url = $this->env('ELASTICSEARCH_URL');

        if ($url === null) {
            throw new RuntimeException(
                'The integration tests need a running Elasticsearch server: set ELASTICSEARCH_URL, for example ELASTICSEARCH_URL=http://localhost:9208.'
            );
        }

        $this->prefix = 'scout_it_'.bin2hex(random_bytes(6)).'_';

        $app['config']->set('scout.prefix', $this->prefix);
        $app['config']->set('elasticsearch.hosts', $url);
        $app['config']->set('elasticsearch.username', $this->env('ELASTICSEARCH_USERNAME'));
        $app['config']->set('elasticsearch.password', $this->env('ELASTICSEARCH_PASSWORD'));
        $app['config']->set('elasticsearch.api_key', $this->env('ELASTICSEARCH_API_KEY'));
        $app['config']->set('elasticsearch.ca_bundle', $this->env('ELASTICSEARCH_CA_BUNDLE'));
        // Make writes visible to the next search, so assertions do not race the refresh interval.
        $app['config']->set('elasticsearch.indexing.refresh', true);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('books', function (Blueprint $table) {
            $table->increments('id');
            $table->string('title')->nullable();
            $table->string('author')->nullable();
            $table->string('status')->nullable();
            $table->integer('views')->nullable();
            $table->string('published_on')->nullable();
        });

        Schema::create('notes', function (Blueprint $table) {
            $table->increments('id');
            $table->string('title')->nullable();
            $table->softDeletes();
        });

        $this->client = $this->app->make(Client::class);
    }

    protected function tearDown(): void
    {
        try {
            $this->deleteTestIndexes();
        } finally {
            parent::tearDown();
        }
    }

    protected function engine(): ElasticSearchEngine
    {
        return $this->app->make(EngineManager::class)->engine('elasticsearch');
    }

    /**
     * Change a configuration value and build a fresh engine that uses it.
     */
    protected function configure(string $key, $value): void
    {
        $this->app['config']->set($key, $value);
        $this->app->make(EngineManager::class)->forgetEngines();
    }

    /**
     * Create the books index with explicit mappings and return its name.
     */
    protected function createBookIndex(): string
    {
        $index = (new Fixtures\Book)->indexableAs();

        $this->engine()->createIndex($index, [
            'mappings' => [
                'properties' => [
                    'id' => ['type' => 'integer'],
                    'title' => ['type' => 'text'],
                    'author' => ['type' => 'text'],
                    'status' => ['type' => 'keyword'],
                    'views' => ['type' => 'integer'],
                    'published_on' => ['type' => 'date', 'format' => 'yyyy-MM-dd'],
                ],
            ],
        ]);

        return $index;
    }

    /**
     * Create books in the database without indexing them.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, \Shabayek\Elastic\Tests\Integration\Fixtures\Book>
     */
    protected function books(array $rows)
    {
        return Fixtures\Book::withoutSyncingToSearch(function () use ($rows) {
            return (new Fixtures\Book)->newCollection(array_map(fn ($row) => Fixtures\Book::create($row), $rows));
        });
    }

    /**
     * The major version of the Elasticsearch server.
     */
    protected function serverMajorVersion(): int
    {
        return (int) explode('.', $this->client->info()->asArray()['version']['number'])[0];
    }

    /**
     * The number of documents in an index, after a refresh.
     */
    protected function documentCount(string $index): int
    {
        $this->client->indices()->refresh(['index' => $index]);

        return $this->client->count(['index' => $index])->asArray()['count'];
    }

    /**
     * The ids of all documents in an index.
     *
     * @return array<int, string>
     */
    protected function documentIds(string $index): array
    {
        $this->client->indices()->refresh(['index' => $index]);

        $hits = $this->client->search([
            'index' => $index,
            'body' => ['size' => 1000, 'query' => ['match_all' => new \stdClass()], '_source' => false],
        ])->asArray()['hits']['hits'];

        $ids = array_column($hits, '_id');
        sort($ids);

        return $ids;
    }

    /**
     * The number of search contexts (scrolls and points in time) open on an index.
     */
    protected function openSearchContexts(string $index): int
    {
        $stats = $this->client->indices()->stats(['index' => $index, 'metric' => 'search'])->asArray();

        return $stats['_all']['total']['search']['open_contexts'];
    }

    /**
     * Delete every index created by this test. Index names are listed and
     * deleted explicitly, so action.destructive_requires_name may stay on.
     */
    protected function deleteTestIndexes(): void
    {
        if (! isset($this->client, $this->prefix)) {
            return;
        }

        $indexes = array_keys($this->client->indices()->get([
            'index' => $this->prefix.'*',
            'expand_wildcards' => 'all',
        ])->asArray());

        if ($indexes !== []) {
            $this->client->indices()->delete(['index' => implode(',', $indexes)]);
        }
    }

    protected function env(string $name): ?string
    {
        $value = getenv($name);

        return $value === false || $value === '' ? null : $value;
    }
}
