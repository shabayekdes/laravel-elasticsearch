# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [1.0.0] - Unreleased

Version 1.0 rewrites the engine for current Laravel, Scout and Elasticsearch versions. It contains breaking changes; see [UPGRADE.md](UPGRADE.md).

### Added

- Support for Elasticsearch 8.16+ and 9.x through the matching `elasticsearch/elasticsearch` 8.x or 9.x client.
- Connection options for multiple hosts, the URL scheme, API keys, Elastic Cloud IDs, SSL verification and a CA bundle, all settable through environment variables.
- `Shabayek\Elastic\Connection\ClientFactory`, which builds the client from the configuration and can be replaced in the container.
- `createIndex()` with `settings`, `mappings` and `aliases`, and `deleteIndex()`, so `scout:index` and `scout:delete-index` work. An existing index is left untouched.
- Bulk indexing and deletion in configurable chunks (`ELASTIC_BULK_CHUNK_SIZE`), with `BulkOperationException` reporting every failed operation.
- A `refresh` option (`ELASTIC_REFRESH`) for bulk and delete-by-query requests.
- Scout 11 `where()` operators (`=`, `!=`, `<>`, `>`, `>=`, `<`, `<=`), `whereIn()` and `whereNotIn()` filters, and defined semantics for missing and null fields.
- `orderBy()`, `take()` and `within()` support.
- `options()` entries merged into the search request, and search callbacks that receive the client, the query and the built request.
- `cursor()` streaming of every hit through a point in time and `search_after`, configurable with `ELASTIC_CURSOR_BATCH_SIZE` and `ELASTIC_CURSOR_KEEP_ALIVE`.
- `IncompleteSearchResultsException` for cursor pages that timed out, terminated early or had failed shards; the point in time is closed on every failure.
- Hit metadata (`_score`, `_index` and the other `_`-prefixed hit fields) on mapped models through `scoutMetadata()`, plus `lazyMap()` and `mapIdsFrom()`.
- Larastan (PHPStan level 5) static analysis, run with `composer analyse`.
- An opt-in integration test suite that runs against real Elasticsearch 8 and 9 servers (`composer test:integration`, `docker-compose.yml`).
- `CHANGELOG.md` and `UPGRADE.md`.

### Changed

- Requires PHP 8.2+, Laravel 12 or 13 and Laravel Scout 10.24+ or 11.
- The `elasticsearch` container binding resolves `Elastic\Elasticsearch\Client` (8.x/9.x) instead of `Elasticsearch\Client` (7.x).
- Basic authentication is used only when both a username and a password are configured; the `elastic`/`elastic` defaults were removed.
- Documents are indexed under the model's Scout key with Scout metadata, and written to `indexableAs()` instead of `searchableAs()` with `$model->id`.
- Results are returned in relevance order (or the `orderBy()` order) instead of database order, and totals are exact beyond 10,000 hits.
- `where()` filters no longer affect relevance scores.
- `searchableFields()` is optional.
- `scout:flush` deletes documents with `delete_by_query` and keeps the index instead of deleting and creating it again.
- `scout:elasticsearch:create` delegates to the engine's `createIndex()`, creates the `indexableAs()` index, leaves an existing index untouched, validates the model class and returns a non-zero exit code on failure.
- Invalid engine settings throw an `InvalidArgumentException` naming the configuration option and environment variable.
- `Shabayek\Elastic\Builders\SearchBuilder` is marked `@internal`.

### Deprecated

- The `scout:elasticsearch:create` command. Use `scout:index`; the command will be removed in 2.0.

### Removed

- Support for PHP below 8.2, Laravel below 12, Scout below 10.24 and the Elasticsearch 7.x client.
- The protected `analyzers()` and `filters()` methods and the `$client` property of the `scout:elasticsearch:create` command.

### Fixed

- `whereNotIn()` constraints were ignored.
- `cursor()` did not return results.
- Searching for the query `"0"` ran an empty search.
- `scout:elasticsearch:create` instantiated a missing class after reporting it and exited with code 0 on failure.

## [0.4] - 2022

Last release of the 0.x series, for PHP 7.3+, Laravel 8, Scout 9 and the Elasticsearch 7.x client.

[Unreleased]: https://github.com/shabayekdes/laravel-elasticsearch/compare/v0.4...HEAD
[1.0.0]: https://github.com/shabayekdes/laravel-elasticsearch/compare/v0.4...HEAD
[0.4]: https://github.com/shabayekdes/laravel-elasticsearch/releases/tag/v0.4
