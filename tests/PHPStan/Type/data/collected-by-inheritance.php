<?php

namespace CollectedByInheritance;

use Hypervel\Database\Eloquent\Attributes\CollectedBy;
use Hypervel\Database\Eloquent\Collection;
use Hypervel\Database\Eloquent\HasCollection;
use Hypervel\Database\Eloquent\Model;
use function PHPStan\Testing\assertType;

function test(): void
{
    assertType('CollectedByInheritance\BaseCollection<int, CollectedByInheritance\User>', User::all());
    assertType('CollectedByInheritance\BaseCollection<int, CollectedByInheritance\User>', User::query()->where('id', '>', 1)->get());
    assertType('CollectedByInheritance\BaseCollection<int, CollectedByInheritance\Admin>', Admin::all());
    assertType('CollectedByInheritance\TeamCollection', Team::all());
    // Hypervel: the attribute is instantiated, so its named argument applies to the model and its child.
    assertType('CollectedByInheritance\TeamCollection', NamedArgumentParent::all());
    assertType('CollectedByInheritance\TeamCollection', NamedArgumentChild::all());
    assertType('CollectedByInheritance\StringCollection<int, CollectedByInheritance\StringChild>', StringChild::all());
    assertType('CollectedByInheritance\StringCollection<int, CollectedByInheritance\LeadingBackslashString>', LeadingBackslashString::all());
    assertType('CollectedByInheritance\BaseCollection<int, CollectedByInheritance\TraitParentChild>', TraitParentChild::all());
    assertType('CollectedByInheritance\BaseCollection<int, CollectedByInheritance\TraitChild>', TraitChild::all());
    // Hypervel: without the attribute, HasCollection uses the static $collectionClass, which a child inherits.
    assertType('CollectedByInheritance\StringCollection<int, CollectedByInheritance\ClassCollectionParent>', ClassCollectionParent::all());
    assertType('CollectedByInheritance\StringCollection<int, CollectedByInheritance\ClassCollectionChild>', ClassCollectionChild::all());
    assertType('CollectedByInheritance\BaseCollection<int, CollectedByInheritance\AttributeOverClassCollection>', AttributeOverClassCollection::all());
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

#[CollectedBy('CollectedByInheritance\StringCollection')]
class StringParent extends Model
{

}

class StringChild extends StringParent
{

}

#[CollectedBy('\CollectedByInheritance\StringCollection')]
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
