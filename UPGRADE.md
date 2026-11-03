# Upgrade Guide

## Upgrading to 1.0 from 0.4

Version 1.0 rewrites the engine for current Laravel, Scout and Elasticsearch versions. Most applications only need the dependency and configuration steps, but read every section: several behaviours changed.

### Requirements

| | 0.4 | 1.0 |
|---|---|---|
| PHP | 7.3+ | 8.2+ (8.3+ for Laravel 13) |
| Laravel | 8 | 12 or 13 |
| Laravel Scout | 9 | 10.24+ or 11 |
| Elasticsearch PHP client | 7.17 | 8.16+ or 9.x |
| Elasticsearch server | 7.x | 8.16+ or 9.x |

Use the client major that matches your server: `elasticsearch/elasticsearch` 8.x for Elasticsearch 8 and 9.x for Elasticsearch 9. Composer installs the 9.x client by default, so on an Elasticsearch 8 cluster run:

```bash
composer require shabayek/laravel-elasticsearch:^1.0 elasticsearch/elasticsearch:^8.16
```

Elasticsearch 7 servers are not supported. Upgrade the cluster first.

### Configuration

Compare your published `config/elasticsearch.php` with the new one, or publish it again:

```bash
php artisan vendor:publish --tag=elasticsearch-config --force
```

- **No default credentials.** The `elastic`/`elastic` username and password defaults are gone. Basic authentication is used only when both `ELASTIC_USERNAME` and `ELASTIC_PASSWORD` are set. If your cluster relied on the defaults, set both variables.
- **New options:** `ELASTIC_HOSTS`, `ELASTIC_SCHEME`, `ELASTIC_API_KEY`, `ELASTIC_CLOUD_ID`, `ELASTIC_SSL_VERIFICATION`, `ELASTIC_CA_BUNDLE`, `ELASTIC_BULK_CHUNK_SIZE`, `ELASTIC_REFRESH`, `ELASTIC_CURSOR_BATCH_SIZE` and `ELASTIC_CURSOR_KEEP_ALIVE`. `ELASTIC_HOST` and `ELASTIC_PORT` still work.
- **Validation:** an invalid chunk size, refresh value, cursor batch size or keep alive throws an `InvalidArgumentException` naming the option and environment variable. `ELASTIC_REFRESH` accepts `true`, `false` or `wait_for`; `1` and `0` are rejected.

### Using the client directly

The container binding `elasticsearch` still exists and now resolves `Elastic\Elasticsearch\Client` (the 8.x/9.x client) instead of `Elasticsearch\Client` (7.x). Code that calls the client directly must follow the client's own upgrade notes:

- Type hint `Elastic\Elasticsearch\Client` instead of `Elasticsearch\Client`.
- Requests return an `Elastic\Elasticsearch\Response\Elasticsearch` response instead of an array. Array access still works; call `asArray()` when you need a real array.
- Errors are thrown as `Elastic\Elasticsearch\Exception\ClientResponseException` (4xx, including 404) and `ServerResponseException` (5xx) instead of the `Elasticsearch\Common\Exceptions\*` classes.

`Model::search()->raw()` still returns an array.

### Indexes and documents

- **Document ids:** documents are stored under the model's Scout key (`getScoutKey()`) with Scout metadata, and written to `indexableAs()`. 0.4 used `$model->id` and `searchableAs()`. If you customised `getScoutKey()` or `indexableAs()`, re-import your models:

  ```bash
  php artisan scout:flush "App\Models\Post"
  php artisan scout:import "App\Models\Post"
  ```

- **Creating indexes:** `scout:index` and `scout:delete-index` now work. Create the index before importing; otherwise Elasticsearch creates it on the first write without this package's analysis settings. Mappings are passed through the engine's `createIndex()` (see the README).
- **Flush:** `scout:flush` deletes the documents with `delete_by_query` and keeps the index, its settings and mappings. 0.4 deleted the index and created it again.
- **Bulk writes:** models are indexed and deleted through the bulk API in chunks of `ELASTIC_BULK_CHUNK_SIZE`. Failed operations throw `Shabayek\Elastic\Exceptions\BulkOperationException` after every chunk was sent; 0.4 sent one request per model.

### The `scout:elasticsearch:create` command

The command is **deprecated** and will be removed in 2.0. Replace it with:

```bash
php artisan scout:index "App\Models\Post"
```

Its behaviour changed:

- It creates the model's `indexableAs()` index (0.4 used `searchableAs()`; both are equal unless you override `indexableAs()`).
- An existing index is left untouched and the command succeeds, as `scout:index` does. 0.4 printed Elasticsearch's error.
- It exits with code 1 when the class does not exist, is not a searchable Eloquent model, or Elasticsearch returns an error. 0.4 always exited with code 0, so deploy scripts that ignored failures will now stop.
- It prints a deprecation notice.
- The protected `analyzers()` and `filters()` methods and the `$client` property were removed. The analysis settings now come from the engine. If you extended the command to change them, call the engine's `createIndex()` with your own `settings` from your own command instead.

### Searching

- **Result order:** results are returned in relevance order (or the `orderBy()` order). 0.4 returned them in database order.
- **Totals:** totals are exact beyond 10,000 hits.
- **`searchableFields()`** is optional. Without it, the query searches the index's default fields.
- **Filters:** `where()` constraints no longer affect relevance scores. `whereNotIn()` is applied (0.4 ignored it). With Scout 11, `where()` operators (`!=`, `<>`, `>`, `>=`, `<`, `<=`) are supported. `!=`, `<>` and `whereNotIn()` also match documents where the field is missing or null.
- **Search callbacks** passed to `Model::search($query, $callback)` were ignored by 0.4 and are now called. Remove callbacks that were left in place but never ran, or make sure they work.
- **Options:** `options()` entries are merged into the request body. `from`, `size` and `query` are rejected, and a `sort` option cannot be combined with `orderBy()`.
- **Cursors:** `cursor()` streams every hit with a point in time and `search_after` (it did not work in 0.4). Incomplete pages (timed out, terminated early or with failed shards) throw `Shabayek\Elastic\Exceptions\IncompleteSearchResultsException`. Cursor search callbacks may not change `pit`, `search_after`, `sort`, `size` or `from`, and must return the client response of their last request (see the README).
- **Hybrid and semantic searches** (Scout 11) throw `NotSupportedException`.

### Internal classes

`Shabayek\Elastic\Builders\SearchBuilder` is now marked `@internal`. It may change in any release. Customise searches through Scout's builder, `options()` or a search callback instead.
