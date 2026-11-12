<?php

declare(strict_types=1);

namespace CustomQueryCollections;

use Hypervel\Database\Eloquent\Attributes\CollectedBy;
use Hypervel\Database\Eloquent\Builder;
use Hypervel\Database\Eloquent\Collection;
use Hypervel\Database\Eloquent\HasBuilder;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Eloquent\Relations\HasMany;

use function PHPStan\Testing\assertType;

/**
 * Framework builder and relation methods return the model's collection; custom overrides keep their declarations.
 *
 * @param MemberBuilder<Member> $query
 */
function test(MemberBuilder $query, Member $member): void
{
    assertType('CustomQueryCollections\Members<int, CustomQueryCollections\Member>', $query->get());
    assertType('Hypervel\Database\Eloquent\Collection<int, CustomQueryCollections\Member>', $query->hydrate([]));

    assertType('CustomQueryCollections\Members<int, CustomQueryCollections\Member>', $member->children()->get());
    assertType('Hypervel\Database\Eloquent\Collection<int, CustomQueryCollections\Member>', $member->children()->hydrate([]));

    assertType('Hypervel\Database\Eloquent\Collection<int, CustomQueryCollections\Member>', $member->friends()->get());
    assertType('CustomQueryCollections\Members<int, CustomQueryCollections\Member>', $member->friends()->getResults());
}

/**
 * The legacy PHPDoc union of the collection and an array of models reads as the generic collection.
 *
 * @param Collection|Member[] $members
 */
function legacyCollectionUnion(Collection $members): void
{
    assertType('Hypervel\Database\Eloquent\Collection<int, CustomQueryCollections\Member>', $members);
}

#[CollectedBy(Members::class)]
class Member extends Model
{
    /** @use HasBuilder<MemberBuilder<static>> */
    use HasBuilder;

    protected static string $builder = MemberBuilder::class;

    /** @return HasMany<Member, $this> */
    public function children(): HasMany
    {
        return $this->hasMany(Member::class);
    }

    /** @return Friends<Member, $this> */
    public function friends(): Friends
    {
        return new Friends(Member::query(), $this, 'friend_id', 'id');
    }
}

/**
 * @template TKey of array-key
 * @template TModel of Model
 *
 * @extends Collection<TKey, TModel>
 */
class Members extends Collection
{
}

/**
 * @template TModel of Model
 *
 * @extends Builder<TModel>
 */
class MemberBuilder extends Builder
{
    /**
     * Hydrate rows into a plain collection.
     *
     * @return Collection<int, TModel>
     */
    public function hydrate(array $items): Collection
    {
        return new Collection;
    }
}

/**
 * @template TRelatedModel of Model
 * @template TDeclaringModel of Model
 *
 * @extends HasMany<TRelatedModel, TDeclaringModel>
 */
class Friends extends HasMany
{
    /**
     * Get the friends as a plain collection.
     *
     * @return Collection<int, TRelatedModel>
     */
    public function get(array|string $columns = ['*']): Collection
    {
        return new Collection;
    }
}
