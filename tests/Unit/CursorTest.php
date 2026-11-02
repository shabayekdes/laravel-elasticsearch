<?php

namespace Shabayek\Elastic\Tests\Unit;

use Elastic\Elasticsearch\Client;
use Elastic\Elasticsearch\Exception\ClientResponseException;
use Elastic\Elasticsearch\Exception\ServerResponseException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\LazyCollection;
use InvalidArgumentException;
use LogicException;
use Laravel\Scout\EngineManager;
use RuntimeException;
use Shabayek\Elastic\Exceptions\IncompleteSearchResultsException;
use Shabayek\Elastic\Tests\EngineTestCase;
use Shabayek\Elastic\Tests\Fixtures\Article;
use Shabayek\Elastic\Tests\Fixtures\Document;
use Shabayek\Elastic\Tests\Fixtures\Post;
use Shabayek\Elastic\Tests\Fixtures\Product;
use Shabayek\Elastic\Tests\Support\FakePointInTimeServer;

class CursorTest extends EngineTestCase
{
    protected function seedPosts(int $count): void
    {
        $this->withoutSearchSync(function () use ($count) {
            foreach (array_chunk(range(1, $count), 500) as $chunk) {
                DB::table('posts')->insert(array_map(fn ($id) => ['id' => $id, 'title' => "post {$id}"], $chunk));
            }
        });
    }

    /**
     * Documents in relevance order: descending scores with ties, ids shuffled.
     */
    protected function rankedDocuments(array $ids): array
    {
        shuffle($ids);
        $documents = [];

        foreach (array_values($ids) as $position => $id) {
            $documents[] = ['id' => $id, 'score' => round(100 - intdiv($position, 7) * 0.01, 2)];
        }

        return $documents;
    }

    protected function server(array $documents, string $index = 'posts'): FakePointInTimeServer
    {
        $this->http->respondUsing($server = new FakePointInTimeServer($documents, $index));

        return $server;
    }

    protected function configure(string $key, $value): void
    {
        $this->app['config']->set("elasticsearch.cursor.{$key}", $value);
        $this->app->make(EngineManager::class)->forgetEngines();
    }

    public function test_it_streams_more_than_ten_thousand_hits_in_relevance_order()
    {
        $this->seedPosts(12345);
        $documents = $this->rankedDocuments(range(1, 12345));
        $server = $this->server($documents);
        DB::enableQueryLog();

        $ids = [];
        foreach (Post::search('post')->cursor() as $post) {
            $ids[] = $post->id;
        }

        $this->assertCount(12345, $ids);
        $this->assertSame(array_column($documents, 'id'), $ids);
        $this->assertSame($ids, array_values(array_unique($ids)));

        // 13 pages of at most 1000 hits, each mapped with its own database query.
        $this->assertCount(13, $server->searches);
        $this->assertSame(array_fill(0, 13, 1000), array_column($server->searches, 'size'));
        $queries = DB::getQueryLog();
        $this->assertCount(13, $queries);
        foreach ($queries as $query) {
            $this->assertLessThanOrEqual(1000, substr_count($query['query'], ',') + 1);
        }
    }

    public function test_each_page_continues_after_the_previous_page_with_the_latest_point_in_time()
    {
        $this->seedPosts(5);
        $server = $this->server($this->rankedDocuments(range(1, 5)));
        $this->configure('batch_size', 2);

        Post::search('post')->cursor()->all();

        $this->assertSame([['index' => 'posts', 'keep_alive' => '1m']], $server->opened);
        $this->assertSame(['pit-0', 'pit-1', 'pit-2'], array_column(array_column($server->searches, 'pit'), 'id'));
        $this->assertSame(['1m', '1m', '1m'], array_column(array_column($server->searches, 'pit'), 'keep_alive'));
        $this->assertArrayNotHasKey('search_after', $server->searches[0]);
        $this->assertSame(1, end($server->searches[1]['search_after']));
        $this->assertSame(3, end($server->searches[2]['search_after']));
        $this->assertSame(['pit-3'], $server->closed);
    }

