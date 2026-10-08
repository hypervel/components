# Nested Set

- [Introduction](#introduction)
- [Installation](#installation)
- [Database Setup](#database-setup)
    - [Adding Nested Sets to Existing Tables](#adding-nested-sets-to-existing-tables)
- [Model Setup](#model-setup)
    - [Custom Column Names](#custom-column-names)
- [Creating Nodes](#creating-nodes)
    - [Creating Root Nodes](#creating-root-nodes)
    - [Creating Child Nodes](#creating-child-nodes)
    - [Creating Trees From Arrays](#creating-trees-from-arrays)
- [Moving Nodes](#moving-nodes)
- [Deleting Nodes](#deleting-nodes)
- [Transactions and Concurrency](#transactions-and-concurrency)
- [Retrieving Nodes](#retrieving-nodes)
    - [Relationships](#relationships)
    - [Ancestors and Descendants](#ancestors-and-descendants)
    - [Siblings and Neighboring Nodes](#siblings-and-neighboring-nodes)
    - [Node State](#node-state)
- [Querying Trees](#querying-trees)
    - [Tree Constraints](#tree-constraints)
    - [Roots, Leaves, and Parents](#roots-leaves-and-parents)
    - [Depth](#depth)
    - [Ordering](#ordering)
- [Collections](#collections)
- [Rebuilding and Repairing Trees](#rebuilding-and-repairing-trees)
    - [Checking for Errors](#checking-for-errors)
    - [Fixing Existing Trees](#fixing-existing-trees)
    - [Rebuilding Trees From Data](#rebuilding-trees-from-data)
- [Scoped Trees](#scoped-trees)
- [Soft Deleting Nodes](#soft-deleting-nodes)
- [Rendering Trees](#rendering-trees)
- [Performance](#performance)
- [Credits](#credits)

<a name="introduction"></a>
## Introduction

Hypervel's nested set package provides tools for storing hierarchical data in a relational database. It is useful for category trees, menus, organizational charts, threaded comments, file hierarchies, and other data where you often need to read a full branch of the tree.

A [nested set](https://en.wikipedia.org/wiki/Nested_set_model) gives each node a left and right bound that enclose the bounds of all of its descendants. Finding a node's ancestors or descendants only compares these numbers, while inserting, moving, or deleting a node updates the bounds of other nodes in the tree. Nested sets are therefore best suited to trees that are read much more often than they change.

<a name="installation"></a>
## Installation

You may install the package using Composer:

```shell
composer require hypervel/nested-set
```

<a name="database-setup"></a>
## Database Setup

Add the nested set columns to your table using the `nestedSet` Blueprint method:

```php
<?php

declare(strict_types=1);

use Hypervel\Database\Migrations\Migration;
use Hypervel\Database\Schema\Blueprint;
use Hypervel\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->nestedSet();
            $table->timestamps();
        });
    }
};
```

The `nestedSet` method adds `_lft`, `_rgt`, `depth`, and a nullable `parent_id` matching `$table->id()`. It also creates indexes for ancestor, descendant, child, and sibling queries.

Use the helper that matches your model's primary key:

```php
$table->increments('id');
$table->integerNestedSet();

$table->uuid('id')->primary();
$table->uuidNestedSet();

$table->ulid('id')->primary();
$table->ulidNestedSet();
```

If you need to remove the nested set columns from an existing table, you may use the `dropNestedSet` Blueprint method:

```php
Schema::table('categories', function (Blueprint $table) {
    $table->dropNestedSet();
});
```

Pass the same ordered scope columns to both methods when using scoped trees:

```php
$table->foreignId('menu_id');
$table->nestedSet(['menu_id']);

// In the rollback migration:
$table->dropNestedSet(['menu_id']);
```

<a name="adding-nested-sets-to-existing-tables"></a>
### Adding Nested Sets to Existing Tables

If an existing table already stores each row's parent in a nullable `parent_id` column, add the remaining nested set columns and indexes yourself, since the `nestedSet` method would also create `parent_id`:

```php
Schema::table('categories', function (Blueprint $table) {
    $table->unsignedInteger('_lft')->default(0);
    $table->unsignedInteger('_rgt')->default(0);
    $table->unsignedSmallInteger('depth')->default(0);

    $table->index('_rgt');
    $table->index(['_lft', '_rgt']);
    $table->index(['parent_id', '_lft']);
});
```

After adding the `HasNode` trait to your model, calculate each node's bounds and depth from the existing parent keys using the [`fixTree`](#fixing-existing-trees) method:

```php
Category::fixTree();
```

Rows with a `null` parent become root nodes. Rows whose parent does not exist also become roots, and their `parent_id` is cleared. For scoped trees, start each index with the scope columns and fix each tree through a [scoped query](#scoped-trees).

<a name="model-setup"></a>
## Model Setup

To make an Eloquent model behave as a nested set node, add the `Hypervel\NestedSet\HasNode` trait to the model:

```php
<?php

declare(strict_types=1);

namespace App\Models;

use Hypervel\Database\Eloquent\Model;
use Hypervel\NestedSet\HasNode;

class Category extends Model
{
    use HasNode;

    protected array $fillable = [
        'name',
        'parent_id',
    ];
}
```

The trait adds the nested set query builder, relationships, node movement operations, and collection helpers used throughout this document.

Custom Eloquent builders must extend the package's nested set builder:

```php
use Hypervel\Database\Eloquent\Attributes\UseEloquentBuilder;
use Hypervel\NestedSet\Eloquent\QueryBuilder;

#[UseEloquentBuilder(CategoryQueryBuilder::class)]
class Category extends Model
{
    use HasNode;
}

class CategoryQueryBuilder extends QueryBuilder
{
    // ...
}
```

For [static analysis](/docs/{{version}}/database#static-analysis), make the builder generic and add the `HasBuilder` trait with its type. Since `HasNode` already defines `newEloquentBuilder`, keep its version when adding the trait:

```php
use Hypervel\Database\Eloquent\HasBuilder;

#[UseEloquentBuilder(CategoryQueryBuilder::class)]
class Category extends Model
{
    use HasNode;

    /** @use HasBuilder<CategoryQueryBuilder<static>> */
    use HasBuilder {
        HasNode::newEloquentBuilder insteadof HasBuilder;
    }
}

/**
 * @template TModel of Model
 *
 * @extends QueryBuilder<TModel>
 */
class CategoryQueryBuilder extends QueryBuilder
{
    // ...
}
```

<a name="custom-column-names"></a>
### Custom Column Names

If your table uses other names for the nested set columns, override the matching methods on your model:

```php
public function getLftName(): string
{
    return 'lft';
}

public function getRgtName(): string
{
    return 'rgt';
}

public function getDepthName(): string
{
    return 'level';
}

public function getParentIdName(): string
{
    return 'parent_category_id';
}
```

The schema methods always use the default names, so create these columns and their indexes yourself, as shown in [adding nested sets to existing tables](#adding-nested-sets-to-existing-tables).

Assigning `parent_id` appends a node through the trait's `setParentIdAttribute` mutator. If you rename the parent column, add a mutator for the new name so that assigning it, or creating nodes through the `children` relationship, still appends the node:

```php
public function setParentCategoryIdAttribute(int|string|null $value): void
{
    $this->setParentIdAttribute($value);
}
```

<a name="creating-nodes"></a>
## Creating Nodes

<a name="creating-root-nodes"></a>
### Creating Root Nodes

New nodes are saved as root nodes by default when no other node action has been queued:

```php
$electronics = Category::create([
    'name' => 'Electronics',
]);
```

You may also explicitly save a node as a root:

```php
$clothing = new Category([
    'name' => 'Clothing',
]);

$clothing->saveAsRoot();
```

Calling `saveAsRoot` on a root that is already stored leaves its position unchanged. The `makeRoot` method queues a forced root operation but does not save the model. Use it when you need to move an existing root to the end of the root list:

```php
$category->makeRoot();

$category->save();
```

<a name="creating-child-nodes"></a>
### Creating Child Nodes

The `appendNode` and `prependNode` methods save the child node immediately:

```php
$computers = new Category([
    'name' => 'Computers',
]);

$electronics->appendNode($computers);

$phones = new Category([
    'name' => 'Phones',
]);

$electronics->prependNode($phones);
```

If you want to queue the move and save the model yourself, use `appendToNode` or `prependToNode`:

```php
$laptops = new Category([
    'name' => 'Laptops',
]);

$laptops->appendToNode($computers);

$laptops->save();
```

You may also create a child node by passing the parent as the second argument to `create`:

```php
$tablets = Category::create([
    'name' => 'Tablets',
], $electronics);
```

The `parent_id` attribute may also be assigned directly. The node will be appended to the matching parent:

```php
$accessories = Category::create([
    'name' => 'Accessories',
    'parent_id' => $electronics->getKey(),
]);
```

Since assigning `parent_id` appends the node, the `children` and `parent` relationships work as well:

```php
$monitors = $computers->children()->create([
    'name' => 'Monitors',
]);

$cameras = new Category([
    'name' => 'Cameras',
]);

$cameras->parent()->associate($electronics)->save();
```

Assigning `parent_id` resolves an active parent. To intentionally target a soft-deleted parent, pass a model retrieved with `withTrashed()` to `appendToNode` or `prependToNode`.

<a name="creating-trees-from-arrays"></a>
### Creating Trees From Arrays

You may create a tree by passing a nested `children` array to `create`:

```php
$electronics = Category::create([
    'name' => 'Electronics',
    'children' => [
        [
            'name' => 'Computers',
            'children' => [
                ['name' => 'Laptops'],
                ['name' => 'Desktops'],
            ],
        ],
        [
            'name' => 'Phones',
            'children' => [
                ['name' => 'iPhone'],
                ['name' => 'Android'],
            ],
        ],
    ],
]);
```

The returned node's `children` relation contains the created child nodes, each with its own `children` loaded the same way.

<a name="moving-nodes"></a>
## Moving Nodes

The `beforeNode` and `afterNode` methods queue an insert operation relative to another node. Call `save` to persist the move:

```php
$smartphones = new Category([
    'name' => 'Smartphones',
]);

$smartphones->beforeNode($tablets);

$smartphones->save();
```

If you want to perform the move and save the model in one call, use `insertBeforeNode` or `insertAfterNode`:

```php
$smartwatches = new Category([
    'name' => 'Smartwatches',
]);

$smartwatches->insertAfterNode($smartphones);
```

The target node must already be saved, while the node you are placing may be new or existing. An existing node moves to the new position and takes the target's parent.

You may move an existing node to another parent using `appendToNode` or `prependToNode`:

```php
$laptops->appendToNode($electronics);

$laptops->save();
```

The `up` and `down` methods move a node among its siblings and save the move immediately:

```php
$laptops->up();

$tablets->down(2);
```

Both methods return `false` when there is no sibling to move past.

Saving a node does not always move it. For example, calling `saveAsRoot` on a node that is already a root leaves it in place. You may use the `hasMoved` method to determine whether the last save changed the node's position:

```php
if ($category->save()) {
    $moved = $category->hasMoved();
}
```

Hypervel will throw a `LogicException` if you try to move a node into itself or one of its descendants.

<a name="deleting-nodes"></a>
## Deleting Nodes

Deleting a node also deletes all of its descendants, and removing their rows closes the gap they leave in the tree. Models that use soft deletes keep their place in the tree instead, as described in [soft deleting nodes](#soft-deleting-nodes):

```php
$electronics->delete();
```

You may delete several nodes at once using `destroy`, even when some of them are descendants of others:

```php
Category::destroy([$computers->id, $laptops->id]);
```

> [!WARNING]
> Always delete nodes through their models. A query builder delete, such as `Category::where('name', 'Old')->delete()`, skips tree maintenance and leaves the tree broken. If rows have been removed outside of their models, repair the tree using [`fixTree`](#fixing-existing-trees).

Rows are removed children first, so deleting a subtree works with a restricting foreign key on `parent_id`. Descendants hidden by your model's global scopes are deleted along with the others.

By default, descendants are deleted with a single query, so their model events are not fired. If your application relies on those events, enable descendant events on the model:

```php
protected function shouldFireDescendantEvents(): bool
{
    return true;
}
```

Descendants are then deleted through their models, children first, in chunks of 1,000. You may change the chunk size by overriding the `getDescendantChunkSize` method. If an observer prevents a descendant from being deleted, a `LogicException` is thrown.

A delete that fails partway through, such as when an observer prevents a descendant's deletion or another table's foreign key still references a node, leaves the earlier changes in place. Use `deleteOrFail` to run the whole delete in a transaction:

```php
$electronics->deleteOrFail();
```

<a name="transactions-and-concurrency"></a>
## Transactions and Concurrency

Creating, moving, and deleting nodes, as well as repairing and rebuilding trees, run several statements that update the bounds of other rows. Hypervel does not start a transaction for you, so wrap each change in a database transaction:

```php
use Hypervel\Support\Facades\DB;
use RuntimeException;

DB::transaction(function () use ($electronics, $computers, $phones): void {
    if (! $computers->appendToNode($electronics)->save()) {
        throw new RuntimeException('Unable to save the computers category.');
    }

    if (! $phones->prependToNode($electronics)->save()) {
        throw new RuntimeException('Unable to save the phones category.');
    }
});
```

A structural save updates the bounds of other rows before `saving` observers run. It can therefore return `false` after changing the tree, when an observer vetoes the save or `saveOrIgnore()` ignores a conflict. Throw from the transaction closure, as above, so those changes are rolled back. The `saveOrFail` method does not help here, because it also returns `false` instead of throwing. The node keeps its queued operation, so you can save it again afterward, but Eloquent does not restore the model's other state after a rollback.

A transaction does not stop two requests from changing the same tree at the same time. Serialize writes to each tree, for example using an [atomic lock](/docs/{{version}}/cache#atomic-locks):

```php
use Hypervel\Support\Facades\Cache;

Cache::lock('category-tree', 10)->block(5, function () use ($electronics, $laptops): void {
    DB::transaction(function () use ($electronics, $laptops): void {
        if (! $laptops->appendToNode($electronics)->save()) {
            throw new RuntimeException('Unable to save the laptops category.');
        }
    });
});
```

For scoped trees, include the scope values in the lock name so that writes to different trees may still run at the same time.

Immediately before a structural change, Hypervel reloads each existing participating node from the write connection. A partially selected model must therefore include its primary key so Hypervel can reload the exact row. This keeps structural decisions aligned with the database without adding hidden reads to ordinary relation queries and node state helpers.

<a name="retrieving-nodes"></a>
## Retrieving Nodes

<a name="relationships"></a>
### Relationships

The `HasNode` trait adds `parent`, `children`, `ancestors`, `descendants`, `siblings`, and `siblingsAndSelf` relationships:

```php
$category = Category::find(1);

$parent = $category->parent;

$children = $category->children;

$ancestors = $category->ancestors;

$descendants = $category->descendants;

$siblings = $category->siblings;
```

You may eager load these relationships or use them in existence and count queries like any other Eloquent relationship:

```php
$categories = Category::with(['ancestors', 'descendants', 'siblings'])
    ->withCount('siblings')
    ->get();
```

> [!WARNING]
> When eager loading descendants for thousands of separate parent nodes that have children, SQLite may spend substantial time preparing the query. For large SQLite workloads, eager load the descendants of the parent models in smaller batches.

<a name="ancestors-and-descendants"></a>
### Ancestors and Descendants

You may retrieve ancestor and descendant queries from a node:

```php
$ancestors = $category->ancestors()->get();

$descendants = $category->descendants()->get();
```

The `getAncestors` and `getDescendants` methods return the corresponding collections directly:

```php
$ancestors = $category->getAncestors();

$descendants = $category->getDescendants();
```

Ancestors are returned in order from the root down to the node's parent. For example, you may eager load them to display breadcrumbs for a list of categories:

```php
$categories = Category::with('ancestors')->paginate(30);

foreach ($categories as $category) {
    echo $category->ancestors
        ->pluck('name')
        ->push($category->name)
        ->implode(' > ');
}
```

When loading these relations from models selected with specific columns, include `_lft`, `_rgt`, and any scope columns on the parent models. Ancestor results also require both bounds and any scope columns. Descendant results require `_lft` and any scope columns. An unsaved parent has an empty relation. A persisted parent or related result with a missing required column throws a `LogicException`; Hypervel does not issue a hidden query to repair an incomplete projection.

To include the node itself in the result, use the query builder methods and pass the node's key:

```php
$ancestors = Category::ancestorsOf($category->getKey());

$ancestorsAndSelf = Category::ancestorsAndSelf($category->getKey());

$descendants = Category::descendantsOf($category->getKey());

$descendantsAndSelf = Category::descendantsAndSelf($category->getKey());
```

Unlike the `ancestors` relationship, these query methods do not apply an order. Add `defaultOrder` when you need the ancestors from the root down:

```php
$ancestors = Category::defaultOrder()->ancestorsOf($category->getKey());
```

If you need to continue building the query before retrieving results, use `whereAncestorOrSelf` or `whereDescendantOrSelf`:

```php
$path = Category::whereAncestorOrSelf($category->getKey())
    ->defaultOrder()
    ->pluck('name')
    ->implode(' > ');

$nodes = Category::whereDescendantOrSelf($category->getKey())
    ->withCount('products')
    ->get();
```

These constraints may also be used in a subquery. For example, you may retrieve the products of a category and all of its descendants with a single query:

```php
$products = Product::whereIn(
    'category_id',
    Category::whereDescendantOrSelf($category)->select('id'),
)->get();
```

<a name="siblings-and-neighboring-nodes"></a>
### Siblings and Neighboring Nodes

The package provides query and collection helpers for siblings:

```php
$siblings = $category->getSiblings();

$siblingsAndSelf = $category->getSiblingsAndSelf();

$nextSiblings = $category->getNextSiblings();

$previousSiblings = $category->getPrevSiblings();

$nextSibling = $category->getNextSibling();

$previousSibling = $category->getPrevSibling();
```

The `siblings`, `nextSiblings`, and `prevSiblings` methods return queries that you may constrain further:

```php
$nextVisibleSiblings = $category->nextSiblings()
    ->where('visible', true)
    ->defaultOrder()
    ->get();
```

When loading sibling relations from models selected with specific columns, include the configured parent and scope columns. The `siblings` relationship also requires the model's primary key so it can exclude the node itself. An unsaved parent has an empty relation. A persisted parent or related result with a missing required column throws a `LogicException`; Hypervel does not issue a hidden query to repair an incomplete projection.

You may also query neighboring nodes without limiting the result to siblings:

```php
$nextNode = $category->getNextNode();

$previousNode = $category->getPrevNode();
```

<a name="node-state"></a>
### Node State

You may inspect a node's position and relationships using helper methods:

```php
if ($category->isRoot()) {
    // ...
}

if ($category->isLeaf()) {
    // ...
}

if ($category->isChildOf($parent)) {
    // ...
}

if ($category->isDescendantOf($parent)) {
    // ...
}

if ($parent->isAncestorOf($category)) {
    // ...
}

if ($category->isSiblingOf($other)) {
    // ...
}
```

You may also retrieve boundary information:

```php
[$left, $right] = $category->getBounds();

$height = $category->getNodeHeight();

$descendantCount = $category->getDescendantCount();
```

Node state helpers use the columns already loaded on the model and do not issue hidden queries. Select `parent_id` before calling `isRoot`, `isChildOf`, or `isSiblingOf`. Select both `_lft` and `_rgt` before calling `isLeaf`, `getNodeHeight`, or `getDescendantCount`. With an incomplete projection, `isLeaf` returns `false`, while the numeric methods throw a `LogicException` because no correct value can be calculated. The mutating `up` and `down` methods reread the node before selecting its current siblings.

<a name="querying-trees"></a>
## Querying Trees

<a name="tree-constraints"></a>
### Tree Constraints

You may query nodes by their relationship to another node:

```php
$ancestors = Category::whereAncestorOf($category)->get();

$ancestorsAndSelf = Category::whereAncestorOrSelf($category->getKey())->get();

$descendants = Category::whereDescendantOf($category)->get();

$descendantsAndSelf = Category::whereDescendantOrSelf($category->getKey())->get();

$notDescendants = Category::whereNotDescendantOf($category)->get();
```

Each constraint accepts a node or its primary key. The `orWhereAncestorOf`, `orWhereDescendantOf`, and `orWhereNotDescendantOf` methods join the same constraints using `or`:

```php
$categories = Category::whereDescendantOf($electronics)
    ->orWhereDescendantOf($clothing)
    ->get();
```

You may also query nodes that appear before or after another node in the tree:

```php
$before = Category::whereIsBefore($category)->get();

$after = Category::whereIsAfter($category)->get();
```

<a name="roots-leaves-and-parents"></a>
### Roots, Leaves, and Parents

You may query root nodes, leaf nodes, and nodes that have children:

```php
$root = Category::root();

$roots = Category::whereIsRoot()->get();

$leaves = Category::leaves();

$leaves = Category::whereIsLeaf()->get();

$parents = Category::hasChildren()->get();
```

To exclude root nodes, use `withoutRoot` or `hasParent`:

```php
$nonRootNodes = Category::withoutRoot()->get();

$nodesWithParent = Category::hasParent()->get();
```

<a name="depth"></a>
### Depth

Every node stores its depth in the `depth` column, with root nodes at depth `0`, so the depth is available on each retrieved node:

```php
$categories = Category::defaultOrder()->get();

foreach ($categories as $category) {
    echo str_repeat('  ', $category->depth) . $category->name;
}
```

When you select specific columns, or need the depth under another name, use the `withDepth` method:

```php
$categories = Category::select('id', 'name')
    ->withDepth('level')
    ->get();

$categories->first()->level;
```

You may filter by depth directly:

```php
$topTwoLevels = Category::query()
    ->where('depth', '<=', 1)
    ->defaultOrder()
    ->get();
```

The standard nested set indexes favor ancestor, descendant, child, and sibling reads. If your application frequently filters large trees by depth, you may add an index on `['depth', '_lft']`, prefixed by any scope columns.

<a name="ordering"></a>
### Ordering

Queries do not apply an order by default. To retrieve nodes in tree order, where each node follows its parent and siblings keep their position, use `defaultOrder`:

```php
$categories = Category::defaultOrder()->get();
```

You may retrieve nodes in reverse tree order using `reversed`:

```php
$categories = Category::reversed()->get();
```

<a name="collections"></a>
## Collections

Nested set models return a `Hypervel\NestedSet\Eloquent\Collection` instance. This collection can link parent and child relationships or convert a flat result set into a tree:

```php
$categories = Category::defaultOrder()->get();

$categories->linkNodes();

$tree = $categories->toTree();

$flatTree = $categories->toFlatTree();
```

The `linkNodes` method fills each node's `parent` and `children` relations using the other nodes in the collection. The `toTree` method links the nodes the same way and returns only the top-level nodes, while `toFlatTree` returns every node followed directly by its descendants. Both keep the collection's order among siblings, so you may order the query by another column to sort each level of the tree:

```php
$categories = Category::orderBy('name')->get()->toFlatTree();
```

When selecting specific columns for collection tree methods, include the model's primary key and configured parent column. The `toTree` and `toFlatTree` methods also require `_lft` when you do not pass a root; a root model passed to either method must include its primary key.

You may build a tree for a specific root node by passing the root model or key to `toTree`:

```php
$mobile = Category::find(5);

$tree = Category::whereDescendantOf($mobile)
    ->defaultOrder()
    ->get()
    ->toTree($mobile);
```

To include the root node itself, retrieve it along with its descendants and take the first node of the tree:

```php
$mobile = Category::defaultOrder()
    ->descendantsAndSelf(5)
    ->toTree()
    ->first();
```

Linked nodes include their `parent` relation when serialized. To leave it out, call `makeHidden('parent')` on the collection before calling `toTree` or `linkNodes`:

```php
$tree = Category::defaultOrder()
    ->get()
    ->makeHidden('parent')
    ->toTree();
```

<a name="rebuilding-and-repairing-trees"></a>
## Rebuilding and Repairing Trees

The diagnostic, repair, and rebuild methods work with every row in the tree, including rows hidden by your model's global scopes, since those rows still hold positions in the tree.

<a name="checking-for-errors"></a>
### Checking for Errors

You may check a tree for structural errors using `countErrors`:

```php
$errors = Category::countErrors();

// [
//     'invalid_intervals' => 0,
//     'duplicate_endpoints' => 0,
//     'missing_endpoints' => 0,
//     'crossing_intervals' => 0,
//     'missing_parent' => 0,
//     'wrong_parent' => 0,
//     'wrong_depth' => 0,
// ]
```

You may retrieve the total error count or check whether the tree is broken:

```php
$totalErrors = Category::getTotalErrors();

$isBroken = Category::isBroken();
```

The total is useful as a broken-or-healthy signal. Since one damaged node may violate more than one invariant, it is not a unique count of damaged nodes.

The `countErrors`, `getTotalErrors`, and `isBroken` methods use a SQL window function. When using MySQL, these methods require MySQL 8.0 or newer. Every other database supported by Hypervel meets this requirement at its minimum supported version.

<a name="fixing-existing-trees"></a>
### Fixing Existing Trees

The `fixTree` method repairs `_lft`, `_rgt`, `depth`, and invalid parentage using the existing `parent_id` values:

```php
if (Category::isBroken()) {
    $fixed = Category::fixTree();
}
```

You may also fix a subtree:

```php
$fixed = Category::fixSubtree($rootNode);
```

Repair selects only structural and scope columns by default. If a model observer needs other attributes, pass them explicitly:

```php
$fixed = Category::fixTree(extraColumns: ['name', 'slug']);
```

Nodes with missing or cyclic parents become roots. During subtree repair, they become direct children of the supplied root so they remain inside that subtree.

Subtree repair requires the root's stored bounds to contain every child linked beneath it. If the damage crosses that boundary, repair the complete tree with `fixTree()` first.

When starting a subtree repair from a scoped builder, the builder scope must match the supplied root:

```php
MenuItem::scoped(['menu_id' => 1])->fixSubtree($rootFromMenu1);
```

<a name="rebuilding-trees-from-data"></a>
### Rebuilding Trees From Data

The `rebuildTree` method rebuilds a tree from nested array data. Existing nodes are matched by their primary key. Items without a primary key are created:

```php
$fixed = Category::rebuildTree([
    [
        'id' => 1,
        'name' => 'Electronics',
        'children' => [
            [
                'id' => 2,
                'name' => 'Computers',
                'children' => [
                    ['name' => 'Laptops'],
                ],
            ],
            [
                'name' => 'Phones',
            ],
        ],
    ],
]);
```

To delete nodes that exist in the database but are missing from the rebuild data, pass `delete: true`:

```php
$treeData = [
    [
        'id' => 1,
        'name' => 'Electronics',
        'children' => [
            ['id' => 2, 'name' => 'Computers'],
        ],
    ],
];

Category::rebuildTree($treeData, delete: true);
```

You may rebuild a subtree using `rebuildSubtree`:

```php
Category::rebuildSubtree($rootNode, [
    ['name' => 'New child'],
]);
```

The nested array controls parentage. Primary keys identify existing nodes, while `parent_id`, `_lft`, `_rgt`, `depth`, and scope values in the payload are ignored. If a primary key does not match a node in the tree or subtree being rebuilt, a `ModelNotFoundException` is thrown.

Rebuilding a subtree has the same boundary requirement. If a parentage edge crosses the root's stored bounds, repair the complete tree with `fixTree()` first.

A scoped builder passed to `rebuildSubtree` must likewise match the supplied root's scope.

<a name="scoped-trees"></a>
## Scoped Trees

Scoped trees allow multiple independent trees to be stored in the same table. For example, a menu item table may store separate trees for each menu:

```php
<?php

declare(strict_types=1);

namespace App\Models;

use Hypervel\Database\Eloquent\Model;
use Hypervel\NestedSet\HasNode;

class MenuItem extends Model
{
    use HasNode;

    protected array $fillable = [
        'menu_id',
        'title',
        'parent_id',
    ];

    protected function getScopeAttributes(): array
    {
        return ['menu_id'];
    }
}
```

The scope attributes define the physical tree partition. Create those columns before calling the matching schema helper so they prefix each nested set index:

```php
$table->foreignId('menu_id');
$table->nestedSet(['menu_id']);
```

Scopes may contain multiple columns. For example, a multi-tenant application that stores multiple menus for each tenant may scope a tree by both values:

```php
$table->uuid('id')->primary();
$table->uuid('tenant_id');
$table->uuid('menu_id');
$table->uuidNestedSet(['tenant_id', 'menu_id']);
```

Return `['tenant_id', 'menu_id']` from `getScopeAttributes()` and provide both values when starting a scoped query.

Set every scope value before saving a new node. A stored node cannot be moved to another scope by changing those attributes.

To query one tree, start from the `scoped` method. A primary key alone does not identify a node's tree, so queries that look up a node by its key, such as `descendantsOf`, throw a `LogicException` unless they start from `scoped`:

```php
$items = MenuItem::scoped(['menu_id' => 1])
    ->defaultOrder()
    ->get();

$descendants = MenuItem::scoped(['menu_id' => 1])
    ->descendantsOf($nodeId);
```

Relationships, node methods, and constraints given a node use that node's own scope. This includes eager loading, which may load nodes from several trees at once:

```php
$items = MenuItem::with('descendants')->get();
```

To start a query within a node's tree, use the `newScopedQuery` method:

```php
$leaves = $item->newScopedQuery()->whereIsLeaf()->get();
```

Diagnostics, whole-tree repair, and rebuild operations also require a concrete scope:

```php
$errors = MenuItem::scoped(['menu_id' => 1])->countErrors();

MenuItem::scoped(['menu_id' => 1])->fixTree();
```

Ordinary Eloquent global scopes only control visibility; they do not create separate nested set trees. Use `getScopeAttributes()` for menu IDs or any other value that partitions the stored boundaries.

Node operations also respect the database connection, table, and scope. Moving a node between trees will throw a `LogicException`:

```php
$source = MenuItem::scoped(['menu_id' => 1])->first();

$target = MenuItem::scoped(['menu_id' => 2])->first();

$source->appendToNode($target)->save();

// LogicException
```

<a name="soft-deleting-nodes"></a>
## Soft Deleting Nodes

Nested set models may use Eloquent's `SoftDeletes` trait:

```php
<?php

declare(strict_types=1);

namespace App\Models;

use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Eloquent\SoftDeletes;
use Hypervel\NestedSet\HasNode;

class Category extends Model
{
    use SoftDeletes;
    use HasNode;
}
```

Soft deleting a node soft deletes its descendants:

```php
$electronics->delete();
```

Soft deleted nodes keep their place in the tree so they can be restored. As a result, a node whose children are all soft deleted is not a leaf: `isLeaf` returns `false` and `whereIsLeaf` does not match the node until its children are force deleted.

Restoring the node restores descendants whose stored deletion time is the same as or later than the node's. Descendants deleted earlier remain deleted:

```php
$electronics->restore();
```

Force deleting a node removes the node and its descendants from the table and closes the gap in the tree:

```php
$electronics->forceDelete();
```

Descendants hidden by your model's global scopes are restored along with the others. When [descendant events](#deleting-nodes) are enabled, descendants are restored through their models, parents first, using the same chunk size as deletes.

Like a delete, a force delete or restore may fail partway through, so wrap it in a transaction:

```php
DB::transaction(fn () => $electronics->forceDelete());
```

<a name="rendering-trees"></a>
## Rendering Trees

For rendering, retrieve nodes in tree order and convert them to a tree collection:

```php
$tree = Category::defaultOrder()
    ->get()
    ->toTree();
```

You may then render each node's `children` relation recursively:

```php
function renderTree(iterable $nodes): string
{
    $html = '<ul>';

    foreach ($nodes as $node) {
        $html .= '<li>' . e($node->name);

        if ($node->children->isNotEmpty()) {
            $html .= renderTree($node->children);
        }

        $html .= '</li>';
    }

    return $html . '</ul>';
}

echo renderTree($tree);
```

<a name="performance"></a>
## Performance

Nested sets are designed for reading branches of a tree. Ancestor, descendant, and subtree reads can be performed efficiently using the `_lft` and `_rgt` boundaries.

Inserting, moving, and deleting nodes update the bounds of many other nodes in the same tree, so their cost grows with the size of the tree. [Scoped trees](#scoped-trees) keep this work within one tree.

The schema helpers create indexes on `_rgt`, `[_lft, _rgt]`, and `[parent_id, _lft]`. For scoped trees, each index starts with the scope columns so queries can narrow their search to one tree. Including both bounds in the same index reduces the work needed for ancestor queries. These indexes make structural writes slightly slower in exchange for faster reads, which suits the read-heavy workloads nested sets are designed for. Add a depth index only when your application frequently filters large trees by depth.

<a name="credits"></a>
## Credits

Hypervel Nested Set is based on Alexander Kalnoy's [laravel-nestedset](https://github.com/lazychaser/laravel-nestedset) and its successor, [Aimeos Laravel Nested Set](https://github.com/aimeos/laravel-nestedset). It also includes fixes and tests from [Lunar's fork](https://github.com/lunarphp/nestedset), and has been adapted for Hypervel's framework architecture and coroutine runtime.
