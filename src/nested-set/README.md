NestedSet for Hypervel
===

[![Ask DeepWiki](https://deepwiki.com/badge.svg)](https://deepwiki.com/hypervel/nested-set)

Documentation: https://hypervel.org/docs/nested-set

## Differences From laravel-nestedset

Migrations add the nested set columns and indexes with one Blueprint macro matching the table's primary key: `nestedSet()` for `id()`, `integerNestedSet()` for `increments()`, `uuidNestedSet()` or `ulidNestedSet()`. Each macro includes the `depth` column and accepts the tree's scope columns, which prefix every index; `dropNestedSet()` accepts the same scope columns. The matching `NestedSet` static methods take scope columns instead of a key column name and type. Upstream's `nestedSetDepth()`, `nestedSetIndex()`, `dropNestedSetDepth()` and `dropNestedSetIndex()` macros and their `NestedSet` methods are not available. For a custom index layout, define the columns and indexes with ordinary Blueprint methods.

The `depth` column is required. `withDepth()` reads the stored depth instead of counting ancestors in a subquery, so global scopes that hide ancestors do not change a node's depth.

Only a `null` parent ID makes a node a root. Upstream also treats `0` and an empty string as root parent IDs; here they are ordinary parent keys, so a node whose key is `0` can have children. Passing `null`, `0` or an empty string to `toTree()` or `toFlatTree()` builds from the nodes with that parent ID. Aimeos infers the root from `0` or an empty string, as it does when no root is given, and does not accept `null`.

The query builder's `getDepth($position)` is `depthForPosition($position)`, which returns the depth of a node inserted at that position within the selected tree. Upstream's method returns the enclosing node's depth, so it cannot tell a position inside a root from one outside every node.

`getAncestors()`, `getDescendants()` and `getSiblings()` always run a fresh query and leave loaded relations unchanged. When all columns are requested, upstream returns the loaded `ancestors`, `descendants` or `siblings` relation, or stores the query result as that relation. Use the relation properties, such as `$node->ancestors`, to reuse loaded results.

Eager loading constrains and matches all parents together, so eager-loaded ancestors are matched in one pass over the sorted results instead of a scan of the earlier results for each parent. Relation subclasses implement `constrainEagerModels()`, which receives the base query builder and the prepared parent models, and `matchMany()`. These replace upstream's per-parent hooks: `addEagerConstraint()` is no longer abstract, and `matches()`, `matchForModel()`, `indexResults()`, `matchFromIndex()`, `preservesResultOrder()` and `getEagerModelKey()` are not available.

Ported from: https://github.com/aimeos/laravel-nestedset
