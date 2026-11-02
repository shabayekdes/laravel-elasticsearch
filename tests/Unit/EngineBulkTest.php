<?php

namespace Shabayek\Elastic\Tests\Unit;

use Elastic\Elasticsearch\Exception\ClientResponseException;
use Elastic\Elasticsearch\Exception\ServerResponseException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Laravel\Scout\EngineManager;
use Shabayek\Elastic\Exceptions\BulkOperationException;
use Shabayek\Elastic\Tests\EngineTestCase;
use Shabayek\Elastic\Tests\Fixtures\Post;
use Shabayek\Elastic\Tests\Support\FakeHttpClient;

class EngineBulkTest extends EngineTestCase
{
    protected function posts(int $count): Collection
    {
        $this->withoutSearchSync(fn () => DB::table('posts')->insert(
            array_map(fn ($id) => ['id' => $id, 'title' => "post {$id}"], range(1, $count))
        ));

        return Post::query()->orderBy('id')->get();
    }

    protected function useChunkSize(int $size): void
    {
        $this->app['config']->set('elasticsearch.indexing.chunk_size', $size);
        $this->app->make(EngineManager::class)->forgetEngines();
    }

    /**
     * Ids sent in each bulk request.
     */
    protected function idsPerRequest(): array
    {
        return array_map(
            fn ($request) => array_map(fn ($operation) => $operation[1]['_id'], $this->bulkOperations($request)),
            $this->http->requests
        );
    }

    protected function captureBulkException(callable $callback): BulkOperationException
    {
        try {
            $callback();
        } catch (BulkOperationException $e) {
            return $e;
        }

        $this->fail('Expected a BulkOperationException.');
    }

    public function test_the_default_chunk_size_sends_up_to_500_documents_per_request()
    {
        $this->respondToBulk(2);

        $this->engine()->update($this->posts(501));

        $this->assertSame([500, 1], array_map('count', $this->idsPerRequest()));
    }

    public function test_documents_are_split_into_chunks_of_the_configured_size()
    {
        $this->useChunkSize(2);
        $this->respondToBulk(3);

        $this->engine()->update($this->posts(5));

        $this->assertSame([['1', '2'], ['3', '4'], ['5']], $this->idsPerRequest());
        $this->assertSame(['POST /_bulk', 'POST /_bulk', 'POST /_bulk'], $this->sentRequests());
    }

    public function test_an_exact_multiple_of_the_chunk_size_sends_no_empty_request()
    {
        $this->useChunkSize(2);
        $this->respondToBulk(2);

        $this->engine()->update($this->posts(4));

        $this->assertSame([['1', '2'], ['3', '4']], $this->idsPerRequest());
    }

    public function test_the_chunk_size_must_be_positive()
    {
        $this->useChunkSize(0);

        $this->expectException(InvalidArgumentException::class);

        $this->engine();
    }

    public function test_a_partial_failure_throws_with_the_failed_ids_and_reasons()
    {
        $this->respondToBulk(1, fn ($type, $meta) => $meta['_id'] === '2'
            ? $this->bulkItem($type, $meta, 400, ['type' => 'mapper_parsing_exception', 'reason' => 'failed to parse field [title]'])
            : $this->bulkItem($type, $meta));

        $e = $this->captureBulkException(fn () => $this->engine()->update($this->posts(3)));

        $this->assertSame(['2'], $e->failedIds());
        $this->assertSame(3, $e->total());
        $this->assertSame([[
            'operation' => 'index',
            'index' => 'posts',
            'id' => '2',
            'status' => 400,
            'type' => 'mapper_parsing_exception',
            'reason' => 'failed to parse field [title]',
        ]], $e->failures());
        $this->assertStringContainsString('failed for 1 of 3 operations', $e->getMessage());
        $this->assertStringContainsString('index [posts/2] mapper_parsing_exception: failed to parse field [title]', $e->getMessage());
    }

