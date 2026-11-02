<?php

namespace Shabayek\Elastic\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Laravel\Scout\Searchable;

/**
 * Custom string primary key, and different read / write index names.
 */
class Product extends Model
{
    use Searchable;

    protected $primaryKey = 'sku';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = [];

    public $timestamps = false;

    public function searchableAs()
    {
        return 'products_read';
    }

    public function indexableAs()
    {
        return 'products_write';
    }

    public function searchableFields()
    {
        return ['name'];
    }

    public function toSearchableArray()
    {
        return ['name' => $this->name];
    }
}
