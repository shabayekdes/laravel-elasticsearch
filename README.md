# Laravel ElasticSearch Scout Engine

[![Github Status](https://github.com/shabayekdes/laravel-elasticsearch/actions/workflows/tests.yml/badge.svg)](https://github.com/shabayekdes/laravel-elasticsearch/actions) [![Styleci Status](https://github.styleci.io/repos/476024226/shield?branch=main)](https://github.styleci.io/repos/476024226) [![Packagist version](https://img.shields.io/packagist/v/shabayek/laravel-elasticsearch)](https://packagist.org/packages/shabayek/laravel-elasticsearch) [![mit](https://img.shields.io/apm/l/laravel)](https://packagist.org/packages/shabayek/laravel-elasticsearch) ![Packagist PHP Version Support](https://img.shields.io/packagist/php-v/shabayek/laravel-elasticsearch) ![Packagist Downloads](https://img.shields.io/packagist/dt/shabayek/laravel-elasticsearch)

This is a Laravel Package for elasticsearch scout engine.

### Requirements

- PHP 8.2+ (Laravel 13 requires PHP 8.3+)
- Laravel 12 or 13 with Laravel Scout 10.24+ or 11
- Elasticsearch 8.x or 9.x, you can install with docker OR in your machine from link [HERE](https://www.elastic.co/guide/en/elasticsearch/reference/current/install-elasticsearch.html).

### Usage

- Install laravel elasticsearch package with composer

```bash
composer require shabayek/laravel-elasticsearch
```

- Publish the config file with following command

```bash
php artisan vendor:publish --provider="Shabayek\Elastic\Providers\ElasticServiceProvider" --tag=elasticsearch-config
```

### Filtering

`where()`, `whereIn()` and `whereNotIn()` filter without changing relevance scores. Elasticsearch does not index `null` (unless the mapping sets `null_value`), so a field set to `null` behaves like a missing field:

| Constraint | Field has the value | Field has another value | Field missing or `null` |
| --- | --- | --- | --- |
| `where('status', 'archived')`, `whereIn('status', ['archived'])` | matches | — | — |
| `where('status', '!=', 'archived')`, `'<>'`, `whereNotIn('status', ['archived'])` | — | matches | matches |
| `where('status', null)` | — | — | matches |
| `where('status', '!=', null)` | matches | matches | — |

`!=`, `<>` and `whereNotIn()` are the exact complement of `=` and `whereIn()`, as with Scout's Meilisearch engine. To exclude documents without a value, add `->where('status', '!=', null)`. Comparison operators (`>`, `>=`, `<`, `<=`) never match missing or `null` fields and reject a `null` value.