    public function test_pages_sort_by_relevance_with_a_shard_doc_tiebreaker_and_no_index_or_total()
    {
        $this->seedPosts(1);
        $server = $this->server([['id' => 1]]);

        Post::search('post')->cursor()->all();

        $body = $server->searches[0];
        $this->assertSame([['_score' => ['order' => 'desc']], ['_shard_doc' => ['order' => 'asc']]], $body['sort']);
        $this->assertFalse($body['track_total_hits']);
        $this->assertArrayNotHasKey('from', $body);
        $this->assertSame('/_search', $this->http->requests[1]->getUri()->getPath());
    }

    public function test_relevance_order_is_kept_across_page_boundaries_with_tied_scores()
    {
        $this->seedPosts(7);
        $documents = array_map(fn ($id) => ['id' => $id, 'score' => 5.0], [6, 2, 7, 1, 5, 3, 4]);
        $this->server($documents);
        $this->configure('batch_size', 2);

        $this->assertSame([6, 2, 7, 1, 5, 3, 4], Post::search('post')->cursor()->pluck('id')->all());
    }

    public function test_database_records_that_disappeared_are_skipped_without_losing_others()
    {
        $this->seedPosts(10);
        $documents = $this->rankedDocuments(range(1, 10));
        $this->server($documents);
        $this->configure('batch_size', 3);
        // Records at the start, middle and on a page boundary are deleted after indexing.
        $deleted = [$documents[0]['id'], $documents[2]['id'], $documents[3]['id'], $documents[9]['id']];
        DB::table('posts')->whereIn('id', $deleted)->delete();

        $ids = Post::search('post')->cursor()->pluck('id')->all();

        $this->assertSame(array_values(array_diff(array_column($documents, 'id'), $deleted)), $ids);
    }

    public function test_custom_string_scout_keys_are_streamed_across_pages()
    {
        $skus = array_map(fn ($i) => sprintf('SKU-%04d', $i), range(1, 2500));
        $this->withoutSearchSync(function () use ($skus) {
            foreach (array_chunk($skus, 500) as $chunk) {
                DB::table('products')->insert(array_map(fn ($sku) => ['sku' => $sku, 'name' => $sku], $chunk));
            }
        });
        $documents = $this->rankedDocuments($skus);
        $server = $this->server($documents, 'products_read');

        $result = Product::search('lamp')->cursor()->pluck('sku')->all();

        $this->assertSame(array_column($documents, 'id'), $result);
        $this->assertSame('products_read', $server->opened[0]['index']);
        $this->assertCount(3, $server->searches);
    }

    public function test_overridden_scout_keys_are_streamed_across_pages()
    {
        $this->withoutSearchSync(function () {
            foreach (['a', 'b', 'c', 'd', 'e'] as $slug) {
                Document::create(['slug' => $slug, 'title' => $slug]);
            }
        });
        $this->server(array_map(fn ($slug) => ['id' => $slug], ['d', 'a', 'e', 'b', 'c']), 'documents');
        $this->configure('batch_size', 2);

        $this->assertSame(['d', 'a', 'e', 'b', 'c'], Document::search('doc')->cursor()->pluck('slug')->all());
    }

    public function test_take_limits_the_streamed_hits()
    {
        $this->seedPosts(3000);
        $server = $this->server($this->rankedDocuments(range(1, 3000)));

        $models = Post::search('post')->take(2500)->cursor()->all();

        $this->assertCount(2500, $models);
        $this->assertSame([1000, 1000, 500], array_column($server->searches, 'size'));
        $this->assertCount(1, $server->closed);
    }

    public function test_take_zero_sends_no_request()
    {
        $this->assertSame([], Post::search('post')->take(0)->cursor()->all());
        $this->assertSame([], $this->http->requests);
    }

