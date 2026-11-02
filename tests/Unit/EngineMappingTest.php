<?php

namespace Shabayek\Elastic\Tests\Unit;

use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\LazyCollection;
use Laravel\Scout\Builder;
use Shabayek\Elastic\Tests\EngineTestCase;
use Shabayek\Elastic\Tests\Fixtures\Document;
use Shabayek\Elastic\Tests\Fixtures\Post;
use Shabayek\Elastic\Tests\Fixtures\Product;

class EngineMappingTest extends EngineTestCase
{
    protected function seedPosts(int $count): void
    {
        $this->withoutSearchSync(function () use ($count) {
            foreach (array_chunk(range(1, $count), 500) as $chunk) {
                DB::table('posts')->insert(array_map(fn ($id) => ['id' => $id, 'title' => "post {$id}"], $chunk));
            }
        });
    }

    protected function builder($model = null): Builder
    {
        return new Builder($model ?? new Post(), 'query');
    }

    public function test_map_preserves_elasticsearch_relevance_order()
    {
        $this->seedPosts(5);

        $models = $this->engine()->map($this->builder(), $this->searchResponse([4, 1, 5, 2]), new Post());

        $this->assertInstanceOf(EloquentCollection::class, $models);
        $this->assertSame([4, 1, 5, 2], $models->pluck('id')->all());
        $this->assertSame([0, 1, 2, 3], $models->keys()->all());
    }

    public function test_map_skips_hits_without_a_database_model()
    {
        $this->seedPosts(3);

        $models = $this->engine()->map($this->builder(), $this->searchResponse([3, 99, 1, 42]), new Post());

        $this->assertSame([3, 1], $models->pluck('id')->all());
    }

    public function test_map_skips_models_excluded_by_the_query_callback_and_keeps_order()
    {
        $this->seedPosts(4);
        $builder = $this->builder()->query(fn ($query) => $query->where('id', '!=', 2));

        $models = $this->engine()->map($builder, $this->searchResponse([2, 4, 1]), new Post());

        $this->assertSame([4, 1], $models->pluck('id')->all());
    }

    public function test_map_returns_an_empty_eloquent_collection_for_empty_or_malformed_results()
    {
        foreach ([$this->searchResponse([]), ['hits' => []], [], null] as $results) {
            $models = $this->engine()->map($this->builder(), $results, new Post());

            $this->assertInstanceOf(EloquentCollection::class, $models);
            $this->assertCount(0, $models);
        }

        $this->assertSame([], $this->http->requests);
    }

    public function test_map_attaches_hit_metadata_but_not_the_source()
    {
        $this->seedPosts(2);

        $models = $this->engine()->map($this->builder(), $this->searchResponse([2, 1]), new Post());

        $this->assertSame(['_index' => 'posts', '_id' => '2', '_score' => 10.0], $models->first()->scoutMetadata());
        $this->assertSame(9.9, $models->last()->scoutMetadata()['_score']);
    }

    public function test_map_supports_custom_string_primary_keys()
    {
        $this->withoutSearchSync(function () {
            Product::create(['sku' => 'B', 'name' => 'b']);
            Product::create(['sku' => 'A', 'name' => 'a']);
            Product::create(['sku' => 'C', 'name' => 'c']);
        });

        $models = $this->engine()->map($this->builder(new Product()), $this->searchResponse(['C', 'A', 'missing', 'B']), new Product());

        $this->assertSame(['C', 'A', 'B'], $models->pluck('sku')->all());
    }

    public function test_map_supports_an_overridden_scout_key()
    {
        $this->withoutSearchSync(function () {
            Document::create(['slug' => 'first', 'title' => 'one']);
            Document::create(['slug' => 'second', 'title' => 'two']);
        });

        $models = $this->engine()->map($this->builder(new Document()), $this->searchResponse(['second', 'first']), new Document());

        $this->assertSame(['second', 'first'], $models->pluck('slug')->all());
    }

    public function test_lazy_map_preserves_relevance_order_and_skips_missing_models()
    {
        $this->seedPosts(5);

        $models = $this->engine()->lazyMap($this->builder(), $this->searchResponse([5, 77, 2, 4]), new Post());

        $this->assertInstanceOf(LazyCollection::class, $models);
        $this->assertSame([5, 2, 4], $models->pluck('id')->all());
        $this->assertSame([0, 1, 2], $models->keys()->all());
        $this->assertSame(10.0, $models->first()->scoutMetadata()['_score']);
    }

    public function test_lazy_map_returns_an_empty_lazy_collection_for_empty_or_malformed_results()
    {
        foreach ([$this->searchResponse([]), ['hits' => ['hits' => null]], null] as $results) {
            $models = $this->engine()->lazyMap($this->builder(), $results, new Post());

            $this->assertInstanceOf(LazyCollection::class, $models);
            $this->assertSame([], $models->all());
        }
    }

