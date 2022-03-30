<?php

namespace Shabayek\Elastic\Providers;

use Elastic\Elasticsearch\ClientBuilder;
use Illuminate\Support\ServiceProvider;
use Laravel\Scout\EngineManager;
use Shabayek\Elastic\Console\Commands\ElasticSearchIndex;
use Shabayek\Elastic\ElasticSearchEngine;

class ElasticServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot()
    {
        $this->publishes([
            __DIR__.'/../../config/elasticsearch.php' => config_path('elasticsearch.php'),
        ]);

        $this->app->singleton('elasticsearch', function ($app) {
            $host = $app['config']->get('elasticsearch.host');
            $port = $app['config']->get('elasticsearch.port');
            $username = $app['config']->get('elasticsearch.username');
            $password = $app['config']->get('elasticsearch.password');

            return ClientBuilder::create()
                ->setHosts([
                    $host.':'.$port,
                ])
                ->setBasicAuthentication($username, $password)
                ->build();
        });
        resolve(EngineManager::class)->extend('elasticsearch', function ($app) {
            return new ElasticSearchEngine(
                app('elasticsearch')
            );
        });

        if ($this->app->runningInConsole()) {
            $this->commands([
                ElasticSearchIndex::class,
            ]);
        }
    }

    /**
     * Register the service provider.
     *
     * @return void
     */
    public function register()
    {
        $this->mergeConfigFrom(__DIR__.'/../../config/elasticsearch.php', 'elasticsearch');
    }
}
