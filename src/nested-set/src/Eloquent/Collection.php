<?php

declare(strict_types=1);

namespace Hypervel\NestedSet\Eloquent;

use Hypervel\Database\Eloquent\Collection as BaseCollection;
use Hypervel\Database\Eloquent\Model;
use Hypervel\NestedSet\NestedSet;
use InvalidArgumentException;
use LogicException;

class Collection extends BaseCollection
{
    /**
     * Fill `parent` and `children` relationships for every node in the collection.
     * This will overwrite any previously set relations.
     */
    public function linkNodes(): static
    {
        if ($this->isEmpty()) {
            return $this;
        }

        [$groupedNodes, $roots] = $this->groupNodesByParent();

        return $this->linkNodesFromGroups($groupedNodes, $roots);
    }

    /**
     * Fill relations from nodes grouped by their parent IDs.
     */
    protected function linkNodesFromGroups(array $groupedNodes, array $roots): static
    {
        foreach ($this->items as $node) {
            $node->unsetRelation('parent');
            $node->unsetRelation('children');
        }

        foreach ($this->items as $node) {
            $parentId = $node->getParentId(); /* @phpstan-ignore method.notFound */

            if ($parentId === null) {
                $node->setRelation('parent', null);
            }

            $children = $this->nodesForParent($groupedNodes, $roots, $node->getKey());

            if ($children !== []) {
                $parent = clone $node;
                $parent->setRelations([]);

                foreach ($children as $child) {
                    $child->setRelation('parent', $parent);
                }
            }

            $node->setRelation('children', $node->newCollection($children));
        }

        return $this;
    }

    /**
     * Build a tree from a list of nodes. Each item will have set children relation.
     * To successfully build tree "id", "_lft" and "parent_id" keys must present.
     * If `$root` is provided, the tree will contain only descendants of that node.
     */
    public function toTree(Model|int|string|false|null $root = false): static
    {
        if ($this->isEmpty()) {
            return new static;
        }

        [$groupedNodes, $roots] = $this->groupNodesByParent();

        $this->linkNodesFromGroups($groupedNodes, $roots);

        return new static($this->nodesForParent(
            $groupedNodes,
            $roots,
            $this->getRootNodeId($root),
        ));
    }

    /**
     * Resolve the parent key used as the collection root.
     */
    protected function getRootNodeId(Model|int|string|false|null $root = false): int|string|null
    {
        if ($root instanceof Model) {
            if (! NestedSet::isNode($root)) {
                throw new InvalidArgumentException(sprintf(
                    'Model [%s] must be node.',
                    $root::class,
                ));
            }

            $key = $root->getKey();

            if ($key === null) {
                throw new LogicException(sprintf(
                    'Nested set tree building for [%s] requires the [%s] column on the supplied root.',
                    $root::class,
                    $root->getKeyName(),
                ));
            }

            return $key;
        }

        if ($root !== false) {
            return $root;
        }

        // If root node is not specified we take parent id of node with
        // least lft value as root node id.
        $leastValue = PHP_INT_MAX;
        $rootNodeId = null;

        foreach ($this->items as $node) {
            $lftName = $node->getLftName(); /* @phpstan-ignore method.notFound */

            if (! array_key_exists($lftName, $node->getAttributes())) {
                throw new LogicException(sprintf(
                    'Nested set tree building for [%s] requires the [%s] column in the projection.',
                    $node::class,
                    $lftName,
                ));
            }

            $lft = $node->getLft(); /* @phpstan-ignore method.notFound */

            if ($lft !== null && $lft < $leastValue) {
                $leastValue = $lft;
                $rootNodeId = $node->getParentId(); /* @phpstan-ignore method.notFound */
            }
        }

        return $rootNodeId;
    }

    /**
     * Build a list of nodes that retain the order that they were pulled from
     * the database.
     */
    public function toFlatTree(Model|int|string|false|null $root = false): static
    {
        $result = new static;

        if ($this->isEmpty()) {
            return $result;
        }

        [$groupedNodes, $roots] = $this->groupNodesByParent();

        return $result->flattenTree(
            $groupedNodes,
            $roots,
            $this->getRootNodeId($root),
        );
    }

    /**
     * Group nodes by parent while keeping roots separate from scalar keys.
     */
    protected function groupNodesByParent(): array
    {
        $groupedNodes = [];
        $roots = [];

        foreach ($this->items as $node) {
            if ($node->getKey() === null) {
                throw new LogicException(sprintf(
                    'Nested set tree building for [%s] requires the [%s] column in the projection.',
                    $node::class,
                    $node->getKeyName(),
                ));
            }

            $parentIdName = $node->getParentIdName(); /* @phpstan-ignore method.notFound */

            if (! array_key_exists($parentIdName, $node->getAttributes())) {
                throw new LogicException(sprintf(
                    'Nested set tree building for [%s] requires the [%s] column in the projection.',
                    $node::class,
                    $parentIdName,
                ));
            }

            $parentId = $node->getParentId(); /* @phpstan-ignore method.notFound */

            if ($parentId === null) {
                $roots[] = $node;
            } else {
                $groupedNodes[$parentId][] = $node;
            }
        }

        return [$groupedNodes, $roots];
    }

    /**
     * Flatten a tree without recursive stack growth.
     */
    protected function flattenTree(array $groupedNodes, array $roots, int|string|null $parentId): static
    {
        $stack = [];
        $children = $this->nodesForParent($groupedNodes, $roots, $parentId);

        for ($index = count($children) - 1; $index >= 0; --$index) {
            $stack[] = $children[$index];
        }

        while ($stack !== []) {
            $node = array_pop($stack);
            $this->push($node);

            $children = $this->nodesForParent($groupedNodes, $roots, $node->getKey());

            for ($index = count($children) - 1; $index >= 0; --$index) {
                $stack[] = $children[$index];
            }
        }

        return $this;
    }

    /**
     * Get nodes for a parent ID.
     */
    protected function nodesForParent(
        array $groupedNodes,
        array $roots,
        int|string|null $parentId,
    ): array {
        return $parentId === null
            ? $roots
            : ($groupedNodes[$parentId] ?? []);
    }
}
