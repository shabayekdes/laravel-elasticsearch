# Laravel ElasticSearch Scout Engine

[![Github Status](https://github.com/shabayekdes/laravel-elasticsearch/actions/workflows/tests.yml/badge.svg)](https://github.com/shabayekdes/laravel-elasticsearch/actions) [![Styleci Status](https://github.styleci.io/repos/476024226/shield?branch=main)](https://github.styleci.io/repos/476024226) [![Packagist version](https://img.shields.io/packagist/v/shabayek/laravel-elasticsearch)](https://packagist.org/packages/shabayek/laravel-elasticsearch) [![mit](https://img.shields.io/apm/l/laravel)](https://packagist.org/packages/shabayek/laravel-elasticsearch) ![Packagist PHP Version Support](https://img.shields.io/packagist/php-v/shabayek/laravel-elasticsearch) ![Packagist Downloads](https://img.shields.io/packagist/dt/shabayek/laravel-elasticsearch)

This is a Laravel Package for elasticsearch scout engine.

### Requirements

- PHP 8.1+
- Laravel 10, 11, 12 or 13 with Laravel Scout 10.13+ or 11
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
