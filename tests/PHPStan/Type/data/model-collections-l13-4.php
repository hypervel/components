<?php

namespace ModelCollectionsL134;

use Hypervel\Database\Eloquent\Attributes\CollectedBy;
use Hypervel\Database\Eloquent\Collection;
use Hypervel\Database\Eloquent\HasCollection;
use Hypervel\Database\Eloquent\Model;
use function PHPStan\Testing\assertType;

function test(): void
{
    assertType('ModelCollectionsL134\BaseCollection<int, ModelCollectionsL134\User>', User::all());
    assertType('ModelCollectionsL134\BaseCollection<int, ModelCollectionsL134\User>', User::query()->where('id', '>', 1)->get());
    assertType('ModelCollectionsL134\BaseCollection<int, ModelCollectionsL134\Admin>', Admin::all());
    assertType('ModelCollectionsL134\TeamCollection', Team::all());
    // Hypervel: the attribute is instantiated, so its named argument applies to the model and its child.
    assertType('ModelCollectionsL134\TeamCollection', NamedArgumentParent::all());
    assertType('ModelCollectionsL134\TeamCollection', NamedArgumentChild::all());
    assertType('ModelCollectionsL134\StringCollection<int, ModelCollectionsL134\StringChild>', StringChild::all());
    assertType('ModelCollectionsL134\StringCollection<int, ModelCollectionsL134\LeadingBackslashString>', LeadingBackslashString::all());
    assertType('ModelCollectionsL134\BaseCollection<int, ModelCollectionsL134\TraitParentChild>', TraitParentChild::all());
    assertType('ModelCollectionsL134\BaseCollection<int, ModelCollectionsL134\TraitChild>', TraitChild::all());
    // Hypervel: without the attribute, HasCollection uses the static $collectionClass, which a child inherits.
    assertType('ModelCollectionsL134\StringCollection<int, ModelCollectionsL134\ClassCollectionParent>', ClassCollectionParent::all());
    assertType('ModelCollectionsL134\StringCollection<int, ModelCollectionsL134\ClassCollectionChild>', ClassCollectionChild::all());
    assertType('ModelCollectionsL134\BaseCollection<int, ModelCollectionsL134\AttributeOverClassCollection>', AttributeOverClassCollection::all());
}

#[CollectedBy(BaseCollection::class)]
abstract class BaseModel extends Model
{

}

class User extends BaseModel
{

}

class Admin extends User
{

}

class Team extends BaseModel
{
    /** @param array<int, static> $models */
    public function newCollection(array $models = []): TeamCollection
    {
        return new TeamCollection($models);
    }
}

/** Laravel only reads a positional argument; Hypervel instantiates the attribute, so this one applies. */
#[CollectedBy(collectionClass: TeamCollection::class)]
class NamedArgumentParent extends BaseModel
{

}

class NamedArgumentChild extends NamedArgumentParent
{

}

#[CollectedBy('ModelCollectionsL134\StringCollection')]
class StringParent extends Model
{

}

class StringChild extends StringParent
{

}

#[CollectedBy('\ModelCollectionsL134\StringCollection')]
class LeadingBackslashString extends Model
{

}

#[CollectedBy(BaseCollection::class)]
abstract class TraitParent extends Model
{
    use HasCollection;
}

class TraitParentChild extends TraitParent
{

}

class TraitChild extends BaseModel
{
    use HasCollection;
}

class ClassCollectionParent extends Model
{
    protected static string $collectionClass = StringCollection::class;
}

class ClassCollectionChild extends ClassCollectionParent
{

}

#[CollectedBy(BaseCollection::class)]
class AttributeOverClassCollection extends ClassCollectionParent
{

}

/**
 * @template TKey of array-key
 * @template TModel of Model
 *
 * @extends Collection<TKey, TModel>
 */
class BaseCollection extends Collection
{

}

/**
 * @template TKey of array-key
 * @template TModel of Model
 *
 * @extends Collection<TKey, TModel>
 */
class StringCollection extends Collection
{

}

/** @extends Collection<int, Team> */
class TeamCollection extends Collection
{

}
