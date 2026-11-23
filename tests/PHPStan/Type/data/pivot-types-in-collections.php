<?php

namespace PivotTypesInCollections;

use App\Account;
use App\Post;
use App\User;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Eloquent\Relations\BelongsToMany;
use Hypervel\Database\Eloquent\Relations\Pivot;

use function PHPStan\Testing\assertType;

class Subscription extends Pivot
{
}

class Podcast extends Model
{
}

class Member extends Model
{
    /** @return BelongsToMany<Podcast, $this, Subscription, 'subscription'> */
    public function podcasts(): BelongsToMany
    {
        return $this->belongsToMany(Podcast::class)->as('subscription')->using(Subscription::class);
    }
}

function test(User $user, Post $post, Member $member, User|Account $union): void
{
    assertType('App\RoleCollection<int, App\Role&object{pivot: Hypervel\Database\Eloquent\Relations\Pivot}>', $user->roles);
    assertType('Hypervel\Database\Eloquent\Relations\Pivot', $user->roles->firstOrFail()->pivot);
    assertType('Hypervel\Database\Eloquent\Collection<int, App\Post&object{pivot: Hypervel\Database\Eloquent\Relations\Pivot}>', $user->posts);
    assertType('Hypervel\Database\Eloquent\Collection<int, App\Post&object{pivot: Hypervel\Database\Eloquent\Relations\Pivot}>', $union->posts);
    assertType('Hypervel\Database\Eloquent\Collection<int, App\Tag&object{pivot: Hypervel\Database\Eloquent\Relations\MorphPivot}>', $post->tags);
    assertType('App\RoleCollection<int, App\Role&object{pivot: Hypervel\Database\Eloquent\Relations\Pivot}>', $user->roles()->findMany([1, 2]));
    assertType('App\RoleCollection<int, App\Role&object{pivot: Hypervel\Database\Eloquent\Relations\Pivot}>', $user->roles()->get());
    assertType('App\RoleCollection<int, App\Role&object{pivot: Hypervel\Database\Eloquent\Relations\Pivot}>', $user->roles()->where('name', 'admin')->get(['name']));
    assertType('App\RoleCollection<int, App\Role&object{pivot: Hypervel\Database\Eloquent\Relations\Pivot}>', $user->roles()->getResults());

    assertType('Hypervel\Database\Eloquent\Collection<int, object{subscription: PivotTypesInCollections\Subscription}&PivotTypesInCollections\Podcast>', $member->podcasts);
    assertType('PivotTypesInCollections\Subscription', $member->podcasts->firstOrFail()->subscription);
    assertType('Hypervel\Database\Eloquent\Collection<int, object{subscription: PivotTypesInCollections\Subscription}&PivotTypesInCollections\Podcast>', $member->podcasts()->findMany([1, 2]));
    assertType('Hypervel\Database\Eloquent\Collection<int, object{subscription: PivotTypesInCollections\Subscription}&PivotTypesInCollections\Podcast>', $member->podcasts()->get());
    assertType('Hypervel\Database\Eloquent\Collection<int, object{subscription: PivotTypesInCollections\Subscription}&PivotTypesInCollections\Podcast>', $member->podcasts()->getResults());

    // Relations without a pivot are unaffected.
    assertType('App\AccountCollection<int, App\Account>', $user->accounts);
}

/**
 * @template TModel of Model
 * @param  BelongsToMany<TModel, Member>  $relation
 */
function testTemplate(BelongsToMany $relation): void
{
    assertType('Hypervel\Database\Eloquent\Collection<int, object{pivot: Hypervel\Database\Eloquent\Relations\Pivot}&TModel of Hypervel\Database\Eloquent\Model (function PivotTypesInCollections\testTemplate(), argument)>', $relation->get());
    assertType('Hypervel\Database\Eloquent\Collection<int, object{pivot: Hypervel\Database\Eloquent\Relations\Pivot}&TModel of Hypervel\Database\Eloquent\Model (function PivotTypesInCollections\testTemplate(), argument)>', $relation->getResults());
    assertType('Hypervel\Database\Eloquent\Collection<int, object{pivot: Hypervel\Database\Eloquent\Relations\Pivot}&TModel of Hypervel\Database\Eloquent\Model (function PivotTypesInCollections\testTemplate(), argument)>', $relation->findMany([1, 2]));
}
