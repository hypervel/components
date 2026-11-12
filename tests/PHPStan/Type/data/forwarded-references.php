<?php

declare(strict_types=1);

namespace ForwardedReferences;

use Hypervel\Database\Eloquent\Attributes\Scope;
use Hypervel\Database\Eloquent\Builder;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Eloquent\Relations\HasMany;

use function PHPStan\Testing\assertType;

/**
 * @template TModel of Model
 *
 * @extends Builder<TModel>
 */
class ReferenceBuilder extends Builder
{
    /**
     * @param-out string $value
     */
    public function mutate(mixed &$value): int
    {
        $value = 'changed';

        return 1;
    }

    /**
     * @param-out string $value
     * @return $this
     */
    public function mutateFluently(mixed &$value): static
    {
        $value = 'changed';

        return $this;
    }
}

class Item extends Model
{
    /**
     * @return ReferenceBuilder<static>
     */
    public function newEloquentBuilder($query): ReferenceBuilder
    {
        return new ReferenceBuilder($query);
    }

    /**
     * @return HasMany<Item, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(Item::class);
    }

    /**
     * @param Builder<static> $query
     * @param-out string $value
     */
    #[Scope]
    protected function counted(Builder $query, mixed &$value): void
    {
        $value = 'changed';
    }
}

function test(Item $item): void
{
    // Model, relation and scope calls forward argument values, so the caller's variable keeps its type.
    $value = 1;

    $item->mutate($value);
    assertType('1', $value);

    Item::mutate($value);
    assertType('1', $value);

    $item->children()->mutateFluently($value);
    assertType('1', $value);

    Item::counted($value);
    assertType('1', $value);

    // A builder's own method receives the variable.
    $item->newQuery()->mutate($value);
    assertType('string', $value);
}
