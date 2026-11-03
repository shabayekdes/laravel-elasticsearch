<?php

namespace Shabayek\Elastic\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Laravel\Scout\Searchable;

class Article extends Model
{
    use Searchable;
    use SoftDeletes;

    protected $guarded = [];

    public $timestamps = false;

    public function searchableAs()
    {
        return 'articles';
    }

    public function searchableFields()
    {
        return ['title'];
    }

    public function toSearchableArray()
    {
        return ['title' => $this->title];
    }
}
