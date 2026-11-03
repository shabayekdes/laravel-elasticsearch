<?php

namespace Shabayek\Elastic\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Laravel\Scout\Searchable;

/**
 * A model without a searchableFields() method.
 */
class Comment extends Model
{
    use Searchable;

    protected $guarded = [];

    public $timestamps = false;

    public function searchableAs()
    {
        return 'comments';
    }
}
