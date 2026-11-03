<?php

namespace Shabayek\Elastic\Tests\Integration\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Laravel\Scout\Searchable;

/**
 * Uses Scout's default index name (prefix plus table), so every test gets its
 * own index through a unique scout.prefix.
 */
class Book extends Model
{
    use Searchable;

    protected $guarded = [];

    public $timestamps = false;

    public function searchableFields()
    {
        return ['title', 'author'];
    }

    public function toSearchableArray()
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'author' => $this->author,
            'status' => $this->status,
            'views' => $this->views,
            'published_on' => $this->published_on,
        ];
    }
}