    public function test_order_by_is_used_with_the_tiebreaker()
    {
        $this->seedPosts(2);
        $server = $this->server([['id' => 2], ['id' => 1]]);

        Post::search('post')->orderBy('views', 'desc')->cursor()->all();

        $this->assertSame([['views' => ['order' => 'desc']], ['_shard_doc' => ['order' => 'asc']]], $server->searches[0]['sort']);
    }

    public function test_the_search_query_and_filters_are_used_for_every_page()
    {
        $this->seedPosts(3);
        $server = $this->server([['id' => 1], ['id' => 2], ['id' => 3]]);
        $this->configure('batch_size', 2);
        $builder = Post::search('post')->where('status', 'published')->whereIn('tag', ['a'])->whereNotIn('color', ['red']);
        $this->http->push($this->searchResponse([]));
        $builder->raw();
        $expected = $this->requestBody($this->http->requests[0])['query'];

        $builder->cursor()->all();

        $this->assertCount(2, $server->searches);
        foreach ($server->searches as $body) {
            $this->assertSame($expected, $body['query']);
        }
    }

    public function test_soft_deleted_records_are_excluded_unless_requested()
    {
        $this->app['config']->set('scout.soft_delete', true);
        [$live, $trashed] = $this->withoutSearchSync(function () {
            $live = Article::create(['title' => 'live']);
            $trashed = Article::create(['title' => 'trashed']);
            $trashed->delete();

            return [$live, $trashed];
        });
        $filters = fn (FakePointInTimeServer $server) => $server->searches[0]['query']['bool']['filter'] ?? [];

        $server = $this->server([['id' => $live->id]], 'articles');
        $this->assertSame([$live->id], Article::search('a')->cursor()->pluck('id')->all());
        $this->assertSame([['term' => ['__soft_deleted' => 0]]], $filters($server));

        $server = $this->server([['id' => $trashed->id], ['id' => $live->id]], 'articles');
        $this->assertSame([$trashed->id, $live->id], Article::search('a')->withTrashed()->cursor()->pluck('id')->all());
        $this->assertSame([], $filters($server));

        $server = $this->server([['id' => $trashed->id]], 'articles');
        $this->assertSame([$trashed->id], Article::search('a')->onlyTrashed()->cursor()->pluck('id')->all());
        $this->assertSame([['term' => ['__soft_deleted' => 1]]], $filters($server));
    }

    public function test_the_query_callback_is_applied_to_every_page()
    {
        $this->seedPosts(6);
        $this->server(array_map(fn ($id) => ['id' => $id], [6, 5, 4, 3, 2, 1]));
        $this->configure('batch_size', 2);

        $ids = Post::search('post')->query(fn ($query) => $query->whereRaw('id % 2 = 1'))->cursor()->pluck('id')->all();

        $this->assertSame([5, 3, 1], $ids);
    }

    public function test_the_cursor_is_lazy()
    {
        $this->seedPosts(5000);
        $server = $this->server($this->rankedDocuments(range(1, 5000)));

        $cursor = Post::search('post')->cursor();
        $this->assertSame([], $server->opened);

        $first = $cursor->take(5)->all();

        $this->assertCount(5, $first);
        $this->assertCount(1, $server->searches);
    }

    public function test_the_point_in_time_is_closed_when_iteration_stops_early()
    {
        $this->seedPosts(5000);
        $server = $this->server($this->rankedDocuments(range(1, 5000)));

        foreach (Post::search('post')->cursor() as $index => $post) {
            if ($index === 1500) {
                break;
            }
        }

        $this->assertCount(2, $server->searches);
        $this->assertSame(['pit-2'], $server->closed);
    }