    public function test_failures_are_detected_from_items_even_when_the_errors_flag_is_false()
    {
        $this->http->pushCallback(fn () => FakeHttpClient::response(['errors' => false, 'items' => [
            ['index' => ['_index' => 'posts', '_id' => '1', 'status' => 201, 'result' => 'created']],
            ['index' => ['_index' => 'posts', '_id' => '2', 'status' => 429, 'error' => ['type' => 'es_rejected_execution_exception', 'reason' => 'queue full']]],
        ]]));

        $e = $this->captureBulkException(fn () => $this->engine()->update($this->posts(2)));

        $this->assertSame(['2'], $e->failedIds());
        $this->assertSame(429, $e->failures()[0]['status']);
    }

    public function test_authorization_failures_of_operations_are_reported()
    {
        $this->respondToBulk(1, fn ($type, $meta) => $this->bulkItem($type, $meta, 403, [
            'type' => 'security_exception',
            'reason' => 'action [indices:data/write/bulk[s]] is unauthorized',
        ]));

        $e = $this->captureBulkException(fn () => $this->engine()->update($this->posts(2)));

        $this->assertSame(['1', '2'], $e->failedIds());
        $this->assertSame(['security_exception', 'security_exception'], array_column($e->failures(), 'type'));
    }

    public function test_operations_missing_from_the_response_are_reported_as_failures()
    {
        $this->http->pushCallback(fn () => FakeHttpClient::response(['errors' => false, 'items' => [
            ['index' => ['_index' => 'posts', '_id' => '1', 'status' => 201, 'result' => 'created']],
        ]]));

        $e = $this->captureBulkException(fn () => $this->engine()->update($this->posts(2)));

        $this->assertSame(['2'], $e->failedIds());
        $this->assertSame('missing_result', $e->failures()[0]['type']);
    }

    public function test_a_response_without_items_fails_every_operation()
    {
        $this->http->push(['took' => 1]);

        $e = $this->captureBulkException(fn () => $this->engine()->update($this->posts(2)));

        $this->assertSame(['1', '2'], $e->failedIds());
    }

    public function test_non_success_statuses_without_an_error_body_are_failures()
    {
        $this->respondToBulk(1, fn ($type, $meta) => $this->bulkItem($type, $meta, 409, null, 'noop'));

        $e = $this->captureBulkException(fn () => $this->engine()->update($this->posts(1)));

        $this->assertSame(409, $e->failures()[0]['status']);
        $this->assertSame('noop', $e->failures()[0]['reason']);
    }

    public function test_every_chunk_is_sent_before_failures_are_reported_together()
    {
        $this->useChunkSize(2);
        $this->respondToBulk(3, fn ($type, $meta) => in_array($meta['_id'], ['1', '5'])
            ? $this->bulkItem($type, $meta, 400, ['type' => 'mapper_parsing_exception', 'reason' => 'bad'])
            : $this->bulkItem($type, $meta));

        $e = $this->captureBulkException(fn () => $this->engine()->update($this->posts(5)));

        $this->assertCount(3, $this->http->requests);
        $this->assertSame(['1', '5'], $e->failedIds());
        $this->assertSame(5, $e->total());
    }

    public function test_the_exception_message_summarises_many_failures()
    {
        $this->respondToBulk(1, fn ($type, $meta) => $this->bulkItem($type, $meta, 400, ['type' => 'mapper_parsing_exception', 'reason' => 'bad']));

        $e = $this->captureBulkException(fn () => $this->engine()->update($this->posts(8)));

        $this->assertCount(8, $e->failedIds());
        $this->assertStringContainsString('failed for 8 of 8 operations', $e->getMessage());
        $this->assertStringEndsWith('; and 3 more', $e->getMessage());
    }

    public function test_request_level_authentication_errors_are_not_swallowed()
    {
        $this->http->push(['error' => ['type' => 'security_exception', 'reason' => 'missing authentication credentials'], 'status' => 401], 401);

        $this->expectException(ClientResponseException::class);
        $this->expectExceptionCode(401);

        $this->engine()->update($this->posts(1));
    }

    public function test_request_level_server_errors_are_not_swallowed()
    {
        $this->http->push(['error' => ['type' => 'cluster_block_exception', 'reason' => 'blocked'], 'status' => 503], 503);

        $this->expectException(ServerResponseException::class);

        $this->engine()->update($this->posts(1));
    }

    public function test_a_successful_bulk_request_does_not_throw()
    {
        $this->respondToBulk();

        $this->engine()->update($this->posts(3));

        $this->assertCount(1, $this->http->requests);
    }
}
