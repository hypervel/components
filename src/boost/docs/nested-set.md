# Nested Set

- [Introduction](#introduction)
- [Installation](#installation)
- [Database Setup](#database-setup)
- [Model Setup](#model-setup)
- [Creating Nodes](#creating-nodes)
    - [Creating Root Nodes](#creating-root-nodes)
    - [Creating Child Nodes](#creating-child-nodes)
    - [Creating Trees From Arrays](#creating-trees-from-arrays)
- [Moving Nodes](#moving-nodes)
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

<a name="introduction"></a>
## Introduction

Hypervel's nested set package provides tools for storing hierarchical data in a relational database. It is useful for category trees, menus, organizational charts, threaded comments, file hierarchies, and other data where you often need to read a full branch of the tree.

Nested sets store each node with left and right boundary columns. This makes ancestor and descendant reads efficient, while inserts and moves update the affected boundary ranges.

The package is based on Aimeos's maintained `laravel-nestedset` package and adapted for Hypervel's Eloquent implementation.

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

The `makeRoot` method queues the root operation but does not save the model:

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

Hypervel will throw a `LogicException` if you try to move a node into itself or one of its descendants.

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

To include the node itself in the result, use the query builder methods and pass the node's key:

```php
$ancestors = Category::ancestorsOf($category->getKey());

$ancestorsAndSelf = Category::ancestorsAndSelf($category->getKey());

$descendants = Category::descendantsOf($category->getKey());

$descendantsAndSelf = Category::descendantsAndSelf($category->getKey());
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

<a name="siblings-and-neighboring-nodes"></a>
### Siblings and Neighboring Nodes

The package provides query and collection helpers for siblings:

```php
$siblings = $category->siblings()->get();

$siblingsAndSelf = $category->getSiblingsAndSelf();

$nextSiblings = $category->getNextSiblings();

$previousSiblings = $category->getPrevSiblings();

$nextSibling = $category->getNextSibling();

$previousSibling = $category->getPrevSibling();
```

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
```

You may also retrieve boundary information and movement state:

```php
[$left, $right] = $category->getBounds();

$height = $category->getNodeHeight();

$descendantCount = $category->getDescendantCount();

$moved = $category->hasMoved();
```

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

Every node stores its depth, with root nodes at depth `0`. You may select the depth column explicitly using `withDepth`:

```php
$categories = Category::withDepth()
    ->defaultOrder()
    ->get();

foreach ($categories as $category) {
    echo str_repeat('  ', $category->depth) . $category->name;
}
```

You may customize the selected depth column name:

```php
$categories = Category::withDepth('level')->get();

$categories->first()->level;
```

You may filter by depth directly:

```php
$topTwoLevels = Category::query()
    ->where('depth', '<=', 2)
    ->defaultOrder()
    ->get();
```

The standard nested set indexes favor ancestor, descendant, child, and sibling reads. If your application frequently filters large trees by depth, you may add an index on `['depth', '_lft']`, prefixed by any scope columns.

<a name="ordering"></a>
### Ordering

To retrieve nodes in tree order, use `defaultOrder`:

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

You may build a tree for a specific root node by passing the root model or key to `toTree`:

```php
$mobile = Category::find(5);

$tree = Category::whereDescendantOf($mobile)
    ->defaultOrder()
    ->get()
    ->toTree($mobile);
```

<a name="rebuilding-and-repairing-trees"></a>
## Rebuilding and Repairing Trees

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

Repair selects only structural columns by default. If a model observer needs other attributes, pass them explicitly:

```php
$fixed = Category::fixTree(extraColumns: ['name', 'slug']);
```

Nodes with missing or cyclic parents become roots. During subtree repair, they become direct children of the supplied root so they remain inside that subtree.

Subtree repair requires the root's stored bounds to contain every child linked beneath it. If the damage crosses that boundary, repair the complete tree with `fixTree()` first.

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

Rebuilding a subtree has the same boundary requirement. If a parentage edge crosses the root's stored bounds, repair the complete tree with `fixTree()` first.

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

When querying a scoped tree by plain IDs, start from the `scoped` query:

```php
$items = MenuItem::scoped(['menu_id' => 1])
    ->defaultOrder()
    ->get();

$descendants = MenuItem::scoped(['menu_id' => 1])
    ->descendantsOf($nodeId);
```

Diagnostics, whole-tree repair, and rebuild operations also require a concrete scope:

```php
$errors = MenuItem::scoped(['menu_id' => 1])->countErrors();

MenuItem::scoped(['menu_id' => 1])->fixTree();
```

Ordinary Eloquent global scopes only control visibility; they do not create separate nested set trees. Use `getScopeAttributes()` for menu IDs or any other value that partitions the stored boundaries.

Node operations also respect scope. Moving a node across scopes will throw a `LogicException`:

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

Restoring the node restores descendants that were deleted as part of the same delete operation. Descendants that were already deleted before the parent was deleted remain deleted:

```php
$electronics->restore();
```

Force deleting a node removes the node and its descendants from the table and closes the gap in the nested set:

```php
$electronics->forceDelete();
```

By default, descendants are deleted in one set-based query, so descendant model events are not fired. If your application requires those events, enable the evented path on the model:

```php
protected function shouldFireDescendantEvents(): bool
{
    return true;
}

protected function getDescendantDeleteChunkSize(): int
{
    return 1000;
}
```

The evented path deletes descendants in bounded, children-first chunks. Wrap the delete in a transaction so an exception or veto from a descendant observer rolls back the whole operation.

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

Moving, inserting, deleting, repairing, and rebuilding nodes use multiple statements and update boundary values across affected rows. Wrap each mutation in a database transaction:

```php
DB::transaction(function () use ($electronics, $computers, $phones): void {
    $computers->appendToNode($electronics)->save();

    $phones->prependToNode($electronics)->save();
});
```

If a model observer vetoes a mutation and it returns `false`, throw from the transaction closure so earlier structural writes are rolled back.

Concurrent writers to the same table and nested set scope must also be serialized by your application. The package does not add an implicit distributed lock or network call.

The schema helpers create separate indexes for right-bound scans, left-bound scans, and parent lookups. Scope columns prefix each index, which keeps each scoped tree's reads isolated and substantially reduces ancestor, descendant, child, and sibling query work. These indexes add a bounded cost to structural writes; this favors the read-heavy workloads nested sets are designed for. Add a depth index only when your application frequently filters large trees by depth.
