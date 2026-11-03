<?php

namespace Shabayek\Elastic\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Laravel\Scout\Searchable;

/**
 * Scout key ("slug") that differs from the primary key ("id").
 */
class Document extends Model
{
    use Searchable;

    protected $guarded = [];

    public $timestamps = false;

    public function searchableAs()
    {
        return 'documents';
    }

    public function searchableFields()
    {
        return ['title'];
    }

    public function getScoutKey()
    {
        return $this->slug;
    }

    public function getScoutKeyName()
    {
        return 'slug';
    }

    public function getScoutKeyType()
    {
        return 'string';
    }

    public function toSearchableArray()
    {
        return $this->title === null ? [] : ['title' => $this->title];
    }
}
