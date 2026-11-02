<?php

namespace Shabayek\Elastic\Tests\Unit;

use Elastic\Elasticsearch\Client;
use Illuminate\Contracts\Console\Kernel;
use Shabayek\Elastic\Tests\EngineTestCase;
use Shabayek\Elastic\Tests\Fixtures\Post;
use Shabayek\Elastic\Tests\Fixtures\Product;

class ElasticSearchIndexCommandTest extends EngineTestCase
{
    protected const DEPRECATION = 'The scout:elasticsearch:create command is deprecated and will be removed in 2.0. Use "php artisan scout:index" instead.';

    public function test_it_creates_the_index_with_the_default_analysis_settings()
    {
        $this->http->push([], 404)->push(['acknowledged' => true, 'index' => 'posts']);

        $this->artisan('scout:elasticsearch:create', ['model' => Post::class])
            ->expectsOutput(self::DEPRECATION)
            ->expectsOutput('Index [posts] is ready.')
            ->assertExitCode(0);

        $this->assertSame(['HEAD /posts', 'PUT /posts'], $this->sentRequests());
        $analysis = $this->requestBody($this->http->lastRequest())['settings']['index']['analysis'];
        $this->assertSame('custom', $analysis['analyzer']['default']['type']);
        $this->assertSame('word_delimiter', $analysis['filter']['words_splitter']['type']);
    }

    public function test_it_creates_the_index_the_engine_writes_to()
    {
        $this->http->push([], 404)->push(['acknowledged' => true, 'index' => 'products_write']);

        $this->artisan('scout:elasticsearch:create', ['model' => Product::class])
            ->expectsOutput('Index [products_write] is ready.')
            ->assertExitCode(0);

        $this->assertSame(['HEAD /products_write', 'PUT /products_write'], $this->sentRequests());
    }

    public function test_an_existing_index_is_left_untouched_and_succeeds()
    {
        $this->http->push([], 200)->push(['posts' => ['settings' => []]]);

        $this->artisan('scout:elasticsearch:create', ['model' => Post::class])
            ->expectsOutput('Index [posts] is ready.')
            ->assertExitCode(0);

        $this->assertSame(['HEAD /posts', 'GET /posts'], $this->sentRequests());
    }

    public function test_an_unknown_class_fails_without_contacting_elasticsearch()
    {
        $this->artisan('scout:elasticsearch:create', ['model' => 'App\\Models\\Missing'])
            ->expectsOutput(self::DEPRECATION)
            ->expectsOutput('Model [App\\Models\\Missing] not found.')
            ->assertExitCode(1);

        $this->assertSame([], $this->http->requests);
    }

    public function test_a_class_that_is_not_a_searchable_model_fails_without_being_instantiated()
    {
        $this->artisan('scout:elasticsearch:create', ['model' => NotSearchable::class])
            ->expectsOutput('Model ['.NotSearchable::class.'] is not a searchable Eloquent model.')
            ->assertExitCode(1);

        $this->assertSame(0, NotSearchable::$instances);
        $this->assertSame([], $this->http->requests);
    }

    public function test_an_elasticsearch_error_fails_with_its_message()
    {
        $this->http
            ->push([], 404)
            ->push(['error' => ['type' => 'illegal_argument_exception', 'reason' => 'bad settings'], 'status' => 400], 400);

        $this->artisan('scout:elasticsearch:create', ['model' => Post::class])
            ->expectsOutputToContain('illegal_argument_exception')
            ->assertExitCode(1);
    }

    public function test_registering_the_command_does_not_connect_to_elasticsearch()
    {
        $this->app->forgetInstance(Client::class);
        $this->app->bind(Client::class, fn () => $this->fail('The client must not be resolved.'));

        $this->assertArrayHasKey('scout:elasticsearch:create', $this->app->make(Kernel::class)->all());
    }
}

class NotSearchable
{
    public static int $instances = 0;

    public function __construct()
    {
        static::$instances++;
    }
}
