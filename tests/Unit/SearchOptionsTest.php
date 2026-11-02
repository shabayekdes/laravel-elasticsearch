<?php

namespace Shabayek\Elastic\Tests\Unit;

use Elastic\Elasticsearch\Client;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Laravel\Scout\Builder;
use Laravel\Scout\Exceptions\NotSupportedException;
use Shabayek\Elastic\Tests\EngineTestCase;
use Shabayek\Elastic\Tests\Fixtures\Post;

class SearchOptionsTest extends EngineTestCase
{
    public function test_options_are_added_to_the_request_body()
    {
        $this->http->push($this->searchResponse([]));

        Post::search('lamp')->where('status', 'published')->options([
            'highlight' => ['fields' => ['title' => new \stdClass()]],
            'min_score' => 0.5,
            '_source' => ['title'],
        ])->raw();

        $body = $this->requestBody($this->http->lastRequest());
        $this->assertSame(['fields' => ['title' => []]], $body['highlight']);
        $this->assertSame(0.5, $body['min_score']);
        $this->assertSame(['title'], $body['_source']);
        $this->assertSame([['term' => ['status' => 'published']]], $body['query']['bool']['filter']);
    }

    public function test_options_may_replace_the_sort_when_order_by_is_not_used()
    {
        $this->http->push($this->searchResponse([]));

        Post::search('lamp')->options(['sort' => [['views' => 'desc'], '_score']])->raw();

        $this->assertSame([['views' => 'desc'], '_score'], $this->requestBody($this->http->lastRequest())['sort']);
    }

    public function test_sort_option_and_order_by_cannot_be_combined()
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Use either orderBy() or the [sort] search option, not both.');

        Post::search('lamp')->orderBy('views')->options(['sort' => ['title']])->raw();
    }

    public function test_engine_managed_options_are_rejected()
    {
        foreach (['from' => 10, 'size' => 10, 'query' => ['match_all' => new \stdClass()]] as $key => $value) {
            try {
                Post::search('lamp')->options([$key => $value])->raw();
                $this->fail("The [{$key}] option should be rejected.");
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString("The [{$key}] search option is managed by the engine", $e->getMessage());
            }
        }

        $this->assertSame([], $this->http->requests);
    }

    public function test_the_search_callback_receives_the_client_query_and_request()
    {
        $received = null;

        $results = Post::search('lamp', function ($client, $query, $params) use (&$received) {
            $received = [$client, $query, $params];

            return $this->searchResponse([2, 1]);
        })->where('status', 'published')->take(10)->raw();

        $this->assertInstanceOf(Client::class, $received[0]);
        $this->assertSame('lamp', $received[1]);
        $this->assertSame('posts', $received[2]['index']);
        $this->assertSame(10, $received[2]['body']['size']);
        $this->assertSame([['term' => ['status' => 'published']]], $received[2]['body']['query']['bool']['filter']);
        $this->assertSame(['2', '1'], array_column($results['hits']['hits'], '_id'));
        $this->assertSame([], $this->http->requests);
    }

    public function test_the_search_callback_can_send_a_custom_request_through_the_client()
    {
        $this->withoutSearchSync(fn () => DB::table('posts')->insert([['id' => 1, 'title' => 'a'], ['id' => 2, 'title' => 'b']]));
        $this->http->push($this->searchResponse([2, 1]));

        $models = Post::search('lamp', function (Client $client, $query, array $params) {
            $params['body']['query'] = ['match' => ['title' => ['query' => $query, 'fuzziness' => 'AUTO']]];

            return $client->search($params);
        })->get();

        $this->assertSame([2, 1], $models->pluck('id')->all());
        $this->assertSame(
            ['match' => ['title' => ['query' => 'lamp', 'fuzziness' => 'AUTO']]],
            $this->requestBody($this->http->lastRequest())['query']
        );
    }

    public function test_the_search_callback_is_used_for_pagination()
    {
        $this->withoutSearchSync(fn () => DB::table('posts')->insert([['id' => 1, 'title' => 'a']]));
        $sent = null;

        $paginator = Post::search('lamp', function ($client, $query, $params) use (&$sent) {
            $sent = $params;

            return $this->searchResponse([1], 31);
        })->paginate(10, 'page', 3);

        $this->assertSame(20, $sent['body']['from']);
        $this->assertSame(10, $sent['body']['size']);
        $this->assertSame(31, $paginator->total());
        $this->assertSame([1], $paginator->getCollection()->pluck('id')->all());
    }

    public function test_a_regular_search_callback_may_change_sort_size_and_from()
    {
        $this->http->push($this->searchResponse([]));

        Post::search('lamp', function (Client $client, $query, array $params) {
            $params['body'] = array_merge($params['body'], ['sort' => [['views' => 'desc']], 'size' => 3, 'from' => 6]);

            return $client->search($params);
        })->raw();

        $body = $this->requestBody($this->http->lastRequest());

        $this->assertSame([['views' => 'desc']], $body['sort']);
        $this->assertSame(3, $body['size']);
        $this->assertSame(6, $body['from']);
    }

    public function test_hybrid_search_is_not_supported()
    {
        if (! method_exists(Builder::class, 'hybrid')) {
            $this->markTestSkipped('Hybrid search only exists in Scout 11.');
        }

        $this->expectException(NotSupportedException::class);
        $this->expectExceptionMessage('does not support semantic or hybrid searches');

        Post::search('lamp')->hybrid()->raw();
    }

    public function test_semantic_search_is_not_supported()
    {
        if (! method_exists(Builder::class, 'semantic')) {
            $this->markTestSkipped('Semantic search only exists in Scout 11.');
        }

        $this->expectException(NotSupportedException::class);

        Post::search('lamp')->semantic()->raw();
    }
}