    public function test_the_point_in_time_is_closed_when_a_search_fails()
    {
        $this->seedPosts(3000);
        $server = $this->server($this->rankedDocuments(range(1, 3000)));
        $server->failOnPage = 2;
        $seen = 0;

        try {
            foreach (Post::search('post')->cursor() as $post) {
                $seen++;
            }
            $this->fail('Expected a ServerResponseException.');
        } catch (ServerResponseException) {
            // expected
        }

        $this->assertSame(1000, $seen);
        $this->assertSame(['pit-1'], $server->closed);
    }

    public function test_the_point_in_time_is_closed_when_the_consumer_throws()
    {
        $this->seedPosts(10);
        $server = $this->server($this->rankedDocuments(range(1, 10)));

        try {
            Post::search('post')->cursor()->each(function () {
                throw new RuntimeException('consumer failed');
            });
            $this->fail('Expected the consumer exception.');
        } catch (RuntimeException $e) {
            $this->assertSame('consumer failed', $e->getMessage());
        }

        $this->assertSame(['pit-1'], $server->closed);
    }

    public function test_incomplete_pages_fail_instead_of_skipping_records()
    {
        $this->seedPosts(3000);
        $server = $this->server($this->rankedDocuments(range(1, 3000)));
        $server->shardFailureOnPage = 2;

        try {
            Post::search('post')->cursor()->all();
            $this->fail('Expected an IncompleteSearchResultsException.');
        } catch (IncompleteSearchResultsException $e) {
            $this->assertStringContainsString('1 failed shard(s)', $e->getMessage());
            $this->assertStringContainsString('node left', $e->getMessage());
        }

        $this->assertSame(['pit-2'], $server->closed);
    }

    public function test_terminated_early_pages_fail_and_close_the_latest_point_in_time()
    {
        $this->seedPosts(3000);
        $server = $this->server($this->rankedDocuments(range(1, 3000)));
        $server->terminatedEarlyOnPage = 2;
        $seen = 0;

        try {
            foreach (Post::search('post')->cursor() as $post) {
                $seen++;
            }
            $this->fail('Expected an IncompleteSearchResultsException.');
        } catch (IncompleteSearchResultsException $e) {
            $this->assertSame('Elasticsearch returned incomplete results (no failed shards, terminated early).', $e->getMessage());
        }

        $this->assertSame(1000, $seen);
        $this->assertCount(2, $server->searches);
        $this->assertSame(['pit-2'], $server->closed);
    }

    public function test_terminated_early_false_is_a_complete_page()
    {
        $this->seedPosts(10);
        $server = $this->server($this->rankedDocuments(range(1, 10)));
        $server->terminatedEarlyOnPage = 2;

        $this->assertCount(10, Post::search('post')->cursor()->all());
        $this->assertSame(['pit-1'], $server->closed);
    }

    public function test_a_failure_to_close_the_point_in_time_is_reported_not_thrown()
    {
        Exceptions::fake();
        $this->seedPosts(1);
        $server = $this->server([['id' => 1]]);
        $server->closeStatus = 404;

        $this->assertSame([1], Post::search('post')->cursor()->pluck('id')->all());

        $this->assertSame(['pit-1'], $server->closed);
        Exceptions::assertReported(ClientResponseException::class);
    }

    public function test_an_empty_result_opens_and_closes_one_point_in_time()
    {
        $server = $this->server([]);

        $this->assertSame([], Post::search('nothing')->cursor()->all());

        $this->assertCount(1, $server->opened);
        $this->assertCount(1, $server->searches);
        $this->assertSame(['pit-1'], $server->closed);
    }

    public function test_opening_a_point_in_time_on_a_missing_index_fails_without_closing()
    {
        $this->http->push(['error' => ['type' => 'index_not_found_exception'], 'status' => 404], 404);

        $this->expectException(ClientResponseException::class);

        try {
            Post::search('post')->cursor()->all();
        } finally {
            $this->assertCount(1, $this->http->requests);
        }
    }

