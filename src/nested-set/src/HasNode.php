<?php

declare(strict_types=1);

namespace Hypervel\NestedSet;

use Carbon\Carbon;
use Exception;
use Hyperf\Database\Model\Model;
use Hyperf\Database\Model\Relations\BelongsTo;
use Hyperf\Database\Model\Relations\HasMany;
use Hyperf\Database\Query\Builder as HyperfQueryBuilder;
use Hypervel\NestedSet\Eloquent\AncestorsRelation;
use Hypervel\NestedSet\Eloquent\Collection;
use Hypervel\NestedSet\Eloquent\DescendantsRelation;
use Hypervel\NestedSet\Eloquent\QueryBuilder;
use Hypervel\Support\Arr;
use LogicException;

/**
 * @template TModel of Model
 *
 * @property int $parent_id
 * @property ?static $parent
 */
trait HasNode
{
    /**
     * Pending operations.
     */
    protected array $pending = [];

    /**
     * Whether the node has moved since last save.
     */
    protected bool $moved = false;

    /**
     * Whether the node has soft delete.
     */
    protected static ?bool $hasSoftDelete = null;

    /**
     * Bootstrap node events.
     */
    public static function bootHasNode(): void
    {
        static::registerCallback(
            'saving',
            fn ($model) => $model->callPendingActions()
        );

        static::registerCallback(
            'deleting',
            fn ($model) => $model->refreshNode()
        );

        static::registerCallback(
            'deleted',
            fn ($model) => $model->deleteDescendants()
        );

        if (static::usesSoftDelete()) {
            static::registerCallback(
                'restoring',
                fn ($model) => NodeContext::keepDeletedAt($model)
            );
            static::registerCallback(
                'restored',
                fn ($model) => $model->restoreDescendants(NodeContext::restoreDeletedAt($model))
            );
        }
    }

    /**
     * Set an action.
     */
    protected function setNodeAction(string $action, mixed ...$args): static
    {
        $this->pending = [$action, ...$args];

        return $this;
    }

    /**
     * Call pending action.
     */
    protected function callPendingActions(): void
    {
        $this->moved = false;

        if (! $this->pending && ! $this->exists) {
            $this->makeRoot();
        }

        if (! $this->pending) {
            return;
        }

        $method = 'action' . ucfirst(array_shift($this->pending));
        $parameters = $this->pending;

        $this->pending = [];

        $this->moved = call_user_func_array([$this, $method], $parameters);
    }

    public static function usesSoftDelete(): bool
    {
        if (! is_null(static::$hasSoftDelete)) {
            return static::$hasSoftDelete;
        }

        return static::$hasSoftDelete = method_exists(new static(), 'bootSoftDeletes');
    }

    protected function actionRaw(): bool
    {
        return true;
    }

    /**
     * Make a root node.
     */
    protected function actionRoot(): bool
    {
        // Simplest case that do not affect other nodes.
        if (! $this->exists) {
            $cut = $this->getLowerBound() + 1;

            $this->setLft($cut);
            $this->setRgt($cut + 1);

            return true;
        }

        return $this->insertAt($this->getLowerBound() + 1);
    }

    /**
     * Get the lower bound.
     */
    protected function getLowerBound(): int
    {
        return (int) $this->newNestedSetQuery()->max($this->getRgtName());
    }

    /**
     * Append or prepend a node to the parent.
     */
    protected function actionAppendOrPrepend(self $parent, bool $prepend = false): bool
    {
        $parent->refreshNode();
        $cut = $prepend ? $parent->getLft() + 1 : $parent->getRgt();

        if (! $this->insertAt($cut)) {
            return false;
        }

        $parent->refreshNode();

        return true;
    }

    /**
     * Apply parent model.
     */
    protected function setParent(?Model $value): static
    {
        $this->setParentId($value ? $value->getKey() : null)
            ->setRelation('parent', $value);

        return $this;
    }

    /**
     * Insert node before or after another node.
     */
    protected function actionBeforeOrAfter(self $node, bool $after = false): bool
    {
        $node->refreshNode();

        return $this->insertAt($after ? $node->getRgt() + 1 : $node->getLft());
    }

