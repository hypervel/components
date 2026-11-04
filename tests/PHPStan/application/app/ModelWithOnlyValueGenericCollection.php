<?php

namespace App;

use Hypervel\Database\Eloquent\Model;

class ModelWithOnlyValueGenericCollection extends Model
{
    /**
     * @template TModel
     *
     * @param  TModel[]  $models
     * @return OnlyValueGenericCollection<TModel>
     */
    public function newCollection(array $models = []): OnlyValueGenericCollection
    {
        return new OnlyValueGenericCollection($models);
    }
}
