<?php

declare(strict_types=1);

use Hypervel\Database\Eloquent\HasBuilder;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Eloquent\SoftDeletes;
use Hypervel\NestedSet\Eloquent\Collection;
use Hypervel\NestedSet\Eloquent\QueryBuilder;
use Hypervel\NestedSet\HasNode;

use function PHPStan\Testing\assertType;

class NestedSetTypeCategory extends Model
{
    use HasNode;
}

class NestedSetTypeSubcategory extends NestedSetTypeCategory
{
}

class NestedSetTypeSoftCategory extends Model
{
    use HasNode;
    use SoftDeletes;
}

/**
 * @template TModel of Model
 *
 * @extends QueryBuilder<TModel>
 */
class NestedSetTypeMenuBuilder extends QueryBuilder
{
}

class NestedSetTypeMenuItem extends Model
{
    use HasNode;

    /** @use HasBuilder<NestedSetTypeMenuBuilder<static>> */
    use HasBuilder;

    protected static string $builder = NestedSetTypeMenuBuilder::class;
}

$category = new NestedSetTypeCategory;

assertType('Hypervel\NestedSet\Eloquent\QueryBuilder<NestedSetTypeCategory>', NestedSetTypeCategory::query());
assertType('Hypervel\NestedSet\Eloquent\QueryBuilder<NestedSetTypeCategory>', NestedSetTypeCategory::scoped([]));
assertType('Hypervel\NestedSet\Eloquent\QueryBuilder<NestedSetTypeCategory>', NestedSetTypeCategory::whereIsRoot());
assertType('Hypervel\NestedSet\Eloquent\QueryBuilder<NestedSetTypeSubcategory>', NestedSetTypeSubcategory::query());
assertType('Hypervel\NestedSet\Eloquent\QueryBuilder<NestedSetTypeCategory>', $category->newNestedSetQuery());
assertType('Hypervel\NestedSet\Eloquent\QueryBuilder<NestedSetTypeCategory>', $category->nextSiblings());
assertType('NestedSetTypeCategory|null', NestedSetTypeCategory::query()->root());
assertType('Hypervel\NestedSet\Eloquent\Collection<int, NestedSetTypeCategory>', NestedSetTypeCategory::query()->descendantsOf(1));

assertType('Hypervel\NestedSet\Eloquent\AncestorsRelation<NestedSetTypeCategory>', $category->ancestors());
assertType('Hypervel\NestedSet\Eloquent\DescendantsRelation<NestedSetTypeCategory>', $category->descendants());
assertType('Hypervel\NestedSet\Eloquent\SiblingsRelation<NestedSetTypeCategory>', $category->siblingsAndSelf());
assertType('Hypervel\NestedSet\Eloquent\Collection<int, NestedSetTypeCategory>', $category->descendants()->getResults());
assertType('Hypervel\Database\Eloquent\Relations\BelongsTo<NestedSetTypeCategory, NestedSetTypeCategory>', $category->parent());

assertType('Hypervel\NestedSet\Eloquent\Collection<int, NestedSetTypeCategory>', $category->getAncestors());
assertType('Hypervel\NestedSet\Eloquent\Collection<int, NestedSetTypeCategory>', $category->getAncestors()->toTree());

$keyedCategories = new Collection(['root' => $category]);

assertType('Hypervel\NestedSet\Eloquent\Collection<string, NestedSetTypeCategory>', $keyedCategories);
assertType('Hypervel\NestedSet\Eloquent\Collection<int, NestedSetTypeCategory>', $keyedCategories->toFlatTree());
assertType('NestedSetTypeCategory|null', $category->getNextSibling());

assertType('NestedSetTypeMenuBuilder<NestedSetTypeMenuItem>', NestedSetTypeMenuItem::query());
assertType('NestedSetTypeMenuBuilder<NestedSetTypeMenuItem>', NestedSetTypeMenuItem::whereIsLeaf());

$softCategory = new NestedSetTypeSoftCategory;

assertType('Hypervel\NestedSet\Eloquent\QueryBuilder<NestedSetTypeSoftCategory>', NestedSetTypeSoftCategory::withTrashed());
assertType('Hypervel\NestedSet\Eloquent\AncestorsRelation<NestedSetTypeSoftCategory>', $softCategory->ancestors()->withTrashed());