    /**
     * Refresh node's crucial attributes.
     */
    public function refreshNode(): void
    {
        if (! $this->exists || ! NodeContext::hasPerformed($this)) {
            return;
        }

        $attributes = $this->newNestedSetQuery()->getNodeData($this->getKey());

        $this->attributes = array_merge($this->attributes, $attributes);
    }

    /**
     * Relation to the parent.
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(get_class($this), $this->getParentIdName())
            ->setModel($this);
    }

    /**
     * Relation to children.
     */
    public function children(): HasMany
    {
        return $this->hasMany(get_class($this), $this->getParentIdName())
            ->setModel($this);
    }

    /**
     * Get query for descendants of the node.
     */
    public function descendants(): DescendantsRelation
    {
        return new DescendantsRelation($this->newQuery(), $this);
    }

    /**
     * Get query for siblings of the node.
     */
    public function siblings(): QueryBuilder
    {
        return $this->newScopedQuery()
            ->where($this->getKeyName(), '<>', $this->getKey())
            ->where($this->getParentIdName(), '=', $this->getParentId());
    }

    /**
     * Get the node siblings and the node itself.
     */
    public function siblingsAndSelf(): QueryBuilder
    {
        return $this->newScopedQuery()
            ->where($this->getParentIdName(), '=', $this->getParentId());
    }

    /**
     * Get query for the node siblings and the node itself.
     *
     * @return Collection<int, TModel>
     */
    public function getSiblingsAndSelf(array $columns = ['*']): Collection
    {
        return $this->siblingsAndSelf()->get($columns);
    }

    /**
     * Get query for siblings after the node.
     */
    public function nextSiblings(): QueryBuilder
    {
        return $this->nextNodes()
            ->where($this->getParentIdName(), '=', $this->getParentId());
    }

    /**
     * Get query for siblings before the node.
     */
    public function prevSiblings(): QueryBuilder
    {
        return $this->prevNodes()
            ->where($this->getParentIdName(), '=', $this->getParentId());
    }

    /**
     * Get query for nodes after current node.
     */
    public function nextNodes(): QueryBuilder
    {
        return $this->newScopedQuery()
            ->where($this->getLftName(), '>', $this->getLft());
    }

    /**
     * Get query for nodes before current node in reversed order.
     */
    public function prevNodes(): QueryBuilder
    {
        return $this->newScopedQuery()
            ->where($this->getLftName(), '<', $this->getLft());
    }

    /**
     * Get query ancestors of the node.
     */
    public function ancestors(): AncestorsRelation
    {
        return new AncestorsRelation($this->newQuery(), $this);
    }

    /**
     * Make this node a root node.
     */
    public function makeRoot(): static
    {
        $this->setParent(null)->dirtyBounds();

        return $this->setNodeAction('root');
    }

    /**
     * Save node as root.
     */
    public function saveAsRoot(): bool
    {
        if ($this->exists && $this->isRoot()) {
            return $this->save();
        }

        return $this->makeRoot()->save();
    }

    /**
     * Append and save a node.
     */
    public function appendNode(self $node): bool
    {
        return $node->appendToNode($this)->save();
    }

    /**
     * Prepend and save a node.
     */
    public function prependNode(self $node): bool
    {
        return $node->prependToNode($this)->save();
    }

    /**
     * Append a node to the new parent.
     */
    public function appendToNode(self $parent): static
    {
        return $this->appendOrPrependTo($parent);
    }

    /**
     * Prepend a node to the new parent.
     */
    public function prependToNode(self $parent): static
    {
        return $this->appendOrPrependTo($parent, true);
    }

    public function appendOrPrependTo(self $parent, bool $prepend = false): static
    {
        $this->assertNodeExists($parent)
            ->assertNotDescendant($parent)
            ->assertSameScope($parent);

        $this->setParent($parent)->dirtyBounds();

        return $this->setNodeAction('appendOrPrepend', $parent, $prepend);
    }

    /**
     * Insert self after a node.
     */
    public function afterNode(self $node): static
    {
        return $this->beforeOrAfterNode($node, true);
    }

    /**
     * Insert self before node.
     */
    public function beforeNode(self $node): static
    {
        return $this->beforeOrAfterNode($node);
    }

