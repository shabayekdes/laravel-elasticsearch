<?php

namespace Shabayek\Elastic\Tests\Unit;

use Elastic\Elasticsearch\Exception\ClientResponseException;
use Elastic\Elasticsearch\Exception\ServerResponseException;
use Shabayek\Elastic\Tests\EngineTestCase;
use Shabayek\Elastic\Tests\Fixtures\Post;
use Shabayek\Elastic\Tests\Fixtures\Product;
use Shabayek\Elastic\Tests\Support\FakeHttpClient;

class EngineIndexLifecycleTest extends EngineTestCase
{
    protected function error(string $type, int $status): array
    {
        return ['error' => ['type' => $type, 'reason' => $type], 'status' => $status];
    }

    public function test_flush_deletes_all_documents_but_keeps_the_index()
    {
        $this->http->push(['deleted' => 3, 'failures' => []]);

        $this->engine()->flush(new Product());

        $this->assertSame(['POST /products_write/_delete_by_query?conflicts=proceed&refresh=true'], $this->sentRequests());
        $this->assertSame('{"query":{"match_all":{}}}', (string) $this->http->lastRequest()->getBody());
    }

    public function test_flush_through_scout_never_drops_or_recreates_the_index()
    {
        $this->http->push(['deleted' => 0, 'failures' => []]);

        Post::removeAllFromSearch();

        $methods = array_map(fn ($request) => $request->getMethod(), $this->http->requests);
        $this->assertSame(['POST'], $methods);
    }

    public function test_flush_of_a_missing_index_is_a_no_op()
    {
        $this->http->push($this->error('index_not_found_exception', 404), 404);

        $this->engine()->flush(new Post());

        $this->assertCount(1, $this->http->requests);
    }

    public function test_flush_rethrows_other_client_errors()
    {
        $this->http->push($this->error('security_exception', 403), 403);

        $this->expectException(ClientResponseException::class);

        $this->engine()->flush(new Post());
    }

    public function test_flush_rethrows_server_errors()
    {
        $this->http->push($this->error('search_phase_execution_exception', 500), 500);

        $this->expectException(ServerResponseException::class);

        $this->engine()->flush(new Post());
    }

    public function test_create_index_creates_a_missing_index_with_the_default_analysis_settings()
    {
        $this->http->push([], 404)->push(['acknowledged' => true, 'index' => 'posts']);

        $response = $this->engine()->createIndex('posts');

        $this->assertSame(['HEAD /posts', 'PUT /posts'], $this->sentRequests());
        $body = $this->requestBody($this->http->lastRequest());
        $this->assertSame(['settings'], array_keys($body));
        $this->assertSame('custom', $body['settings']['index']['analysis']['analyzer']['default']['type']);
        $this->assertSame('word_delimiter', $body['settings']['index']['analysis']['filter']['words_splitter']['type']);
        $this->assertSame(['acknowledged' => true, 'index' => 'posts'], $response);
    }

    public function test_create_index_uses_given_settings_and_mappings_and_ignores_unknown_options()
    {
        $this->http->push([], 404)->push(['acknowledged' => true]);
        $settings = ['number_of_shards' => 1];
        $mappings = ['properties' => ['title' => ['type' => 'text'], 'status' => ['type' => 'keyword']]];

        $this->engine()->createIndex('posts', ['settings' => $settings, 'mappings' => $mappings, 'primaryKey' => 'id']);

        $this->assertSame(
            ['settings' => $settings, 'mappings' => $mappings],
            $this->requestBody($this->http->lastRequest())
        );
    }

    public function test_create_index_keeps_default_settings_when_only_mappings_are_given()
    {
        $this->http->push([], 404)->push(['acknowledged' => true]);

        $this->engine()->createIndex('posts', ['mappings' => ['properties' => ['title' => ['type' => 'text']]]]);

        $body = $this->requestBody($this->http->lastRequest());
        $this->assertArrayHasKey('analysis', $body['settings']['index']);
        $this->assertSame('text', $body['mappings']['properties']['title']['type']);
    }

    public function test_create_index_leaves_an_existing_index_untouched()
    {
        $existing = ['posts' => ['mappings' => [], 'settings' => ['index' => ['number_of_shards' => '1']]]];
        $this->http->push([], 200)->push($existing);

        $response = $this->engine()->createIndex('posts', ['settings' => ['number_of_shards' => 3]]);

        $this->assertSame(['HEAD /posts', 'GET /posts'], $this->sentRequests());
        $this->assertSame($existing, $response);
    }

    public function test_create_index_tolerates_a_concurrently_created_index()
    {
        $this->http
            ->push([], 404)
            ->push($this->error('resource_already_exists_exception', 400), 400)
            ->push(['posts' => ['settings' => []]]);

        $response = $this->engine()->createIndex('posts');

        $this->assertSame(['HEAD /posts', 'PUT /posts', 'GET /posts'], $this->sentRequests());
        $this->assertSame(['posts' => ['settings' => []]], $response);
    }

    public function test_create_index_rethrows_other_errors()
    {
        $this->http->push([], 404)->push($this->error('illegal_argument_exception', 400), 400);

        $this->expectException(ClientResponseException::class);

        $this->engine()->createIndex('posts', ['settings' => ['invalid' => true]]);
    }

    public function test_delete_index_deletes_the_index()
    {
        $this->http->push(['acknowledged' => true]);

        $response = $this->engine()->deleteIndex('posts');

        $this->assertSame(['DELETE /posts'], $this->sentRequests());
        $this->assertSame(['acknowledged' => true], $response);
    }

    public function test_delete_index_reports_a_missing_index()
    {
        $this->http->push($this->error('index_not_found_exception', 404), 404);

        $this->expectException(ClientResponseException::class);

        $this->engine()->deleteIndex('posts');
    }

    public function test_scout_index_command_creates_the_index()
    {
        $this->http->push([], 404)->push(['acknowledged' => true]);

        $this->artisan('scout:index', ['name' => 'posts'])
            ->expectsOutputToContain('Synchronised index ["posts"] successfully.')
            ->assertSuccessful();

        $this->assertSame(['HEAD /posts', 'PUT /posts'], $this->sentRequests());
    }

    public function test_scout_delete_index_command_deletes_the_index()
    {
        $this->http->push(['acknowledged' => true]);

        $this->artisan('scout:delete-index', ['name' => 'posts'])->assertSuccessful();

        $this->assertSame(['DELETE /posts'], $this->sentRequests());
    }

    public function test_error_responses_are_recognised_as_elasticsearch_responses()
    {
        // Guards the fake transport: error bodies must carry the product header too.
        $this->assertSame('Elasticsearch', FakeHttpClient::response([], 404)->getHeaderLine('X-Elastic-Product'));
    }
}
