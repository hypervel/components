<?php

namespace BelongsToManyPivotAccessor;

use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Eloquent\Relations\BelongsToMany;
use Hypervel\Database\Eloquent\Relations\MorphPivot;
use Hypervel\Database\Eloquent\Relations\MorphToMany;
use Hypervel\Database\Eloquent\Relations\Pivot;

use function PHPStan\Testing\assertType;

class Subscription extends Pivot
{
}

class Podcast extends Model
{
}

class Tag extends Model
{
}

class Member extends Model
{
    /** @return BelongsToMany<Podcast, $this, Subscription, 'subscription'> */
    public function podcasts(): BelongsToMany
    {
        return $this->belongsToMany(Podcast::class)->as('subscription')->using(Subscription::class);
    }

    /** @return BelongsToMany<Podcast, $this> */
    public function defaultPodcasts(): BelongsToMany
    {
        return $this->belongsToMany(Podcast::class);
    }

    /** @return MorphToMany<Tag, $this, MorphPivot, 'tagging'> */
    public function tags(): MorphToMany
    {
        return $this->morphToMany(Tag::class, 'taggable')->as('tagging');
    }
}

function test(Member $member, string $name, bool $condition): void
{
    assertType('(BelongsToManyPivotAccessor\Podcast&object{subscription: BelongsToManyPivotAccessor\Subscription})|null', $member->podcasts()->first());
    assertType('BelongsToManyPivotAccessor\Subscription', $member->podcasts()->firstOrFail()->subscription);
    assertType('BelongsToManyPivotAccessor\Podcast&object{subscription: BelongsToManyPivotAccessor\Subscription}', $member->podcasts()->findOrFail(1));
    assertType('Hypervel\Pagination\LengthAwarePaginator<int, BelongsToManyPivotAccessor\Podcast&object{subscription: BelongsToManyPivotAccessor\Subscription}, Hypervel\Database\Eloquent\Collection<int, BelongsToManyPivotAccessor\Podcast&object{subscription: BelongsToManyPivotAccessor\Subscription}>>', $member->podcasts()->paginate());
    assertType('Hypervel\Support\LazyCollection<int, BelongsToManyPivotAccessor\Podcast&object{subscription: BelongsToManyPivotAccessor\Subscription}>', $member->podcasts()->cursor());

    $member->podcasts()->each(function ($podcast) {
        assertType('BelongsToManyPivotAccessor\Podcast&object{subscription: BelongsToManyPivotAccessor\Subscription}', $podcast);
    });

    $member->podcasts()->chunk(10, function ($podcasts) {
        assertType('Hypervel\Database\Eloquent\Collection<int, BelongsToManyPivotAccessor\Podcast&object{subscription: BelongsToManyPivotAccessor\Subscription}>', $podcasts);
    });

    assertType('(BelongsToManyPivotAccessor\Podcast&object{subscription: BelongsToManyPivotAccessor\Subscription})|null', $member->belongsToMany(Podcast::class, 'a')->as('subscription')->using(Subscription::class)->first());
    assertType('(BelongsToManyPivotAccessor\Podcast&object{subscription: Hypervel\Database\Eloquent\Relations\Pivot})|null', $member->belongsToMany(Podcast::class, 'b')->as('subscription')->first());
    assertType('(BelongsToManyPivotAccessor\Podcast&object{pivot: Hypervel\Database\Eloquent\Relations\Pivot})|null', $member->defaultPodcasts()->first());
    assertType('(BelongsToManyPivotAccessor\Tag&object{tagging: Hypervel\Database\Eloquent\Relations\MorphPivot})|null', $member->tags()->first());

    // Without a single known accessor, no pivot property can be promised.
    assertType('BelongsToManyPivotAccessor\Podcast|null', $member->belongsToMany(Podcast::class, 'c')->as($name)->first());
    assertType('BelongsToManyPivotAccessor\Podcast|null', $member->belongsToMany(Podcast::class, 'd')->as($condition ? 'a' : 'b')->first());
}

/**
 * @template TModel of Model
 * @param  BelongsToMany<TModel, Member>  $relation
 */
function testTemplate(BelongsToMany $relation): void
{
    // Unlike upstream, collections keep the pivot; see pivot-types-in-collections.php.
    assertType('Hypervel\Database\Eloquent\Collection<int, object{pivot: Hypervel\Database\Eloquent\Relations\Pivot}&TModel of Hypervel\Database\Eloquent\Model (function BelongsToManyPivotAccessor\testTemplate(), argument)>', $relation->get());
    assertType('Hypervel\Database\Eloquent\Collection<int, object{pivot: Hypervel\Database\Eloquent\Relations\Pivot}&TModel of Hypervel\Database\Eloquent\Model (function BelongsToManyPivotAccessor\testTemplate(), argument)>', $relation->getResults());
    assertType('Hypervel\Database\Eloquent\Collection<int, object{pivot: Hypervel\Database\Eloquent\Relations\Pivot}&TModel of Hypervel\Database\Eloquent\Model (function BelongsToManyPivotAccessor\testTemplate(), argument)>', $relation->findMany([1, 2]));
}
