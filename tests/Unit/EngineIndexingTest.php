<?php

namespace Shabayek\Elastic\Tests\Unit;

use Illuminate\Database\Eloquent\Collection;
use Laravel\Scout\Jobs\RemoveFromSearch;
use Shabayek\Elastic\Tests\EngineTestCase;
use Shabayek\Elastic\Tests\Fixtures\Article;
use Shabayek\Elastic\Tests\Fixtures\Document;
use Shabayek\Elastic\Tests\Fixtures\Post;
use Shabayek\Elastic\Tests\Fixtures\Product;

class EngineIndexingTest extends EngineTestCase
{
    public function test_update_indexes_each_model_under_its_scout_key_in_one_bulk_request()
    {
        $posts = $this->withoutSearchSync(fn () => new Collection([
            Post::create(['title' => 'first']),
            Post::create(['title' => 'second']),
        ]));
        $this->respondToBulk();

        $this->engine()->update($posts);

        $this->assertSame(['POST /_bulk'], $this->sentRequests());
        $this->assertSame([
            ['index', ['_index' => 'posts', '_id' => '1'], ['id' => 1, 'title' => 'first']],
            ['index', ['_index' => 'posts', '_id' => '2'], ['id' => 2, 'title' => 'second']],
        ], $this->bulkOperations($this->http->lastRequest()));
    }

    public function test_update_uses_a_custom_string_primary_key_and_the_indexable_index()
    {
        $product = $this->withoutSearchSync(fn () => Product::create(['sku' => 'SKU-42', 'name' => 'Lamp']));
        $this->respondToBulk();

        $this->engine()->update(new Collection([$product]));

        $this->assertSame(
            [['index', ['_index' => 'products_write', '_id' => 'SKU-42'], ['name' => 'Lamp']]],
            $this->bulkOperations($this->http->lastRequest())
        );
    }

    public function test_update_uses_an_overridden_get_scout_key()
    {
        $document = $this->withoutSearchSync(fn () => Document::create(['slug' => 'hello-world', 'title' => 'Hello']));
        $this->respondToBulk();

        $this->engine()->update(new Collection([$document]));

        $this->assertSame(
            [['index', ['_index' => 'documents', '_id' => 'hello-world'], ['title' => 'Hello']]],
            $this->bulkOperations($this->http->lastRequest())
        );
    }

    public function test_update_skips_models_with_an_empty_searchable_array()
    {
        $documents = $this->withoutSearchSync(fn () => new Collection([
            Document::create(['slug' => 'empty', 'title' => null]),
            Document::create(['slug' => 'full', 'title' => 'Full']),
        ]));
        $this->respondToBulk();

        $this->engine()->update($documents);

        $this->assertSame(
            [['index', ['_index' => 'documents', '_id' => 'full'], ['title' => 'Full']]],
            $this->bulkOperations($this->http->lastRequest())
        );
    }

    public function test_update_sends_nothing_when_every_searchable_array_is_empty()
    {
        $document = $this->withoutSearchSync(fn () => Document::create(['slug' => 'empty', 'title' => null]));

        $this->engine()->update(new Collection([$document]));

        $this->assertSame([], $this->http->requests);
    }

    public function test_update_with_no_models_sends_nothing()
    {
        $this->engine()->update(new Collection());

        $this->assertSame([], $this->http->requests);
    }

    public function test_update_does_not_add_soft_delete_metadata_when_soft_delete_is_disabled()
    {
        $article = $this->withoutSearchSync(fn () => Article::create(['title' => 'Draft']));
        $this->respondToBulk();

        $this->engine()->update(new Collection([$article]));

        $this->assertSame(['title' => 'Draft'], $this->bulkOperations($this->http->lastRequest())[0][2]);
    }

    public function test_update_adds_soft_delete_metadata_when_soft_delete_is_enabled()
    {
        $this->app['config']->set('scout.soft_delete', true);

        [$live, $trashed] = $this->withoutSearchSync(function () {
            $live = Article::create(['title' => 'Live']);
            $trashed = Article::create(['title' => 'Trashed']);
            $trashed->delete();

            return [$live, $trashed];
        });
        $this->respondToBulk();

        $this->engine()->update(new Collection([$live, $trashed]));

        $operations = $this->bulkOperations($this->http->lastRequest());
        $this->assertSame(['title' => 'Live', '__soft_deleted' => 0], $operations[0][2]);
        $this->assertSame(['title' => 'Trashed', '__soft_deleted' => 1], $operations[1][2]);
    }

    public function test_update_merges_custom_scout_metadata()
    {
        $post = $this->withoutSearchSync(fn () => Post::create(['title' => 'Meta']));
        $post->withScoutMetadata('tenant', 7);
        $this->respondToBulk();

        $this->engine()->update(new Collection([$post]));

        $this->assertSame(['id' => 1, 'title' => 'Meta', 'tenant' => 7], $this->bulkOperations($this->http->lastRequest())[0][2]);
    }

    public function test_delete_removes_models_by_scout_key_from_the_indexable_index_in_one_bulk_request()
    {
        $models = $this->withoutSearchSync(fn () => [
            Product::create(['sku' => 'A-1', 'name' => 'One']),
            Product::create(['sku' => 'B-2', 'name' => 'Two']),
        ]);
        $this->respondToBulk();

        $this->engine()->delete(new Collection($models));

        $this->assertSame(['POST /_bulk'], $this->sentRequests());
        $this->assertSame([
            ['delete', ['_index' => 'products_write', '_id' => 'A-1'], null],
            ['delete', ['_index' => 'products_write', '_id' => 'B-2'], null],
        ], $this->bulkOperations($this->http->lastRequest()));
    }

    public function test_delete_uses_an_overridden_get_scout_key()
    {
        $document = $this->withoutSearchSync(fn () => Document::create(['slug' => 'to-remove', 'title' => 'x']));
        $this->respondToBulk();

        $this->engine()->delete(new Collection([$document]));

        $this->assertSame(
            [['delete', ['_index' => 'documents', '_id' => 'to-remove'], null]],
            $this->bulkOperations($this->http->lastRequest())
        );
    }

    public function test_delete_uses_scout_keys_of_models_restored_by_a_queued_remove_job()
    {
        $documents = $this->withoutSearchSync(fn () => new Collection([
            Document::create(['slug' => 'queued-a', 'title' => 'a']),
            Document::create(['slug' => 'queued-b', 'title' => 'b']),
        ]));
        $this->respondToBulk();

        $job = unserialize(serialize(new RemoveFromSearch($documents)));
        $this->engine()->delete($job->models);

        $this->assertSame(['queued-a', 'queued-b'], array_map(
            fn ($operation) => $operation[1]['_id'],
            $this->bulkOperations($this->http->lastRequest())
        ));
    }

    public function test_delete_with_no_models_sends_nothing()
    {
        $this->engine()->delete(new Collection());

        $this->assertSame([], $this->http->requests);
    }

    public function test_searchable_and_unsearchable_go_through_the_engine()
    {
        $post = $this->withoutSearchSync(fn () => Post::create(['title' => 'Synced']));

        $this->respondToBulk(2);

        $post->searchable();
        $post->unsearchable();

        $this->assertSame(['POST /_bulk', 'POST /_bulk'], $this->sentRequests());
        $this->assertSame('index', $this->bulkOperations($this->http->requests[0])[0][0]);
        $this->assertSame('delete', $this->bulkOperations($this->http->requests[1])[0][0]);
    }
}
