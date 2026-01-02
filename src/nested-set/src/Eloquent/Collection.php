<?php

declare(strict_types=1);

namespace Hypervel\NestedSet\Eloquent;

use Hypervel\Database\Eloquent\Collection as BaseCollection;
use Hypervel\NestedSet\NestedSet;

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

        /* @phpstan-ignore-next-line */
        $groupedNodes = $this->groupBy($this->first()->getParentIdName());

        foreach ($this->items as $node) {
            /* @phpstan-ignore-next-line */
            if (! $node->getParentId()) {
                $node->setRelation('parent', null);
            }

            $children = $groupedNodes->get($node->getKey(), []);
            foreach ($children as $child) { // @phpstan-ignore foreach.emptyArray
                $child->setRelation('parent', $node);
            }

            $node->setRelation('children', BaseCollection::make($children));
        }

        return $this;
    }

    /**
     * Build a tree from a list of nodes. Each item will have set children relation.
     * To successfully build tree "id", "_lft" and "parent_id" keys must present.
     * If `$root` is provided, the tree will contain only descendants of that node.
     */
    public function toTree(mixed $root = false): static
    {
        if ($this->isEmpty()) {
            return new static();
        }

        $this->linkNodes();

        $items = [];
        $root = $this->getRootNodeId($root);

        foreach ($this->items as $node) {
            /* @phpstan-ignore-next-line */
            if ($node->getParentId() == $root) {
                $items[] = $node;
            }
        }

        return new static($items);
    }

    protected function getRootNodeId(mixed $root = false): int
    {
        if (NestedSet::isNode($root)) {
            return $root->getKey();
        }

        if ($root !== false) {
            return $root;
        }

        // If root node is not specified we take parent id of node with
        // least lft value as root node id.
        $leastValue = null;

        foreach ($this->items as $node) {
            /* @phpstan-ignore-next-line */
            if ($leastValue === null || $node->getLft() < $leastValue) {
                $leastValue = $node->getLft(); /* @phpstan-ignore-line */
                $root = $node->getParentId(); /* @phpstan-ignore-line */
            }
        }

        return $root;
    }

    /**
     * Build a list of nodes that retain the order that they were pulled from
     * the database.
     */
    public function toFlatTree(bool $root = false): static
    {
        $result = new static();

        if ($this->isEmpty()) {
            return $result;
        }

        /* @phpstan-ignore-next-line */
        $groupedNodes = $this->groupBy($this->first()->getParentIdName());

        return $result->flattenTree($groupedNodes, $this->getRootNodeId($root));
    }

    /**
     * Flatten a tree into a non recursive array.
     */
    protected function flattenTree(Collection $groupedNodes, mixed $parentId): static
    {
        foreach ($groupedNodes->get($parentId, []) as $node) { // @phpstan-ignore foreach.emptyArray
            $this->push($node);

            $this->flattenTree($groupedNodes, $node->getKey());
        }

        return $this;
    }
}
