<?php

namespace Shabayek\Elastic\Tests\Unit;

use Elastic\Elasticsearch\Client;
use Illuminate\Database\Eloquent\Model;
use Laravel\Scout\Builder;
use Laravel\Scout\Searchable;
use Shabayek\Elastic\Connection\ClientFactory;
use Shabayek\Elastic\ElasticSearchEngine;
use Shabayek\Elastic\Tests\Support\FakeHttpClient;
use Shabayek\Elastic\Tests\TestCase;

/**
 * Checks that the engine talks to the 8.x / 9.x client and keeps returning
 * plain arrays as raw results, like the 0.x releases did.
 */
class ClientCompatibilityTest extends TestCase
{
    public function test_raw_search_results_are_plain_arrays()
    {
        $http = (new FakeHttpClient())->push([
            'hits' => ['total' => ['value' => 1, 'relation' => 'eq'], 'hits' => [['_id' => '1', '_source' => []]]],
        ]);
        $this->app->instance(Client::class, (new ClientFactory())->make(['host' => 'localhost'], $http));

        $engine = new ElasticSearchEngine($this->app->make(Client::class));
        $results = $engine->search(new Builder(new CompatibilityModel(), 'laravel'));

        $this->assertIsArray($results);
        $this->assertSame(1, $engine->getTotalCount($results));
        $this->assertSame('POST', $http->lastRequest()->getMethod());
        $this->assertSame('/compatibility_models/_search', $http->lastRequest()->getUri()->getPath());
    }

    public function test_paginated_results_are_plain_arrays()
    {
        $http = (new FakeHttpClient())->push(['hits' => ['total' => ['value' => 0, 'relation' => 'eq'], 'hits' => []]]);
        $engine = new ElasticSearchEngine((new ClientFactory())->make(['host' => 'localhost'], $http));

        $results = $engine->paginate(new Builder(new CompatibilityModel(), 'laravel'), 15, 2);

        $this->assertIsArray($results);
        $body = json_decode((string) $http->lastRequest()->getBody(), true);
        $this->assertSame(15, $body['from']);
        $this->assertSame(15, $body['size']);
    }
}

class CompatibilityModel extends Model
{
    use Searchable;

    public function searchableAs()
    {
        return 'compatibility_models';
    }

    public function searchableFields()
    {
        return ['title'];
    }
}
