<?php

namespace App;

use Hypervel\Database\Eloquent\Model;

class ModelWithNonGenericCollection extends Model
{
    /**
     * @param  ModelWithNonGenericCollection[]  $models
     */
    public function newCollection(array $models = []): NonGenericCollection
    {
        return new NonGenericCollection($models);
    }
}
