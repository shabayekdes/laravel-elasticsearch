<?php

namespace Shabayek\Elastic\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Laravel\Scout\Searchable;

class Post extends Model
{
    use Searchable;

    protected $guarded = [];

    public $timestamps = false;

    public function searchableAs()
    {
        return 'posts';
    }

    public function searchableFields()
    {
        return ['title'];
    }

    public function toSearchableArray()
    {
        return ['id' => $this->id, 'title' => $this->title];
    }
}