    public function beforeOrAfterNode(self $node, bool $after = false): static
    {
        $this->assertNodeExists($node)
            ->assertNotDescendant($node)
            ->assertSameScope($node);

        if (! $this->isSiblingOf($node)) {
            $this->setParent($node->getRelationValue('parent'));
        }

        $this->dirtyBounds();

        return $this->setNodeAction('beforeOrAfter', $node, $after);
    }

    /**
     * Insert self after a node and save.
     */
    public function insertAfterNode(self $node): bool
    {
        return $this->afterNode($node)->save();
    }

    /**
     * Insert self before a node and save.
     */
    public function insertBeforeNode(self $node): bool
    {
        if (! $this->beforeNode($node)->save()) {
            return false;
        }

        // We'll update the target node since it will be moved
        $node->refreshNode();

        return true;
    }

    public function rawNode(mixed $lft, mixed $rgt, mixed $parentId): static
    {
        $this->setLft($lft)->setRgt($rgt)->setParentId($parentId);

        return $this->setNodeAction('raw');
    }

    /**
     * Move node up given amount of positions.
     */
    public function up(int $amount = 1): bool
    {
        $sibling = $this->prevSiblings()
            ->defaultOrder('desc')
            ->skip($amount - 1)
            ->first();

        if (! $sibling) {
            return false;
        }

        return $this->insertBeforeNode($sibling);
    }

    /**
     * Move node down given amount of positions.
     */
    public function down(int $amount = 1): bool
    {
        $sibling = $this->nextSiblings()
            ->defaultOrder()
            ->skip($amount - 1)
            ->first();

        if (! $sibling) {
            return false;
        }

        return $this->insertAfterNode($sibling);
    }

    /**
     * Insert node at specific position.
     */
    protected function insertAt(int $position): bool
    {
        NodeContext::setHasPerformed($this);

        return $this->exists
            ? $this->moveNode($position)
            : $this->insertNode($position);
    }

    /**
     * Move a node to the new position.
     */
    protected function moveNode(int $position): bool
    {
        $updated = $this->newNestedSetQuery()
            ->moveNode($this->getKey(), $position) > 0;

        if ($updated) {
            $this->refreshNode();
        }

        return $updated;
    }

    /**
     * Insert new node at specified position.
     */
    protected function insertNode(int $position): bool
    {
        $this->newNestedSetQuery()->makeGap($position, 2);

        $height = $this->getNodeHeight();

        $this->setLft($position);
        $this->setRgt($position + $height - 1);

        return true;
    }

    /**
     * Update the tree when the node is removed physically.
     */
    protected function deleteDescendants(): void
    {
        $lft = $this->getLft();
        $rgt = $this->getRgt();

        $method = $this->usesSoftDelete() && $this->forceDeleting
            ? 'forceDelete'
            : 'delete';

        $this->descendants()->{$method}();

        if ($this->hasForceDeleting()) {
            $height = $rgt - $lft + 1;

            $this->newNestedSetQuery()->makeGap($rgt + 1, -$height);

            // In case if user wants to re-create the node
            $this->makeRoot();

            NodeContext::setHasPerformed($this);
        }
    }

    /**
     * Restore the descendants.
     */
    protected function restoreDescendants(Carbon|string $deletedAt): void
    {
        $this->descendants()
            ->where($this->getDeletedAtColumn(), '>=', $deletedAt)
            ->restore();
    }

    /**
     * Create a new Model query builder for the model.
     *
     * @param HyperfQueryBuilder $query
     */
    public function newModelBuilder($query): QueryBuilder
    {
        return new QueryBuilder($query);
    }

    /**
     * Get a new base query that includes deleted nodes.
     */
    public function newNestedSetQuery(?string $table = null): mixed
    {
        $builder = $this->usesSoftDelete()
            ? $this->withTrashed()
            : $this->newQuery();

        return $this->applyNestedSetScope($builder, $table);
    }

    public function newScopedQuery(?string $table = null): mixed
    {
        return $this->applyNestedSetScope($this->newQuery(), $table);
    }

    public function applyNestedSetScope(mixed $query, ?string $table = null): mixed
    {
        if (! $scoped = $this->getScopeAttributes()) {
            return $query;
        }

        if (! $table) {
            $table = $this->getTable();
        }

        foreach ($scoped as $attribute) {
            $query->where(
                $table . '.' . $attribute,
                '=',
                $this->getAttributeValue($attribute)
            );
        }

        return $query;
    }

