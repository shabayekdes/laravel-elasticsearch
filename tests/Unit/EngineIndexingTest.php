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
    public function test_update_indexes_each_model_under_its_scout_key()
    {
        $posts = $this->withoutSearchSync(fn () => new Collection([
            Post::create(['title' => 'first']),
            Post::create(['title' => 'second']),
        ]));

        $this->engine()->update($posts);

        $this->assertSame(['PUT /posts/_doc/1', 'PUT /posts/_doc/2'], $this->sentRequests());
        $this->assertSame(['id' => 1, 'title' => 'first'], $this->requestBody($this->http->requests[0]));
    }

    public function test_update_uses_a_custom_string_primary_key_and_the_indexable_index()
    {
        $product = $this->withoutSearchSync(fn () => Product::create(['sku' => 'SKU-42', 'name' => 'Lamp']));

        $this->engine()->update(new Collection([$product]));

        $this->assertSame(['PUT /products_write/_doc/SKU-42'], $this->sentRequests());
        $this->assertSame(['name' => 'Lamp'], $this->requestBody($this->http->lastRequest()));
    }

    public function test_update_uses_an_overridden_get_scout_key()
    {
        $document = $this->withoutSearchSync(fn () => Document::create(['slug' => 'hello-world', 'title' => 'Hello']));

        $this->engine()->update(new Collection([$document]));

        $this->assertSame(['PUT /documents/_doc/hello-world'], $this->sentRequests());
    }

    public function test_update_skips_models_with_an_empty_searchable_array()
    {
        $documents = $this->withoutSearchSync(fn () => new Collection([
            Document::create(['slug' => 'empty', 'title' => null]),
            Document::create(['slug' => 'full', 'title' => 'Full']),
        ]));

        $this->engine()->update($documents);

        $this->assertSame(['PUT /documents/_doc/full'], $this->sentRequests());
    }

    public function test_update_with_no_models_sends_nothing()
    {
        $this->engine()->update(new Collection());

        $this->assertSame([], $this->http->requests);
    }

    public function test_update_does_not_add_soft_delete_metadata_when_soft_delete_is_disabled()
    {
        $article = $this->withoutSearchSync(fn () => Article::create(['title' => 'Draft']));

        $this->engine()->update(new Collection([$article]));

        $this->assertSame(['title' => 'Draft'], $this->requestBody($this->http->lastRequest()));
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

        $this->engine()->update(new Collection([$live, $trashed]));

        $this->assertSame(['title' => 'Live', '__soft_deleted' => 0], $this->requestBody($this->http->requests[0]));
        $this->assertSame(['title' => 'Trashed', '__soft_deleted' => 1], $this->requestBody($this->http->requests[1]));
    }

    public function test_update_merges_custom_scout_metadata()
    {
        $post = $this->withoutSearchSync(fn () => Post::create(['title' => 'Meta']));
        $post->withScoutMetadata('tenant', 7);

        $this->engine()->update(new Collection([$post]));

        $this->assertSame(['id' => 1, 'title' => 'Meta', 'tenant' => 7], $this->requestBody($this->http->lastRequest()));
    }

    public function test_delete_removes_each_model_by_its_scout_key_from_the_indexable_index()
    {
        $models = $this->withoutSearchSync(fn () => [
            Product::create(['sku' => 'A-1', 'name' => 'One']),
            Product::create(['sku' => 'B-2', 'name' => 'Two']),
        ]);

        $this->engine()->delete(new Collection($models));

        $this->assertSame(['DELETE /products_write/_doc/A-1', 'DELETE /products_write/_doc/B-2'], $this->sentRequests());
    }

    public function test_delete_uses_an_overridden_get_scout_key()
    {
        $document = $this->withoutSearchSync(fn () => Document::create(['slug' => 'to-remove', 'title' => 'x']));

        $this->engine()->delete(new Collection([$document]));

        $this->assertSame(['DELETE /documents/_doc/to-remove'], $this->sentRequests());
    }

    public function test_delete_uses_scout_keys_of_models_restored_by_a_queued_remove_job()
    {
        $documents = $this->withoutSearchSync(fn () => new Collection([
            Document::create(['slug' => 'queued-a', 'title' => 'a']),
            Document::create(['slug' => 'queued-b', 'title' => 'b']),
        ]));

        $job = unserialize(serialize(new RemoveFromSearch($documents)));
        $this->engine()->delete($job->models);

        $this->assertSame(['DELETE /documents/_doc/queued-a', 'DELETE /documents/_doc/queued-b'], $this->sentRequests());
    }

    public function test_delete_with_no_models_sends_nothing()
    {
        $this->engine()->delete(new Collection());

        $this->assertSame([], $this->http->requests);
    }

    public function test_searchable_and_unsearchable_go_through_the_engine()
    {
        $post = $this->withoutSearchSync(fn () => Post::create(['title' => 'Synced']));

        $post->searchable();
        $post->unsearchable();

        $this->assertSame(['PUT /posts/_doc/1', 'DELETE /posts/_doc/1'], $this->sentRequests());
    }
}
