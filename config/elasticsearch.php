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
    | The hostname or IP address of the ElasticSearch server, used when
    | "hosts" is empty. A full URL such as "https://es01:9200" is also
    | accepted; its scheme and port then take precedence over the options
    | around it.
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

    /*
    |--------------------------------------------------------------------------
    | Indexing
    |--------------------------------------------------------------------------
    |
    | Models are indexed and deleted through the bulk API. "chunk_size" is the
    | maximum number of documents sent in one bulk request.
    |
    | "refresh" controls when the changes become visible to searches: false
    | (default, on the next periodic refresh), true (refresh immediately) or
    | "wait_for" (wait for the next refresh before returning).
    |
    */
    'indexing' => [
        'chunk_size' => (int) env('ELASTIC_BULK_CHUNK_SIZE', 500),
        'refresh' => env('ELASTIC_REFRESH', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Cursor
    |--------------------------------------------------------------------------
    |
    | Model::search()->cursor() pages through the results with a point in
    | time and search_after. "batch_size" is the number of hits fetched per
    | page and "keep_alive" how long Elasticsearch keeps the point in time
    | open between two pages.
    |
    */
    'cursor' => [
        'batch_size' => (int) env('ELASTIC_CURSOR_BATCH_SIZE', 1000),
        'keep_alive' => env('ELASTIC_CURSOR_KEEP_ALIVE', '1m'),
    ],
];