    public function test_lazy_map_keeps_relevance_order_for_large_result_sets()
    {
        $this->seedPosts(5000);
        $ids = range(5000, 1);
        shuffle($ids);
        // Hits for models that no longer exist in the database, at the start and in the middle.
        array_splice($ids, 2500, 0, [7001, 7002]);
        array_splice($ids, 0, 0, [6001, 6002]);

        $models = $this->engine()->lazyMap($this->builder(), $this->searchResponse($ids), new Post());
        $expected = array_values(array_filter($ids, fn ($id) => $id <= 5000));

        $this->assertInstanceOf(LazyCollection::class, $models);
        $this->assertCount(5000, $expected);
        $this->assertSame($expected, $models->pluck('id')->all());
    }

    public function test_lazy_map_loads_models_with_a_single_cursor_query()
    {
        $this->seedPosts(10);
        DB::enableQueryLog();

        $this->engine()->lazyMap($this->builder(), $this->searchResponse([3, 1, 2]), new Post())->all();

        $this->assertCount(1, DB::getQueryLog());
    }

    public function test_get_and_cursor_return_ordered_models_through_scout()
    {
        $this->seedPosts(3);
        $this->http->push($this->searchResponse([2, 3, 1]))->push($this->searchResponse([3, 1]));

        $this->assertSame([2, 3, 1], Post::search('post')->get()->pluck('id')->all());

        $cursor = Post::search('post')->cursor();
        $this->assertInstanceOf(LazyCollection::class, $cursor);
        $this->assertSame([3, 1], $cursor->pluck('id')->all());
    }

    public function test_cursor_returns_an_empty_lazy_collection_when_nothing_matches()
    {
        $this->http->push($this->searchResponse([]));

        $cursor = Post::search('nothing')->cursor();

        $this->assertInstanceOf(LazyCollection::class, $cursor);
        $this->assertCount(0, $cursor);
    }

    public function test_get_applies_the_after_raw_search_callback()
    {
        $this->seedPosts(3);
        $this->http->push($this->searchResponse([1, 2, 3]));

        $models = Post::search('post')
            ->withRawResults(function ($results) {
                $results['hits']['hits'] = array_reverse($results['hits']['hits']);

                return $results;
            })
            ->get();

        $this->assertSame([3, 2, 1], $models->pluck('id')->all());
    }

    public function test_map_ids_returns_the_document_ids_in_order()
    {
        $ids = $this->engine()->mapIds($this->searchResponse([9, 3, 7]));

        $this->assertSame(['9', '3', '7'], $ids->all());
        $this->assertSame([], $this->engine()->mapIds($this->searchResponse([]))->all());
        $this->assertSame([], $this->engine()->mapIds(null)->all());
    }

    public function test_map_ids_from_reads_the_key_from_the_source_and_falls_back_to_the_id()
    {
        $results = $this->searchResponse([1, 2], null, [1 => ['uuid' => 'aaa'], 2 => ['title' => 'no uuid']]);

        $this->assertSame(['aaa', '2'], $this->engine()->mapIdsFrom($results, 'uuid')->all());
        $this->assertSame([], $this->engine()->mapIdsFrom($this->searchResponse([]), 'uuid')->all());
    }

    public function test_keys_are_cast_to_the_scout_key_type()
    {
        $this->http->push($this->searchResponse([5, 2]))->push($this->searchResponse(['SKU-2', 'SKU-1']));

        $this->assertSame([5, 2], Post::search('post')->keys()->all());
        $this->assertSame(['SKU-2', 'SKU-1'], Product::search('lamp')->keys()->all());
    }

    public function test_get_total_count_reads_object_and_integer_totals()
    {
        $engine = $this->engine();

        $this->assertSame(25000, $engine->getTotalCount(['hits' => ['total' => ['value' => 25000, 'relation' => 'eq']]]));
        $this->assertSame(12, $engine->getTotalCount(['hits' => ['total' => 12]]));
        $this->assertSame(0, $engine->getTotalCount(['hits' => []]));
        $this->assertSame(0, $engine->getTotalCount(null));
    }

    public function test_searches_request_exact_total_hits()
    {
        $this->http->push($this->searchResponse([]))->push($this->searchResponse([]));

        Post::search('post')->raw();
        Post::search('post')->paginateRaw(10);

        foreach ($this->http->requests as $request) {
            $this->assertTrue($this->requestBody($request)['track_total_hits']);
        }
    }

    public function test_paginator_uses_the_exact_total_beyond_ten_thousand_hits()
    {
        $this->seedPosts(3);
        $this->http->push($this->searchResponse([1, 2, 3], 25000));

        $paginator = Post::search('post')->paginate(3, 'page', 2);

        $this->assertSame(25000, $paginator->total());
        $this->assertSame(8334, $paginator->lastPage());
        $this->assertSame([1, 2, 3], $paginator->getCollection()->pluck('id')->all());
        $this->assertSame(3, $this->requestBody($this->http->lastRequest())['from']);
        $this->assertTrue($this->requestBody($this->http->lastRequest())['track_total_hits']);
    }
}
