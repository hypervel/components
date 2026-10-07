NestedSet for Hypervel
===

[![Ask DeepWiki](https://deepwiki.com/badge.svg)](https://deepwiki.com/hypervel/nested-set)

Documentation: https://hypervel.org/docs/nested-set

## Differences From laravel-nestedset

Migrations add the nested set columns and indexes with one Blueprint macro matching the table's primary key: `nestedSet()` for `id()`, `integerNestedSet()` for `increments()`, `uuidNestedSet()` or `ulidNestedSet()`. Each macro includes the `depth` column and accepts the tree's scope columns, which prefix every index; `dropNestedSet()` accepts the same scope columns. The matching `NestedSet` static methods take scope columns instead of a key column name and type. Upstream's `nestedSetDepth()`, `nestedSetIndex()`, `dropNestedSetDepth()` and `dropNestedSetIndex()` macros and their `NestedSet` methods are not available. For a custom index layout, define the columns and indexes with ordinary Blueprint methods.

The `depth` column is required. `withDepth()` reads the stored depth instead of counting ancestors in a subquery, so global scopes that hide ancestors do not change a node's depth.

Only a `null` parent ID makes a node a root. Upstream also treats `0` and an empty string as root parent IDs; here they are ordinary parent keys, so a node whose key is `0` can have children.

The query builder's `getDepth($position)` is `depthForPosition($position)`, which returns the depth of a node inserted at that position within the selected tree. Upstream's method returns the enclosing node's depth, so it cannot tell a position inside a root from one outside every node.

Ported from: https://github.com/aimeos/laravel-nestedset
