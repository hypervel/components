<?php

declare(strict_types=1);

namespace RelationExistenceBareRelations;

use App\BareRelations\Owner;
use App\BareRelations\Shelf;
use App\User;
use Hypervel\Database\Eloquent\Builder;

Owner::query()->whereHas('items.category', static function (Builder $query): void {
    $query->whereHas('labels', static function (Builder $query): void {
        $query->whereKey(1);
    });
});

Owner::query()->whereHas('items', static function (Builder $query): void {
    $query->whereHas('category');
});

User::query()->whereHas('posts.comments', static function (Builder $query): void {
    $query->whereHas('missing');
});

// Hypervel: a method without a declared return type may be a relation of unknown type, which ends the path.
Shelf::query()->with(['labels', 'labels.anything', 'owner.anything'])->whereHas('labels');
Shelf::query()->with(['labelsOfType', 'labelsOfKind', 'title', 'getKey', 'where']);
