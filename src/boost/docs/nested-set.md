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

The package is based on Lazychaser's `laravel-nestedset` package and adapted for Hypervel's Eloquent implementation.

<a name="installation"></a>
## Installation

You may install the package using Composer:

```shell
composer require hypervel/nested-set
```

<a name="database-setup"></a>
## Database Setup

Add the nested set columns to your table using the `NestedSet::columns` helper:

```php
<?php

declare(strict_types=1);

use Hypervel\Database\Migrations\Migration;
use Hypervel\Database\Schema\Blueprint;
use Hypervel\NestedSet\NestedSet;
use Hypervel\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('categories', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name');
            NestedSet::columns($table);
            $table->timestamps();
        });
    }
};
```

The `NestedSet::columns` method adds the `_lft`, `_rgt`, and `parent_id` columns and creates an index over those columns.

If you need to remove the nested set columns from an existing table, you may use the `NestedSet::dropColumns` helper:

```php
Schema::table('categories', function (Blueprint $table) {
    NestedSet::dropColumns($table);
});
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

The `HasNode` trait adds `parent`, `children`, `ancestors`, and `descendants` relationships:

```php
$category = Category::find(1);

$parent = $category->parent;

$children = $category->children;

$ancestors = $category->ancestors;

$descendants = $category->descendants;
```

You may eager load ancestors and descendants like any other Eloquent relationship:

```php
$categories = Category::with(['ancestors', 'descendants'])->get();
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

You may include each node's depth in the query result using `withDepth`:

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

The depth value is selected as a query alias. If you need to filter by depth, retrieve the results and filter the collection:

```php
$topTwoLevels = Category::withDepth()
    ->defaultOrder()
    ->get()
    ->filter(fn (Category $category): bool => $category->depth <= 2);
```

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
//     'oddness' => 0,
//     'duplicates' => 0,
//     'wrong_parent' => 0,
//     'missing_parent' => 0,
// ]
```

You may retrieve the total error count or check whether the tree is broken:

```php
$totalErrors = Category::getTotalErrors();

$isBroken = Category::isBroken();
```

<a name="fixing-existing-trees"></a>
### Fixing Existing Trees

The `fixTree` method repairs `_lft` and `_rgt` values using the existing `parent_id` values:

```php
if (Category::isBroken()) {
    $fixed = Category::fixTree();
}
```

You may also fix a subtree:

```php
$fixed = Category::fixSubtree($rootNode);
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

When querying a scoped tree by plain IDs, start from the `scoped` query:

```php
$items = MenuItem::scoped(['menu_id' => 1])
    ->defaultOrder()
    ->get();

$descendants = MenuItem::scoped(['menu_id' => 1])
    ->descendantsOf($nodeId);
```

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

Moving, inserting, deleting, and rebuilding nodes update boundary values across affected rows. If you perform several related tree changes, wrap them in a database transaction:

```php
DB::transaction(function () use ($electronics, $computers, $phones): void {
    $computers->appendToNode($electronics)->save();

    $phones->prependToNode($electronics)->save();
});
```

The `NestedSet::columns` helper creates an index for the nested set columns. If you use scoped trees, add indexes for the scope columns you query by most often.
