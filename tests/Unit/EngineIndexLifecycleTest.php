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

    public function test_error_responses_are_recognised_as_elasticsearch_responses()
    {
        // Guards the fake transport: error bodies must carry the product header too.
        $this->assertSame('Elasticsearch', FakeHttpClient::response([], 404)->getHeaderLine('X-Elastic-Product'));
    }
}
