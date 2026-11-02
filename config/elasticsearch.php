<?php

/*
|--------------------------------------------------------------------------
| ElasticSearch Configuration
|--------------------------------------------------------------------------
|
| Connection settings for the Elasticsearch client used by the Scout engine.
| Never commit real credentials: set them through environment variables.
|
*/

return [

    /*
    |--------------------------------------------------------------------------
    | ElasticSearch Hosts
    |--------------------------------------------------------------------------
    |
    | A comma separated list of full node URLs, for example:
    | "https://es01:9200,https://es02:9200". When empty, a single node URL is
    | built from the "scheme", "host" and "port" options below.
    |
    */
    'hosts' => env('ELASTIC_HOSTS'),

    /*
    |--------------------------------------------------------------------------
    | ElasticSearch Scheme
    |--------------------------------------------------------------------------
    |
    | The protocol used to reach the "host" below: "http" or "https".
    | Default: http
    |
    */
    'scheme' => env('ELASTIC_SCHEME', 'http'),

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
    | The username of the ElasticSearch server. Basic authentication is only
    | used when both the username and the password are set.
    |
    */
    'username' => env('ELASTIC_USERNAME'),

    /*
    |--------------------------------------------------------------------------
    | ElasticSearch Password
    |--------------------------------------------------------------------------
    |
    | The password of the ElasticSearch server.
    |
    */
    'password' => env('ELASTIC_PASSWORD'),

    /*
    |--------------------------------------------------------------------------
    | ElasticSearch API Key
    |--------------------------------------------------------------------------
    |
    | A base64 encoded API key. When set, it is used instead of basic
    | authentication.
    |
    */
    'api_key' => env('ELASTIC_API_KEY'),

    /*
    |--------------------------------------------------------------------------
    | Elastic Cloud ID
    |--------------------------------------------------------------------------
    |
    | The Cloud ID of an Elastic Cloud deployment. When set, it is used instead
    | of the hosts above.
    |
    */
    'cloud_id' => env('ELASTIC_CLOUD_ID'),

    /*
    |--------------------------------------------------------------------------
    | SSL Verification
    |--------------------------------------------------------------------------
    |
    | Whether to verify the server certificate on HTTPS connections, and an
    | optional path to the CA bundle used for verification.
    |
    */
    'ssl_verification' => (bool) env('ELASTIC_SSL_VERIFICATION', true),

    'ca_bundle' => env('ELASTIC_CA_BUNDLE'),
];