    public function test_within_opens_the_point_in_time_on_the_custom_index()
    {
        $server = $this->server([], 'posts_archive');

        Post::search('post')->within('posts_archive')->cursor()->all();

        $this->assertSame('posts_archive', $server->opened[0]['index']);
    }

    public function test_options_are_used_for_every_page()
    {
        $this->seedPosts(1);
        $server = $this->server([['id' => 1]]);

        Post::search('post')->options(['highlight' => ['fields' => ['title' => new \stdClass()]], 'sort' => [['views' => 'desc']]])->cursor()->all();

        $this->assertSame(['fields' => ['title' => []]], $server->searches[0]['highlight']);
        $this->assertSame([['views' => 'desc'], ['_shard_doc' => ['order' => 'asc']]], $server->searches[0]['sort']);
    }

    public function test_cursor_managed_options_are_rejected()
    {
        $this->server([]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The [search_after] search option is managed by the engine when iterating a cursor.');

        Post::search('post')->options(['search_after' => [1]])->cursor()->all();
    }

    public function test_the_search_callback_runs_each_page()
    {
        $this->seedPosts(3);
        $server = $this->server([['id' => 3], ['id' => 1], ['id' => 2]]);
        $this->configure('batch_size', 2);
        $calls = [];

        $ids = Post::search('post', function (Client $client, $query, array $params) use (&$calls) {
            $calls[] = $params;

            return $client->search($params);
        })->cursor()->pluck('id')->all();

        $this->assertSame([3, 1, 2], $ids);
        $this->assertCount(2, $calls);
        $this->assertArrayNotHasKey('index', $calls[0]);
        $this->assertSame('pit-0', $calls[0]['body']['pit']['id']);
        $this->assertCount(2, $server->searches);
    }

    /**
     * Iterate a 3-page cursor whose search callback applies $modify to the
     * request of page $page, and return the exception, the number of models
     * yielded and the server.
     */
    protected function cursorWithModifiedRequest(int $page, callable $modify): array
    {
        $this->seedPosts(6);
        $server = $this->server($this->rankedDocuments(range(1, 6)));
        $this->configure('batch_size', 2);
        $calls = 0;
        $seen = 0;
        $exception = null;

        $cursor = Post::search('post', function (Client $client, $query, array $params) use ($page, $modify, &$calls) {
            return $client->search(++$calls === $page ? $modify($params) : $params);
        })->cursor();

        try {
            foreach ($cursor as $post) {
                $seen++;
            }
        } catch (LogicException $e) {
            $exception = $e;
        }

        return [$exception, $seen, $server];
    }

    /**
     * Assert the cursor stopped with a LogicException naming the changed
     * parameters, without yielding page 2, and closed the point in time.
     */
    protected function assertManagedParametersRejected(string $parameters, callable $modify): void
    {
        [$exception, $seen, $server] = $this->cursorWithModifiedRequest(2, $modify);

        $this->assertInstanceOf(LogicException::class, $exception);
        $this->assertStringContainsString("cursor-managed [{$parameters}] parameter(s)", $exception->getMessage());
        $this->assertSame(2, $seen);
        $this->assertSame(['pit-1'], $server->closed);
    }

    public function test_a_cursor_search_callback_cannot_change_the_point_in_time()
    {
        $this->assertManagedParametersRejected('pit', function ($params) {
            $params['body']['pit']['keep_alive'] = '1h';

            return $params;
        });
    }

    public function test_a_cursor_search_callback_cannot_change_search_after()
    {
        $this->assertManagedParametersRejected('search_after', function ($params) {
            $params['body']['search_after'] = [1.0, 0];

            return $params;
        });
    }

    public function test_a_cursor_search_callback_cannot_change_the_sort()
    {
        $this->assertManagedParametersRejected('sort', function ($params) {
            $params['body']['sort'] = [['_shard_doc' => ['order' => 'asc']]];

            return $params;
        });
    }

    public function test_a_cursor_search_callback_cannot_change_the_size()
    {
        $this->assertManagedParametersRejected('size', function ($params) {
            $params['body']['size'] = 1;

            return $params;
        });
    }

    public function test_a_cursor_search_callback_cannot_add_from()
    {
        $this->assertManagedParametersRejected('from', function ($params) {
            $params['body']['from'] = 1;

            return $params;
        });
    }

    public function test_a_cursor_search_callback_cannot_remove_managed_parameters()
    {
        $this->assertManagedParametersRejected('search_after, sort', function ($params) {
            unset($params['body']['search_after'], $params['body']['sort']);

            return $params;
        });
    }

    public function test_a_cursor_search_callback_cannot_override_managed_parameters_in_the_query_string()
    {
        [$exception, $seen, $server] = $this->cursorWithModifiedRequest(1, function ($params) {
            $params['size'] = 1;

            return $params;
        });

        $this->assertStringContainsString('cursor-managed [size] parameter(s)', $exception->getMessage());
        $this->assertSame(0, $seen);
        $this->assertSame(['pit-0'], $server->closed);
    }

    public function test_a_cursor_search_callback_must_send_its_search_through_the_client()
    {
        $this->seedPosts(1);
        $server = $this->server([['id' => 1]]);

        try {
            Post::search('post', fn () => $this->searchResponse([1]))->cursor()->all();
            $this->fail('Expected a LogicException.');
        } catch (LogicException $e) {
            $this->assertSame('A cursor search callback must send its search through the given client.', $e->getMessage());
        }

        $this->assertSame([], $server->searches);
        $this->assertSame(['pit-0'], $server->closed);
    }

    public function test_a_cursor_search_callback_can_change_the_query_and_other_options()
    {
        $this->seedPosts(3);
        $server = $this->server([['id' => 3], ['id' => 1], ['id' => 2]]);
        $this->configure('batch_size', 2);

        $ids = Post::search('post', function (Client $client, $query, array $params) {
            $params['body']['query'] = ['match' => ['title' => $query]];
            $params['body']['highlight'] = ['fields' => ['title' => new \stdClass()]];

            return $client->search($params);
        })->cursor()->pluck('id')->all();

        $this->assertSame([3, 1, 2], $ids);
        $this->assertSame(['match' => ['title' => 'post']], $server->searches[1]['query']);
        $this->assertSame(['fields' => ['title' => []]], $server->searches[1]['highlight']);
        $this->assertSame(['pit-2'], $server->closed);
    }

    public function test_the_after_raw_search_callback_is_applied_to_each_page()
    {
        $this->seedPosts(4);
        $this->server([['id' => 1], ['id' => 2], ['id' => 3], ['id' => 4]]);
        $this->configure('batch_size', 2);

        $ids = Post::search('post')->withRawResults(function ($results) {
            $results['hits']['hits'] = array_reverse($results['hits']['hits']);

            return $results;
        })->cursor()->pluck('id')->all();

        $this->assertSame([2, 1, 4, 3], $ids);
    }

    public function test_the_batch_size_and_keep_alive_are_configurable()
    {
        $this->seedPosts(5);
        $server = $this->server($this->rankedDocuments(range(1, 5)));
        $this->configure('batch_size', 4);
        $this->configure('keep_alive', '30s');

        Post::search('post')->cursor()->all();

        $this->assertSame([4, 4], array_column($server->searches, 'size'));
        $this->assertSame('30s', $server->opened[0]['keep_alive']);
        $this->assertSame('30s', $server->searches[1]['pit']['keep_alive']);
    }

    public function test_an_invalid_batch_size_is_rejected()
    {
        $this->configure('batch_size', 0);

        $this->expectException(InvalidArgumentException::class);

        $this->engine();
    }

    public function test_a_cursor_returns_a_lazy_collection()
    {
        $this->assertInstanceOf(LazyCollection::class, Post::search('post')->cursor());
    }
}
