# Hypervel Nested Set

[[toc]]

## Introduction

Most web applications need to store and work with hierarchical data at some point. Whether you're building a menu system, organizing product categories, or managing organizational structures, you'll eventually face the challenge of efficiently querying tree-like data in a relational database.

The Hypervel Nested Set package provides a robust solution for managing hierarchical data using the nested set model - a technique that stores tree structures in a way that makes retrieving entire subtrees, checking ancestry relationships, and performing complex hierarchical queries remarkably efficient. This package is ported from [Lazychaser's Nested Set package](https://github.com/lazychaser/laravel-nestedset) and optimized for the Hypervel framework. Unlike traditional parent-child relationships that require recursive queries or multiple database hits, nested sets allow you to fetch an entire category tree or determine if a node is an ancestor of another with a single, simple query.

This package handles all the complexity of maintaining the tree structure behind the scenes. When you move a node or insert new children, the package automatically recalculates the internal boundaries that make fast queries possible. You can focus on your application logic while the package ensures your tree data remains consistent and performant.

### When to Use Nested Set

**Best for:**
- Menu systems and navigation hierarchies
- Category trees (product categories, content taxonomies)
- Organizational charts and reporting structures
- Comment threads and discussions
- File/folder hierarchies
- Geographic hierarchies (country > state > city)

**Consider alternatives when:**
- Tree structure changes frequently (many insertions/updates)
- You primarily need parent-child relationships without deep querying
- Simple adjacency lists are sufficient for your use case

## Installation

Install the package via Composer:

```bash
composer require hypervel/nested-set
```

## Setup

### Database Migration

Add the nested set columns to your table using the provided helper:

```php
use Hyperf\Database\Schema\Blueprint;
use Hypervel\Database\Migrations\Migration;
use Hypervel\NestedSet\NestedSet;
use Hypervel\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();

            // Add nested set columns
            NestedSet::columns($table);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            NestedSet::dropColumns($table);
        });

        Schema::dropIfExists('categories');
    }
};
```

This creates the following columns:
- `_lft` (unsigned integer) - Left boundary
- `_rgt` (unsigned integer) - Right boundary
- `parent_id` (unsigned integer, nullable) - Parent node ID

### Model Setup

Add the `HasNode` trait to your model:

```php
namespace App\Models;

use Hypervel\Database\Eloquent\Model;
use Hypervel\NestedSet\HasNode;

class Category extends Model
{
    use HasNode;

    protected array $fillable = [
        'name',
        'description',
    ];
}
```

## Basic Usage

### Creating Root Nodes

```php
use App\Models\Category;

// Create a root node
$electronics = Category::create([
    'name' => 'Electronics'
]);

// Or explicitly make it root
$clothing = new Category(['name' => 'Clothing']);
$clothing->saveAsRoot();
```

### Creating Child Nodes

```php
// Append to parent (becomes last child)
$computers = new Category(['name' => 'Computers']);
$electronics->appendNode($computers);

// Prepend to parent (becomes first child)
$phones = new Category(['name' => 'Phones']);
$electronics->prependNode($phones);

// Alternative syntax
$laptops = Category::create(['name' => 'Laptops']);
$laptops->appendToNode($computers);

// Create with parent relationship
$tablets = Category::create([
    'name' => 'Tablets',
], $electronics);
```

### Moving Nodes

```php
// Move node to different parent
$laptops->appendToNode($electronics);

// Insert before another node
$smartphones = Category::create(['name' => 'Smartphones']);
$smartphones->beforeNode($tablets);

// Insert after another node
$smartwatches = Category::create(['name' => 'Smartwatches']);
$smartwatches->afterNode($smartphones);

// Move up/down among siblings
$laptops->up();    // Move up one position
$tablets->down(2); // Move down two positions
```

## Retrieving Nodes

### Basic Relationships

```php
$category = Category::find(1);

// Get immediate parent
$parent = $category->parent;

// Get immediate children
$children = $category->children;

// Check if node has relationships
if ($category->isRoot()) {
    // This is a root node
}

if ($category->isLeaf()) {
    // This node has no children
}
```

### Ancestors and Descendants

```php
// Get all ancestors (parents, grandparents, etc.)
$ancestors = $category->ancestors()->get();
$ancestors = $category->getAncestors(); // Alternative

// Get ancestors including self
$ancestorsAndSelf = $category->ancestorsAndSelf();

// Get all descendants (children, grandchildren, etc.)
$descendants = $category->descendants()->get();
$descendants = $category->getDescendants(); // Alternative

// Get descendants including self
$descendantsAndSelf = $category->descendantsAndSelf();

// Get only direct children
$directChildren = $category->children()->get();
```

### Siblings

```php
// Get siblings (nodes with same parent)
$siblings = $category->siblings()->get();
$siblings = $category->getSiblings(); // Alternative

// Get siblings including self
$siblingsAndSelf = $category->getSiblingsAndSelf();

// Get next/previous siblings
$nextSiblings = $category->getNextSiblings();
$prevSiblings = $category->getPrevSiblings();

// Get immediate next/previous sibling
$nextSibling = $category->getNextSibling();
$prevSibling = $category->getPrevSibling();
```

### Advanced Queries

```php
// Get root nodes only
$roots = Category::whereIsRoot()->get();

// Get leaf nodes only (no children)
$leaves = Category::whereIsLeaf()->get();

// Get nodes with children
$parents = Category::hasChildren()->get();

// Get ancestors of specific node
$ancestors = Category::ancestorsOf($categoryId);

// Get descendants of specific node
$descendants = Category::descendantsOf($categoryId);

// Get nodes after specific node
$afterNodes = Category::whereIsAfter($category)->get();

// Get nodes before specific node
$beforeNodes = Category::whereIsBefore($category)->get();
```

## Tree Operations

### Building Trees

#### From Nested Arrays

```php
$treeData = [
    'name' => 'Electronics',
    'children' => [
        [
            'name' => 'Computers',
            'children' => [
                ['name' => 'Laptops'],
                ['name' => 'Desktops'],
            ]
        ],
        [
            'name' => 'Phones',
            'children' => [
                ['name' => 'iPhones'],
                ['name' => 'Android Phones'],
            ]
        ]
    ]
];

// Create entire tree structure
$electronics = Category::create($treeData);
```

#### Rebuilding Trees

```php
// Rebuild tree from array data
$categories = [
    ['id' => 1, 'name' => 'Electronics', 'parent_id' => null],
    ['id' => 2, 'name' => 'Computers', 'parent_id' => 1],
    ['id' => 3, 'name' => 'Laptops', 'parent_id' => 2],
    ['id' => 4, 'name' => 'Phones', 'parent_id' => 1],
];

Category::rebuildTree($categories);

// Rebuild specific subtree
Category::rebuildSubtree($electronics, $subtreeData);
```

### Tree Collections

The package provides specialized collection methods for working with tree data:

```php
$categories = Category::get();

// Link parent-child relationships
$categories->linkNodes();

// Convert flat collection to nested tree
$tree = $categories->toTree();

// Convert to flat tree (ordered hierarchy)
$flatTree = $categories->toFlatTree();
```

### Tree Validation and Repair

```php
// Check for tree errors
$errors = Category::countErrors();
/*
Returns array with error counts:
[
    'oddness' => 0,          // Invalid lft/rgt values
    'duplicates' => 0,       // Duplicate lft/rgt values
    'wrong_parent' => 0,     // Incorrect parent relationships
    'missing_parent' => 0    // Missing parent nodes
]
*/

// Get total error count
$totalErrors = Category::getTotalErrors();

// Check if tree is broken
if (Category::isBroken()) {
    // Fix the entire tree
    $fixed = Category::fixTree();
    echo "Fixed {$fixed} nodes";
}

// Fix specific subtree
$fixed = Category::fixSubtree($rootNode);
```

## Advanced Features

### Depth Information

```php
// Include depth level in results
$categoriesWithDepth = Category::withDepth()->get();

foreach ($categoriesWithDepth as $category) {
    echo str_repeat('  ', $category->depth) . $category->name;
}

// Alternative: calculate depth manually
$depth = $category->ancestors()->count();
```

### Scoped Trees

For multiple independent trees in the same table:

```php
class Category extends Model
{
    use HasNode;

    // Define scope attributes
    protected function getScopeAttributes(): array
    {
        return ['company_id']; // Separate trees per company
    }
}

// Usage
$companyCategories = Category::scoped(['company_id' => 1])
    ->defaultOrder()
    ->get();

// All operations automatically respect scope
$electronics = Category::create([
    'name' => 'Electronics',
    'company_id' => 1
]);

$computers = Category::create([
    'name' => 'Computers',
    'company_id' => 1
]);

$computers->appendToNode($electronics); // Only works within same scope
```

### Soft Delete Support

The package automatically handles soft deletes:

```php
class Category extends Model
{
    use HasNode, SoftDeletes;
}

// Deleting a node soft-deletes all descendants
$electronics->delete(); // Soft deletes entire subtree

// Restoring a node restores all descendants deleted with it
$electronics->restore(); // Restores entire subtree

// Force delete removes from tree structure
$electronics->forceDelete(); // Permanently removes and adjusts tree
```

### Custom Node Operations

```php
class Category extends Model
{
    use HasNode;

    // Custom method using tree relationships
    public function getPath(string $separator = ' > '): string
    {
        return $this->ancestorsAndSelf()
            ->pluck('name')
            ->implode($separator);
    }

    // Get total product count including subcategories
    public function getTotalProductCount(): int
    {
        return $this->descendantsAndSelf()
            ->withCount('products')
            ->sum('products_count');
    }

    // Check if category can be deleted
    public function canDelete(): bool
    {
        return $this->products()->count() === 0 &&
               $this->descendants()->count() === 0;
    }
}

// Usage
echo $category->getPath(); // "Electronics > Computers > Laptops"
$totalProducts = $category->getTotalProductCount();
```

## Query Optimization

### Eager Loading

```php
// Load tree with relationships
$categories = Category::with([
    'children',
    'parent',
    'products'
])->get();

// Load ancestors for multiple nodes efficiently
$categories = Category::with('ancestors')->get();
```

### Ordering Results

```php
// Default tree order (by left boundary)
$categories = Category::defaultOrder()->get();

// Reverse order
$categories = Category::defaultOrder('desc')->get();
// or
$categories = Category::reversed()->get();

// Custom ordering with depth
$categories = Category::withDepth()
    ->orderBy('depth')
    ->orderBy('name')
    ->get();
```

### Limiting Results

```php
// Get only root level
$roots = Category::whereIsRoot()
    ->withDepth()
    ->where('depth', 0)
    ->get();

// Get specific depth levels
$topTwoLevels = Category::withDepth()
    ->where('depth', '<=', 2)
    ->get();

// Exclude root from results
$nonRoots = Category::withoutRoot()->get();
// or
$hasParent = Category::hasParent()->get();
```

## Tree Rendering Examples

### Simple Nested List

```php
function renderTree($nodes, $depth = 0): string
{
    $html = $depth === 0 ? '<ul>' : '<ul>';

    foreach ($nodes as $node) {
        $html .= '<li>';
        $html .= '<a href="/category/' . $node->id . '">' . $node->name . '</a>';

        if ($node->children->isNotEmpty()) {
            $html .= renderTree($node->children, $depth + 1);
        }

        $html .= '</li>';
    }

    $html .= '</ul>';
    return $html;
}

// Usage
$tree = Category::with('children')->whereIsRoot()->get();
echo renderTree($tree);
```

### Breadcrumb Navigation

```php
function renderBreadcrumb($category): string
{
    $ancestors = $category->ancestorsAndSelf()->get();

    $breadcrumb = '<nav><ol>';

    foreach ($ancestors as $ancestor) {
        $breadcrumb .= '<li>';

        if ($ancestor->id === $category->id) {
            $breadcrumb .= '<span>' . $ancestor->name . '</span>';
        } else {
            $breadcrumb .= '<a href="/category/' . $ancestor->id . '">' . $ancestor->name . '</a>';
        }

        $breadcrumb .= '</li>';
    }

    $breadcrumb .= '</ol></nav>';
    return $breadcrumb;
}
```

### Tree Select Dropdown

```php
function renderSelectOptions($categories, $selectedId = null): string
{
    $options = '';

    foreach ($categories as $category) {
        $indent = str_repeat('&nbsp;&nbsp;&nbsp;', $category->depth);
        $selected = $category->id == $selectedId ? ' selected' : '';

        $options .= '<option value="' . $category->id . '"' . $selected . '>';
        $options .= $indent . $category->name;
        $options .= '</option>';
    }

    return $options;
}

// Usage
$allCategories = Category::withDepth()->defaultOrder()->get();
echo '<select name="category_id">' . renderSelectOptions($allCategories, $selectedId) . '</select>';
```

## Performance Considerations

### Advantages of Nested Set

- **Fast subtree queries**: Get all descendants in a single query
- **Efficient ancestor paths**: Get full path with one query
- **Simple depth calculations**: Depth is calculable from tree structure
- **Fast leaf detection**: Leaves are `rgt = lft + 1`

### Disadvantages

- **Complex updates**: Moving nodes requires updating multiple records
- **Insert overhead**: Adding nodes requires updating many `lft`/`rgt` values
- **Storage overhead**: Requires two additional integer columns

### Optimization Tips

1. **Use appropriate indexes**:
```sql
-- Add indexes for performance
CREATE INDEX idx_lft_rgt ON categories (_lft, _rgt);
CREATE INDEX idx_parent_id ON categories (parent_id);
```

2. **Batch operations**:
```php
// Instead of multiple single inserts, use batch operations
Category::rebuildTree($treeData);
```

3. **Cache frequently accessed trees**:
```php
// Cache menu trees that don't change often
$menu = Cache::remember('main-menu', 3600, function () {
    return Category::whereIn('type', ['menu'])
        ->with('children')
        ->whereIsRoot()
        ->get();
});
```

4. **Use scoped queries when possible**:
```php
// Limit queries to specific scopes
$userCategories = Category::scoped(['user_id' => $userId])
    ->defaultOrder()
    ->get();
```

## Common Patterns

### Menu System

```php
class MenuItem extends Model
{
    use HasNode;

    protected array $fillable = [
        'title', 'url', 'icon', 'is_active'
    ];

    // Get active menu items only
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    // Get menu with all active descendants
    public function getActiveMenu()
    {
        return $this->descendants()
            ->active()
            ->with('children')
            ->get()
            ->toTree();
    }
}
```

### Forum Categories

```php
class ForumCategory extends Model
{
    use HasNode;

    public function topics()
    {
        return $this->hasMany(Topic::class);
    }

    public function posts()
    {
        return $this->hasManyThrough(Post::class, Topic::class);
    }

    // Get stats including subcategories
    public function getStatsAttribute(): array
    {
        $descendants = $this->descendantsAndSelf()->pluck('id');

        return [
            'topics_count' => Topic::whereIn('category_id', $descendants)->count(),
            'posts_count' => Post::whereHas('topic', function ($query) use ($descendants) {
                $query->whereIn('category_id', $descendants);
            })->count(),
        ];
    }
}
```

### Product Categories with Filters

```php
class ProductCategory extends Model
{
    use HasNode;

    public function products()
    {
        return $this->hasMany(Product::class, 'category_id');
    }

    public function filters()
    {
        return $this->hasMany(CategoryFilter::class);
    }

    // Get all products in category and subcategories
    public function getAllProducts()
    {
        $categoryIds = $this->descendantsAndSelf()->pluck('id');

        return Product::whereIn('category_id', $categoryIds);
    }

    // Get inherited filters from ancestors
    public function getAvailableFilters()
    {
        $ancestorIds = $this->ancestorsAndSelf()->pluck('id');

        return CategoryFilter::whereIn('category_id', $ancestorIds)
            ->where('is_active', true)
            ->get();
    }
}
```