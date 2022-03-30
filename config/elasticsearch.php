<?php
/*
|--------------------------------------------------------------------------
| ElasticSearch Configuration
|--------------------------------------------------------------------------
|
*/
return [

    /*
    |--------------------------------------------------------------------------
    | ElasticSearch Host
    |--------------------------------------------------------------------------
    |
    | The IP host of the ElasticSearch server.
    | Default: localhost
    |
    */
    'host' => env('ELASTIC_HOST', 'localhost'),
    /*
    |--------------------------------------------------------------------------
    | ElasticSearch Port
    |--------------------------------------------------------------------------
    |
    | The port of the ElasticSearch server.
    | Default: 9200
    |
    */
    'port' => env('ELASTIC_PORT', '9200'),
    /*
    |--------------------------------------------------------------------------
    | ElasticSearch Username
    |--------------------------------------------------------------------------
    |
    | The username of the ElasticSearch server.
    |
    */
    'username' => env('ELASTIC_USERNAME', 'elastic'),
    /*
    |--------------------------------------------------------------------------
    | ElasticSearch Password
    |--------------------------------------------------------------------------
    |
    | The password of the ElasticSearch server.
    |
    */
    'password' => env('ELASTIC_PASSWORD', 'elastic'),
];
