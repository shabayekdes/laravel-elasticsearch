<?php

namespace Shabayek\Elastic\Tests;

use Laravel\Scout\ScoutServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;
use Shabayek\Elastic\Providers\ElasticServiceProvider;

/**
 * TestCase class.
 *
 * @author Esmail Shabayek
 */
abstract class TestCase extends Orchestra
{
    /**
     * Get package service providers.
     *
     * @param  \Illuminate\Foundation\Application  $app
     * @return array
     */
    protected function getPackageProviders($app): array
    {
        return [
            ScoutServiceProvider::class,
            ElasticServiceProvider::class,
        ];
    }

    /**
     * Define environment setup.
     *
     * @param  \Illuminate\Foundation\Application  $app
     * @return void
     */
    protected function defineEnvironment($app): void
    {
        $app['config']->set('scout.driver', 'elasticsearch');
    }
}
