<?php

namespace Shabayek\Elastic\Tests\Unit;

use Elastic\Elasticsearch\Client;
use Illuminate\Support\ServiceProvider;
use Laravel\Scout\EngineManager;
use Shabayek\Elastic\Connection\ClientFactory;
use Shabayek\Elastic\ElasticSearchEngine;
use Shabayek\Elastic\Providers\ElasticServiceProvider;
use Shabayek\Elastic\Tests\TestCase;

class ElasticServiceProviderTest extends TestCase
{
    public function test_it_merges_the_package_configuration_with_safe_defaults()
    {
        $config = $this->app['config']->get('elasticsearch');

        $this->assertSame('localhost', $config['host']);
        $this->assertSame('9200', $config['port']);
        $this->assertSame('http', $config['scheme']);
        $this->assertNull($config['username']);
        $this->assertNull($config['password']);
        $this->assertNull($config['api_key']);
        $this->assertTrue($config['ssl_verification']);
        $this->assertSame(500, $config['indexing']['chunk_size']);
    }

    public function test_it_registers_the_client_as_a_shared_singleton_with_a_string_alias()
    {
        $client = $this->app->make(Client::class);

        $this->assertInstanceOf(Client::class, $client);
        $this->assertSame($client, $this->app->make(Client::class));
        $this->assertSame($client, $this->app->make('elasticsearch'));
    }

    public function test_the_client_is_built_from_the_application_configuration()
    {
        $this->app['config']->set('elasticsearch.hosts', 'https://search.example.com:9243');

        $client = $this->app->make(Client::class);

        $node = $client->getTransport()->getNodePool()->nextNode();
        $this->assertSame('https://search.example.com:9243', (string) $node->getUri());
    }

    public function test_the_client_factory_can_be_swapped_in_the_container()
    {
        $client = (new ClientFactory())->make(['host' => 'localhost']);
        $factory = \Mockery::mock(ClientFactory::class);
        $factory->shouldReceive('make')->once()->andReturn($client);
        $this->app->instance(ClientFactory::class, $factory);

        $this->assertSame($client, $this->app->make(Client::class));
    }

    public function test_it_registers_the_elasticsearch_scout_engine()
    {
        $engine = $this->app->make(EngineManager::class)->engine('elasticsearch');

        $this->assertInstanceOf(ElasticSearchEngine::class, $engine);
    }

    public function test_the_engine_is_the_default_when_scout_driver_is_elasticsearch()
    {
        $this->assertInstanceOf(ElasticSearchEngine::class, $this->app->make(EngineManager::class)->engine());
    }

    public function test_the_engine_is_registered_when_the_engine_manager_was_resolved_before_boot()
    {
        $manager = $this->app->make(EngineManager::class);
        $manager->forgetDrivers();
        (fn () => $this->customCreators = [])->call($manager);

        try {
            $manager->engine('elasticsearch');
            $this->fail('The elasticsearch driver should not exist before the provider boots.');
        } catch (\InvalidArgumentException) {
            // expected
        }

        // Booting against an already resolved manager must register the driver immediately.
        (new ElasticServiceProvider($this->app))->boot();

        $this->assertInstanceOf(ElasticSearchEngine::class, $manager->engine('elasticsearch'));
    }

    public function test_the_config_file_is_publishable_under_both_tags()
    {
        $source = realpath(__DIR__.'/../../config/elasticsearch.php');

        foreach (['elasticsearch-config', 'config'] as $tag) {
            $paths = ServiceProvider::pathsToPublish(ElasticServiceProvider::class, $tag);

            $this->assertSame([config_path('elasticsearch.php')], array_values($paths), $tag);
            $this->assertSame($source, realpath(array_key_first($paths)), $tag);
        }
    }

    public function test_the_publish_command_copies_the_config_file()
    {
        $target = config_path('elasticsearch.php');
        @unlink($target);

        try {
            $this->artisan('vendor:publish', [
                '--provider' => ElasticServiceProvider::class,
                '--tag' => 'elasticsearch-config',
            ])->assertSuccessful();

            $this->assertFileExists($target);
            $this->assertFileEquals(__DIR__.'/../../config/elasticsearch.php', $target);
        } finally {
            @unlink($target);
        }
    }
}
