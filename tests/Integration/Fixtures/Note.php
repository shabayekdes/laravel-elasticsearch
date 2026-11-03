<?php

namespace Shabayek\Elastic\Tests\Integration\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Laravel\Scout\Searchable;

class Note extends Model
{
    use Searchable;
    use SoftDeletes;

    protected $guarded = [];

    public $timestamps = false;

    public function searchableFields()
    {
        return ['title'];
    }

    public function toSearchableArray()
    {
        return ['id' => $this->id, 'title' => $this->title];
    }
}