    protected function getScopeAttributes(): array
    {
        return [];
    }

    public static function scoped(array $attributes): mixed
    {
        $instance = new static();

        $instance->setRawAttributes($attributes);

        return $instance->newScopedQuery();
    }

    /**
     * @return Collection<int, TModel>
     */
    public function newCollection(array $models = []): Collection
    {
        return new Collection($models);
    }

    /**
     * Use `children` key on `$attributes` to create child nodes.
     */
    public static function create(array $attributes = [], ?self $parent = null): ?static
    {
        $children = Arr::pull($attributes, 'children');

        $instance = new static($attributes);

        if ($parent) {
            $instance->appendToNode($parent);
        }

        $instance->save();

        $relation = new Collection();

        foreach ((array) $children as $child) {
            $relation->add($child = static::create($child, $instance));

            $child->setRelation('parent', $instance);
        }

        $instance->refreshNode();

        return $instance->setRelation('children', $relation);
    }

    /**
     * Get node height (rgt - lft + 1).
     */
    public function getNodeHeight(): int
    {
        if (! $this->exists) {
            return 2;
        }

        return $this->getRgt() - $this->getLft() + 1;
    }

    /**
     * Get number of descendant nodes.
     */
    public function getDescendantCount(): int
    {
        return (int) ceil($this->getNodeHeight() / 2) - 1;
    }

    /**
     * Set the value of model's parent id key.
     * Behind the scenes node is appended to found parent node.
     *
     * @throws Exception If parent node doesn't exists
     */
    public function setParentIdAttribute(?int $value): void
    {
        if ($this->getParentId() == $value) {
            return;
        }

        if ($value) {
            $this->appendToNode($this->newScopedQuery()->findOrFail($value));
        } else {
            $this->makeRoot();
        }
    }

    /**
     * Get whether node is root.
     */
    public function isRoot(): bool
    {
        return is_null($this->getParentId());
    }

    public function isLeaf(): bool
    {
        return $this->getLft() + 1 == $this->getRgt();
    }

    /**
     * Get the lft key name.
     */
    public function getLftName(): string
    {
        return NestedSet::LFT;
    }

    /**
     * Get the rgt key name.
     */
    public function getRgtName(): string
    {
        return NestedSet::RGT;
    }

    /**
     * Get the parent id key name.
     */
    public function getParentIdName(): string
    {
        return NestedSet::PARENT_ID;
    }

    /**
     * Get the value of the model's lft key.
     */
    public function getLft(): ?int
    {
        $value = $this->getAttributeValue($this->getLftName());

        return is_null($value) ? null : (int) $value;
    }

    /**
     * Get the value of the model's rgt key.
     */
    public function getRgt(): ?int
    {
        $value = $this->getAttributeValue($this->getRgtName());

        return is_null($value) ? null : (int) $value;
    }

    /**
     * Get the value of the model's parent id key.
     */
    public function getParentId(): ?int
    {
        return $this->getAttributeValue($this->getParentIdName());
    }

    /**
     * Returns node that is next to current node without constraining to siblings.
     * This can be either a next sibling or a next sibling of the parent node.
     *
     * @return TModel|null
     */
    public function getNextNode(array $columns = ['*']): mixed
    {
        return $this->nextNodes()->defaultOrder()->first($columns);
    }

    /**
     * Returns node that is before current node without constraining to siblings.
     * This can be either a prev sibling or parent node.
     *
     * @return TModel|null
     */
    public function getPrevNode(array $columns = ['*']): mixed
    {
        return $this->prevNodes()->defaultOrder('desc')->first($columns);
    }

    /**
     * @return Collection<int, TModel>
     */
    public function getAncestors(array $columns = ['*']): Collection
    {
        return $this->ancestors()->get($columns);
    }

    /**
     * @return Collection<int, TModel>
     */
    public function getDescendants(array $columns = ['*']): Collection
    {
        return $this->descendants()->get($columns);
    }

    /**
     * @return Collection<int, TModel>
     */
    public function getSiblings(array $columns = ['*']): Collection
    {
        return $this->siblings()->get($columns);
    }

    /**
     * @return Collection<int, TModel>
     */
    public function getNextSiblings(array $columns = ['*']): Collection
    {
        return $this->nextSiblings()->get($columns);
    }

