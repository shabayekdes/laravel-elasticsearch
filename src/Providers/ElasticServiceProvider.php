<?php

namespace Shabayek\Elastic\Providers;

use Elastic\Elasticsearch\Client;
use Illuminate\Support\ServiceProvider;
use Laravel\Scout\EngineManager;
use Shabayek\Elastic\Connection\ClientFactory;
use Shabayek\Elastic\Console\Commands\ElasticSearchIndex;
use Shabayek\Elastic\ElasticSearchEngine;

class ElasticServiceProvider extends ServiceProvider
{
    /**
     * Register the service provider.
     *
     * @return void
     */
    public function register()
    {
        $this->mergeConfigFrom(__DIR__.'/../../config/elasticsearch.php', 'elasticsearch');

        $this->app->singleton(ClientFactory::class);

        $this->app->singleton(Client::class, function ($app) {
            return $app->make(ClientFactory::class)->make($app['config']->get('elasticsearch', []));
        });

        $this->app->alias(Client::class, 'elasticsearch');
    }

    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot()
    {
        $this->publishes([
            __DIR__.'/../../config/elasticsearch.php' => config_path('elasticsearch.php'),
        ], ['elasticsearch-config', 'config']);

        $this->callAfterResolving(EngineManager::class, function (EngineManager $manager) {
            $manager->extend('elasticsearch', function ($app) {
                return new ElasticSearchEngine($app->make(Client::class));
            });
        });

        if ($this->app->runningInConsole()) {
            $this->commands([
                ElasticSearchIndex::class,
            ]);
        }
    }
}
