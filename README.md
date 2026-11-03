# Laravel ElasticSearch Scout Engine

[![Github Status](https://github.com/shabayekdes/laravel-elasticsearch/actions/workflows/tests.yml/badge.svg)](https://github.com/shabayekdes/laravel-elasticsearch/actions) [![Styleci Status](https://github.styleci.io/repos/476024226/shield?branch=main)](https://github.styleci.io/repos/476024226) [![Packagist version](https://img.shields.io/packagist/v/shabayek/laravel-elasticsearch)](https://packagist.org/packages/shabayek/laravel-elasticsearch) [![License](https://img.shields.io/packagist/l/shabayek/laravel-elasticsearch)](LICENSE) ![Packagist PHP Version Support](https://img.shields.io/packagist/php-v/shabayek/laravel-elasticsearch) ![Packagist Downloads](https://img.shields.io/packagist/dt/shabayek/laravel-elasticsearch)

An Elasticsearch engine for [Laravel Scout](https://laravel.com/docs/scout). It indexes your Eloquent models with the bulk API, searches them with full-text queries and non-scoring filters, and streams large result sets with a point in time.

- [Requirements](#requirements)
- [Installation](#installation)
- [Configuration](#configuration)
- [Indexes](#indexes)
- [Models](#models)
- [Indexing](#indexing)
- [Soft deletes](#soft-deletes)
- [Searching](#searching)
- [Filtering](#filtering)
- [Sorting, limits, pagination and options](#sorting-limits-pagination-and-options)
- [Raw results and hit metadata](#raw-results-and-hit-metadata)
- [Search callbacks](#search-callbacks)
- [Cursors](#cursors)
- [Limitations](#limitations)
- [Upgrading from 0.4](#upgrading-from-04)
- [Testing](#testing)

## Requirements

- PHP 8.2+ (Laravel 13 requires PHP 8.3+)
- Laravel 12 or 13
- Laravel Scout 10.24+ or 11
- Elasticsearch 8.16+ or 9.x, with the matching major version of the official PHP client (`elasticsearch/elasticsearch` ^8.16 or ^9.0)

Use the client major that matches your server: the 8.x client for Elasticsearch 8 and the 9.x client for Elasticsearch 9. The [official client documentation](https://github.com/elastic/elasticsearch-php#versioning) puts it this way: "for Elasticsearch `8.16`, use `8.16` of this library or above, but not `9.0`". Clients are forward compatible with servers of the same or a newer minor version within their major; compatibility with older servers is not guaranteed. This package requires client 8.16 or newer, so on Elasticsearch 8 run at least 8.16 (the latest 8.x release is preferable).

Composer installs the 9.x client by default, so for an Elasticsearch 8 cluster require the 8.x client yourself (see below).

## Installation

```bash
composer require shabayek/laravel-elasticsearch

# Only for an Elasticsearch 8.x cluster:
composer require elasticsearch/elasticsearch:^8.16
```

The service provider is registered through Laravel package auto-discovery. It merges the default configuration, registers the Elasticsearch client in the container and adds the `elasticsearch` Scout driver.

Select the driver in your `.env`:

```dotenv
SCOUT_DRIVER=elasticsearch
```

Optionally publish the configuration file to `config/elasticsearch.php`:

```bash
php artisan vendor:publish --tag=elasticsearch-config
```

The configured `Elastic\Elasticsearch\Client` is a container singleton (also bound as `elasticsearch`), so you can inject it to call Elasticsearch directly. To build the client differently, bind your own `Shabayek\Elastic\Connection\ClientFactory` subclass in the container.

## Configuration

Every option can be set through an environment variable. Never commit real credentials.

### Connection

| Variable | Default | Description |
| --- | --- | --- |
| `ELASTIC_HOSTS` | — | Comma-separated node URLs, for example `https://es01:9200,https://es02:9200`. Takes precedence over the three options below. |
| `ELASTIC_SCHEME` | `http` | Scheme used with `ELASTIC_HOST` when the host has none. |
| `ELASTIC_HOST` | `localhost` | Single node host (a full URL is accepted). |
| `ELASTIC_PORT` | `9200` | Port added to `ELASTIC_HOST` when it has none. |
| `ELASTIC_CLOUD_ID` | — | Elastic Cloud deployment ID. When set, the hosts above are ignored. |

### Authentication

| Variable | Description |
| --- | --- |
| `ELASTIC_API_KEY` | Base64-encoded API key (the `encoded` value Elasticsearch returns). Takes precedence over basic authentication. |
| `ELASTIC_USERNAME`, `ELASTIC_PASSWORD` | Basic authentication, used only when both are set. |

With neither, requests are sent without credentials.

```dotenv
# Elastic Cloud
ELASTIC_CLOUD_ID=my-deployment:ZXUtY2VudHJhbC0x...
ELASTIC_API_KEY=VnVhQ2ZHY0JDZGJrUW...

# Self-managed cluster over HTTPS
ELASTIC_HOSTS=https://es01.internal:9200
ELASTIC_USERNAME=scout
ELASTIC_PASSWORD=secret
ELASTIC_CA_BUNDLE=/etc/ssl/certs/elastic-ca.crt
```

### TLS

| Variable | Default | Description |
| --- | --- | --- |
| `ELASTIC_SSL_VERIFICATION` | `true` | Verify the server certificate on HTTPS connections. Only disable it for local development. |
| `ELASTIC_CA_BUNDLE` | — | Path to the CA certificate used to verify the server, for example the `http_ca.crt` generated by Elasticsearch's security auto-configuration. |

Use HTTPS by giving `https://` URLs in `ELASTIC_HOSTS` or by setting `ELASTIC_SCHEME=https`. Client certificates (mutual TLS) are not configurable; build the client yourself through a custom `ClientFactory` if you need them.

### Indexing and cursors

| Variable | Default | Description |
| --- | --- | --- |
| `ELASTIC_BULK_CHUNK_SIZE` | `500` | Maximum number of documents per bulk request. |
| `ELASTIC_REFRESH` | `false` | When indexed changes become searchable: `false` (next periodic refresh, about one second), `true` (refresh immediately) or `wait_for` (wait for the next refresh). `true` is expensive; avoid it in production. |
| `ELASTIC_CURSOR_BATCH_SIZE` | `1000` | Hits fetched per page by `cursor()`. |
| `ELASTIC_CURSOR_KEEP_ALIVE` | `1m` | How long the cursor's point in time stays open between two pages. |

Scout's own options (`SCOUT_PREFIX`, `SCOUT_QUEUE`, `scout.soft_delete`, chunk sizes for `scout:import`) live in `config/scout.php`.

## Indexes

Each model is written to the index named by its `indexableAs()` method and searched in the index named by `searchableAs()`. Both return Scout's prefix plus the table name by default; `indexableAs()` returns `searchableAs()` unless you override it, for example to write through an alias.

Create the index **before** importing records. Otherwise Elasticsearch creates it automatically on the first write, with dynamic mappings and without this package's analysis settings.

```bash
php artisan scout:index "App\Models\Post"     # create the index (left untouched if it exists)
php artisan scout:delete-index "App\Models\Post"
```

A new index gets these default analysis settings: a `default` analyzer using the standard tokenizer, the `html_strip` character filter, lowercasing and a `word_delimiter` filter that splits tokens on letter/number boundaries and inner punctuation such as dots, and also indexes the joined form (`iphone15` is indexed as `iphone`, `15` and `iphone15`; `wi.fi` as `wi`, `fi` and `wifi`). The standard tokenizer already splits words on hyphens, so `wi-fi` is indexed as `wi` and `fi` only.

### Mappings and custom settings

`scout:index` cannot pass mappings or settings to this engine, and `scout:sync-index-settings` is not supported. Call the engine yourself, for example in a deployment command or a migration:

```php
use App\Models\Post;

(new Post)->searchableUsing()->createIndex((new Post)->indexableAs(), [
    'mappings' => [
        'properties' => [
            'title' => ['type' => 'text'],
            'status' => ['type' => 'keyword'],
            'views' => ['type' => 'integer'],
            'published_at' => ['type' => 'date'],
        ],
    ],
]);
```

`createIndex()` accepts `settings`, `mappings` and `aliases`; other options are ignored.

- Passing `settings` **replaces** the default analysis settings instead of merging with them.
- When the index already exists, nothing is changed, not even its mappings. To change a mapping, delete the index, create it again and run `scout:import`.

The `scout:elasticsearch:create {model}` command from 0.4 is **deprecated** and will be removed in 2.0; use `scout:index` instead. It now behaves like `scout:index`: it creates the model's `indexableAs()` index with the default analysis settings, leaves an existing index untouched, and exits with a non-zero code when the class is not a searchable model or Elasticsearch returns an error.

## Models

Add Scout's `Searchable` trait as usual:

```php
use Illuminate\Database\Eloquent\Model;
use Laravel\Scout\Searchable;

class Post extends Model
{
    use Searchable;

    public function toSearchableArray(): array
    {
        return [
            'title' => $this->title,
            'body' => $this->body,
            'status' => $this->status,
            'views' => $this->views,
            'published_at' => $this->published_at,
        ];
    }

    /**
     * Optional: the fields searched by Post::search('text').
     */
    public function searchableFields(): array
    {
        return ['title^2', 'body'];
    }
}
```

- Documents use the model's Scout key (`getScoutKey()`, the primary key by default) as their `_id`. String and custom Scout keys are supported.
- `searchableFields()` is optional and specific to this package. It accepts Elasticsearch field syntax such as boosts (`title^2`) and wildcards (`meta.*`). Without it, every eligible field is searched (see [Searching](#searching)).
- A model whose `toSearchableArray()` returns an empty array is skipped when indexing.

## Indexing

Indexing works through Scout as usual: saving a model, `$model->searchable()`, `$models->searchable()`, `$model->unsearchable()`, `php artisan scout:import "App\Models\Post"` and `scout:flush`.

- Models are written with the bulk API, in requests of at most `ELASTIC_BULK_CHUNK_SIZE` documents. Each write replaces the whole document.
- Deleting a document that is already missing from the index counts as a success.
- `scout:flush` (`Post::removeAllFromSearch()`) deletes every document with `delete_by_query` but keeps the index with its settings and mappings. Flushing a missing index does nothing.

The bulk API answers HTTP 200 even when some operations fail, so the engine checks every operation. After all chunks are sent, failures are reported together with a `Shabayek\Elastic\Exceptions\BulkOperationException`:

```php
use Shabayek\Elastic\Exceptions\BulkOperationException;

try {
    $posts->searchable();
} catch (BulkOperationException $e) {
    $e->total();      // number of operations sent
    $e->failedIds();  // ['12', '57']
    $e->failures();   // [['operation' => 'index', 'index' => 'posts', 'id' => '12', 'status' => 400, 'type' => 'mapper_parsing_exception', 'reason' => '...'], ...]
}
```

With queued indexing (`SCOUT_QUEUE=true`) the exception fails the queued job, so the job can be retried.

## Soft deletes

With `'soft_delete' => true` in `config/scout.php`, soft-deleted models stay in the index with a `__soft_deleted` field set to `1`. Searches exclude them by default; use Scout's `withTrashed()` and `onlyTrashed()` to include them or to search only them:

```php
Post::search('lamp')->withTrashed()->get();
Post::search('lamp')->onlyTrashed()->get();
```

Without `soft_delete`, soft-deleting a model removes it from the index.

## Searching

```php
Post::search('desk lamp')->get();
```

The search text is matched with a `multi_match` query of type `phrase_prefix`:

- The words must appear in order, and the last word may be a prefix: `desk la` matches "desk lamp", but `lamp desk` does not.
- With `searchableFields()`, only those fields are searched. Without it, Elasticsearch searches the index's `index.query.default_field` (all eligible fields by default), and fields that cannot parse the text, such as numbers and dates, are skipped.
- An empty search (`Post::search('')`) matches every document; filters still apply.
- Hits are ordered by relevance (`_score`) unless you sort them.

To use another query type, replace the query with a [search callback](#search-callbacks).

## Filtering

`where()`, `whereIn()` and `whereNotIn()` filter without changing relevance scores.

```php
Post::search('lamp')
    ->where('status', 'published')
    ->where('views', '>=', 100)        // Scout 11
    ->whereIn('category_id', [1, 2, 3])
    ->whereNotIn('author_id', [9])
    ->get();
```

| Operator | Elasticsearch query |
| --- | --- |
| `where($field, $value)`, `'='` | `term` |
| `'!='`, `'<>'` | `term` inside `must_not` |
| `'>'`, `'>='`, `'<'`, `'<='` | `range` |
| `whereIn()` | `terms` (an empty list matches nothing) |
| `whereNotIn()` | `terms` inside `must_not` (an empty list excludes nothing) |

- Operators other than `=` require Scout 11; Scout 10's `where()` only accepts a field and a value. Any other operator throws an `InvalidArgumentException`.
- Backed enums are sent as their value, unit enums as their name, and dates as ISO 8601 strings.
- `null` is not accepted inside `whereIn()` or `whereNotIn()` values; use `where($field, null)` instead.
- `term` queries compare exact values. Filter on `keyword`, numeric, date or boolean fields. On an analyzed `text` field, `where('status', 'Published')` will not match, because the indexed tokens are lowercased. With dynamic mappings, filter on the `.keyword` sub-field (`where('status.keyword', 'Published')`) or map the field as `keyword`.

### Missing and null fields

Elasticsearch does not index `null` (unless the mapping sets `null_value`), so a field set to `null` behaves like a missing field:

| Constraint | Field has the value | Field has another value | Field missing or `null` |
| --- | --- | --- | --- |
| `where('status', 'archived')`, `whereIn('status', ['archived'])` | matches | — | — |
| `where('status', '!=', 'archived')`, `'<>'`, `whereNotIn('status', ['archived'])` | — | matches | matches |
| `where('status', null)` | — | — | matches |
| `where('status', '!=', null)` | matches | matches | — |

`!=`, `<>` and `whereNotIn()` are the exact complement of `=` and `whereIn()`, as with Scout's Meilisearch engine. To exclude documents without a value, add `->where('status', '!=', null)`. Comparison operators (`>`, `>=`, `<`, `<=`) never match missing or `null` fields and reject a `null` value.

## Sorting, limits, pagination and options

```php
Post::search('lamp')->orderBy('published_at', 'desc')->orderBy('views')->get();
Post::search('lamp')->take(20)->get();
Post::search('lamp')->paginate(15);
Post::search('lamp')->within('posts_archive')->get();
Post::search('lamp')->options(['min_score' => 0.5, 'highlight' => ['fields' => ['title' => new \stdClass]]])->raw();
```

- **Sorting**: `orderBy()` sorts in the given order; without it, hits are sorted by relevance. Sort on `keyword`, numeric or date fields: Elasticsearch cannot sort on `text` fields by default.
- **Limits**: `get()` returns at most `take()` hits, or **5,000** without `take()`. Use `cursor()` to read more.
- **Pagination**: `paginate()` and `simplePaginate()` use `from`/`size` and report an exact total. Elasticsearch rejects pages beyond `index.max_result_window` (10,000 hits by default); use `cursor()` to go further.
- **Within**: `within()` searches another index instead of the model's.
- **Options**: `options()` adds keys to the search request body, such as `min_score`, `highlight`, `_source`, `timeout` or `sort`.
  - `from`, `size` and `query` are managed by the engine and throw an `InvalidArgumentException`; use `take()`/`paginate()`, or a search callback to replace the query.
  - Use either `orderBy()` or the `sort` option, not both.

## Raw results and hit metadata

`raw()` returns the Elasticsearch response as an array. Use it for highlights, aggregations and anything else outside the models:

```php
$results = Post::search('lamp')->options(['highlight' => ['fields' => ['title' => new \stdClass]]])->raw();
```

Models returned by `get()`, `paginate()` and `cursor()` carry the hit metadata whose key starts with an underscore:

```php
$post->scoutMetadata()['_score'];
$post->scoutMetadata()['_index'];
```

Other hit data, such as `highlight`, is only available through `raw()` or a `withRawResults()` callback.

Scout's `query()` callback restricts and eager-loads the database query; results keep the Elasticsearch order, and hits without a matching database record are skipped.

## Search callbacks

A search callback receives the Elasticsearch client, the search text and the request parameters, and returns the raw results:

```php
use Elastic\Elasticsearch\Client;

Post::search('lamp', function (Client $client, string $query, array $params) {
    $params['body']['query'] = ['match' => ['title' => ['query' => $query, 'fuzziness' => 'AUTO']]];

    return $client->search($params);
})->get();
```

For `get()`, `paginate()`, `raw()` and `keys()`, the callback may change any parameter and may return an array or the client response. `cursor()` imposes a stricter contract, described below.

## Cursors

`cursor()` streams every matching model without loading them all, including result sets larger than `index.max_result_window`:

```php
Post::search('lamp')->where('status', 'published')->cursor()->each(function (Post $post) {
    // ...
});
```

- Hits are read page by page (`ELASTIC_CURSOR_BATCH_SIZE`) from a point in time with `search_after`. Each page is mapped to models separately.
- The order is relevance, `orderBy()` or the `sort` option, with a `_shard_doc` tiebreaker, so no hit is skipped or repeated. Results reflect the index as it was when iteration started.
- `take()`, `within()`, filters, `options()`, `query()` and `withRawResults()` callbacks apply to every page.
- The point in time is closed when iteration ends, fails or is stopped early. If closing fails, the error is reported to Laravel's exception handler instead of being thrown; the point in time then expires after `ELASTIC_CURSOR_KEEP_ALIVE`.
- Processing one page must take less than `ELASTIC_CURSOR_KEEP_ALIVE`, or the next page fails because the point in time expired.
- The `pit` and `search_after` options are managed by the engine and throw an `InvalidArgumentException`.

If a page comes back incomplete (failed shards, a timeout or `terminated_early`), the cursor throws `Shabayek\Elastic\Exceptions\IncompleteSearchResultsException` instead of silently skipping records. Models from earlier pages have already been yielded at that point.

### Search callbacks and cursors

`cursor()` pages through a point in time with `search_after`, so the callback runs once per page under a stricter contract:

- It may change the query and other options (for example `highlight` or `_source`).
- It must send the search through the given `$client`, as its last request, to `_search` without an index.
- It must send `pit`, `search_after`, `sort`, `size` and `from` in the body exactly as received, and must not add them to the query string.
- It must return the response of that last request, as returned by `$client->search()`: not an array, and not the response of another request.

```php
Post::search('lamp', function (Client $client, $query, array $params) {
    $params['body']['query'] = ['match' => ['title' => ['query' => $query, 'fuzziness' => 'AUTO']]];

    return $client->search($params);
})->cursor();
```

Use the `withRawResults()` callback to change the results of each page.

If the callback breaks this contract, the cursor throws a `LogicException` before using that page, and closes the point in time. Use `orderBy()` or the `sort` search option to choose the order.

The guard cannot detect one deliberate workaround: a callback that sends an unsafe search, then a valid search that fails without a response, catches that error and returns the first response.

## Limitations

- Mappings and custom settings cannot be passed through `scout:index`, and `scout:sync-index-settings` is not supported (see [Mappings and custom settings](#mappings-and-custom-settings)).
- Semantic and hybrid searches (Scout 11) are not supported and throw a `NotSupportedException`.
- `where()` operators beyond `=` require Scout 11.
- Client certificates (mutual TLS) are not configurable.
- The integration tests run against single-node servers with security disabled. Secured clusters (TLS, API keys, basic authentication), multi-node clusters, Elastic Cloud and timed-out searches are not covered by real-server tests (see [Integration tests](#integration-tests)).

## Upgrading from 0.4

Version 1.0 rewrites the engine for current Laravel, Scout and Elasticsearch versions and contains breaking changes. Read [UPGRADE.md](UPGRADE.md) before upgrading, and see [CHANGELOG.md](CHANGELOG.md) for the full list of changes.

## Testing

```bash
composer test      # PHPUnit
composer analyse   # Larastan (PHPStan level 5)
```

The unit test suite uses fake HTTP responses and needs no Elasticsearch server. Code style is checked by StyleCI.

### Integration tests

The `Integration` test suite (`tests/Integration`) runs the engine against a real Elasticsearch server over HTTP. It is opt-in: `composer test` never runs it. It needs Docker (or any reachable server) and the client major that matches the server.

| Server | Client | Start the server | `ELASTICSEARCH_URL` |
|---|---|---|---|
| Elasticsearch 8.19 | `elasticsearch/elasticsearch` ^8.16 | `docker compose up -d --wait elasticsearch8` | `http://localhost:9208` |
| Elasticsearch 9.5 | `elasticsearch/elasticsearch` ^9.0 | `docker compose up -d --wait elasticsearch9` | `http://localhost:9209` |

```bash
# Elasticsearch 9 (Composer installs the 9.x client by default)
docker compose up -d --wait elasticsearch9
ELASTICSEARCH_URL=http://localhost:9209 composer test:integration

# Elasticsearch 8: install the 8.x client first, and restore composer.json afterwards
composer require --no-update elasticsearch/elasticsearch:^8.16 && composer update
docker compose up -d --wait elasticsearch8
ELASTICSEARCH_URL=http://localhost:9208 composer test:integration

docker compose down
```

The containers in `docker-compose.yml` run a single node with security and the disk watermark check disabled; they are for testing only. For a secured server, also set `ELASTICSEARCH_USERNAME` and `ELASTICSEARCH_PASSWORD`, or `ELASTICSEARCH_API_KEY`, and `ELASTICSEARCH_CA_BUNDLE`. Without `ELASTICSEARCH_URL` every integration test fails instead of being skipped, and the suite also fails when the server and client majors differ.

Each test writes to indexes named `scout_it_<random>_*` and deletes them afterwards, even when it fails, so the suite can run against a shared development server.

Covered against a real server:

- **Indexes**: `createIndex()` with mappings and settings, the default analysis settings and their tokens, existing indexes left untouched, `deleteIndex()`, `scout:index`, `scout:delete-index` and `scout:elasticsearch:create`.
- **Indexing**: bulk indexing in chunks under the Scout key, updates on save, per-document mapping errors reported by `BulkOperationException` while the other documents are indexed, and bulk deletes, including models that were never indexed.
- **Searching**: full-text search and model hydration in relevance order, `where`, `whereIn`, `whereNotIn` and `where(field, null)` with null and missing fields, `orderBy`, `take`, `paginate` totals, `raw()`, `keys()` and `options()`.
- **Cursors**: every document exactly once, stable order across equal sort values, the point in time id returned by each page sent with the next, a consistent snapshot while documents are added, the point in time closed after completion, early stops and exceptions, and pages that terminated early or had failed shards rejected with `IncompleteSearchResultsException`.
- **Flush and deletes**: `scout:flush` deletes the model's documents with `delete_by_query` and keeps the index and other indexes, flushing a missing index succeeds, and `unsearchable()` on a query removes only the matching documents.
- **Soft deletes**: soft-deleted models stay indexed with `__soft_deleted`, normal searches and cursors exclude them, `withTrashed()` and `onlyTrashed()`, restore and force delete.

## License

The MIT License (MIT). See [LICENSE](LICENSE).