    /**
     * @return Collection<int, TModel>
     */
    public function getPrevSiblings(array $columns = ['*']): Collection
    {
        return $this->prevSiblings()->get($columns);
    }

    /**
     * @return TModel|null
     */
    public function getNextSibling(array $columns = ['*']): mixed
    {
        return $this->nextSiblings()->defaultOrder()->first($columns);
    }

    /**
     * @return TModel|null
     */
    public function getPrevSibling(array $columns = ['*']): mixed
    {
        return $this->prevSiblings()->defaultOrder('desc')->first($columns);
    }

    /**
     * Get whether a node is a descendant of other node.
     */
    public function isDescendantOf(self $other): bool
    {
        return $this->getLft() > $other->getLft()
            && $this->getLft() < $other->getRgt()
            && $this->isSameScope($other);
    }

    /**
     * Get whether a node is itself or a descendant of other node.
     */
    public function isSelfOrDescendantOf(self $other): bool
    {
        return $this->getLft() >= $other->getLft()
            && $this->getLft() < $other->getRgt();
    }

    /**
     * Get whether the node is immediate children of other node.
     */
    public function isChildOf(self $other): bool
    {
        return $this->getParentId() == $other->getKey();
    }

    /**
     * Get whether the node is a sibling of another node.
     */
    public function isSiblingOf(self $other): bool
    {
        return $this->getParentId() == $other->getParentId();
    }

    /**
     * Get whether the node is an ancestor of other node, including immediate parent.
     */
    public function isAncestorOf(self $other): bool
    {
        return $other->isDescendantOf($this);
    }

    /**
     * Get whether the node is itself or an ancestor of other node, including immediate parent.
     */
    public function isSelfOrAncestorOf(self $other): bool
    {
        return $other->isSelfOrDescendantOf($this);
    }

    /**
     * Get whether the node has moved since last save.
     */
    public function hasMoved(): bool
    {
        return $this->moved;
    }

    protected function getArrayableRelations(): array
    {
        $result = parent::getArrayableRelations();

        unset($result['parent']);

        return $result;
    }

    /**
     * Get whether user is intended to delete the model from database entirely.
     */
    protected function hasForceDeleting(): bool
    {
        return ! $this->usesSoftDelete() || $this->forceDeleting;
    }

    /**
     * @return array{?int, ?int}
     */
    public function getBounds(): array
    {
        return [$this->getLft(), $this->getRgt()];
    }

    public function setLft(mixed $value): static
    {
        $this->attributes[$this->getLftName()] = $value;

        return $this;
    }

    public function setRgt(mixed $value): static
    {
        $this->attributes[$this->getRgtName()] = $value;

        return $this;
    }

    public function setParentId(mixed $value): static
    {
        $this->attributes[$this->getParentIdName()] = $value;

        return $this;
    }

    protected function dirtyBounds(): static
    {
        $this->original[$this->getLftName()] = null;
        $this->original[$this->getRgtName()] = null;

        return $this;
    }

    protected function assertNotDescendant(self $node): static
    {
        if ($node == $this || $node->isDescendantOf($this)) {
            throw new LogicException('Node must not be a descendant.');
        }

        return $this;
    }

    protected function assertNodeExists(self $node): static
    {
        if (! $node->getLft() || ! $node->getRgt()) {
            throw new LogicException('Node must exists.');
        }

        return $this;
    }

    protected function assertSameScope(self $node): void
    {
        if (! $scoped = $this->getScopeAttributes()) {
            return;
        }

        foreach ($scoped as $attr) {
            if ($this->getAttribute($attr) != $node->getAttribute($attr)) {
                throw new LogicException('Nodes must be in the same scope');
            }
        }
    }

    protected function isSameScope(self $node): bool
    {
        if (! $scoped = $this->getScopeAttributes()) {
            return true;
        }

        foreach ($scoped as $attr) {
            if ($this->getAttribute($attr) != $node->getAttribute($attr)) {
                return false;
            }
        }

        return true;
    }

    public function replicate(?array $except = null): Model
    {
        $defaults = [
            $this->getParentIdName(),
            $this->getLftName(),
            $this->getRgtName(),
        ];

        $except = $except ? array_unique(array_merge($except, $defaults)) : $defaults;

        return parent::replicate($except);
    }
}
