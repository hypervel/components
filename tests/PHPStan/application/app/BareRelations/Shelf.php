<?php

namespace App\BareRelations;

use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Eloquent\Relations\HasMany;

/** Relations here are deliberately left without a declared relation return type, or need arguments. */
class Shelf extends Model
{
    public function labels()
    {
        return $this->hasMany(Label::class);
    }

    public function labelsOfType($type)
    {
        return $this->hasMany(Label::class)->where('type', $type);
    }

    /** @return HasMany<Label, $this> */
    public function labelsOfKind(string $kind): HasMany
    {
        return $this->hasMany(Label::class)->where('kind', $kind);
    }

    public function owner(): object
    {
        return $this->belongsTo(Owner::class);
    }

    public function title(): string
    {
        return 'Shelf';
    }
}
