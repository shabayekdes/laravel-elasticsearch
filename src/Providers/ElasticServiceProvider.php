<?php

namespace Shabayek\Elastic\Providers;

use Illuminate\Support\ServiceProvider;

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
