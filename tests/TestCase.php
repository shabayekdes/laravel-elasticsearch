<?php

namespace Shabayek\Elastic\Tests;

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
            ElasticServiceProvider::class,
        ];
    }
}
