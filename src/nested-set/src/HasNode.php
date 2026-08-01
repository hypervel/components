<?php

declare(strict_types=1);

namespace Hypervel\NestedSet;

use DateTimeInterface;
use Hypervel\Database\Eloquent\Builder as EloquentBuilder;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Eloquent\Relations\BelongsTo;
use Hypervel\Database\Eloquent\Relations\HasMany;
use Hypervel\Database\Query\Builder as BaseQueryBuilder;
use Hypervel\NestedSet\Eloquent\AncestorsRelation;
use Hypervel\NestedSet\Eloquent\Collection;
use Hypervel\NestedSet\Eloquent\DescendantsRelation;
use Hypervel\NestedSet\Eloquent\QueryBuilder;
use Hypervel\NestedSet\Eloquent\SiblingsRelation;
use Hypervel\Support\Arr;
use LogicException;
use Stringable;

use function Hypervel\Support\enum_value;

/**
 * @template TModel of Model
 *
 * @property null|int|string $parent_id
 * @property ?int $depth
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
     * Whether the node is being deleted by an evented descendant cascade.
     */
    protected bool $deletingAsDescendant = false;

    /**
     * Create a new Eloquent query builder for the model.
     */
    public function newEloquentBuilder(BaseQueryBuilder $query): QueryBuilder
    {
        $builderClass = static::$resolvedBuilderClasses[static::class]
            ??= $this->resolveCustomBuilderClass();

        if ($builderClass === false) {
            return new QueryBuilder($query);
        }

        if (! is_subclass_of($builderClass, QueryBuilder::class)) {
            throw new LogicException(sprintf(
                'Nested set model [%s] must use a builder that extends [%s].',
                static::class,
                QueryBuilder::class,
            ));
        }

        /** @var QueryBuilder $builder */
        $builder = new $builderClass($query);

        return $builder;
    }

    /**
     * Bootstrap node events.
     */
    public static function bootHasNode(): void
    {
        static::saving(function ($model): void {
            $model->assertNestedSetScopeIsUnchanged();
            $model->callPendingActions();
        });

        static::deleting(function ($model): void {
            if (! $model->deletingAsDescendant) {
                $model->prepareForNestedSetMutation();
            }
        });

        static::deleted(function ($model): void {
            if (! $model->deletingAsDescendant) {
                $model->deleteDescendants();
            }
        });

        // The restore events are supplied by SoftDeletes rather than Model.
        if (static::isSoftDeletable()) {
            static::restoring(function ($model): void {
                $model->prepareForNestedSetMutation([$model->getDeletedAtColumn()]);
            });

            static::restored(function ($model): void {
                /** @var null|DateTimeInterface|int|string $deletedAt */
                $deletedAt = $model->getPrevious()[$model->getDeletedAtColumn()] ?? null;

                if ($deletedAt !== null) {
                    $model->restoreDescendants($deletedAt);
                }
            });
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

        $action = array_shift($this->pending);

        if ($action !== 'raw') {
            $this->ensureMutationIdentityIsLoaded();
            $this->assertConcreteNestedSetScope('mutation');
        }

        $method = 'action' . ucfirst($action);
        $parameters = $this->pending;

        $this->pending = [];

        $this->moved = call_user_func_array([$this, $method], $parameters);
    }

    /**
     * Determine whether the model uses soft deletes.
     */
    public static function usesSoftDelete(): bool
    {
        return static::isSoftDeletable();
    }

    /**
     * Apply a raw node action.
     */
    protected function actionRaw(): bool
    {
        if (! $this->exists || $this->isDirty([
            $this->getParentIdName(),
            $this->getLftName(),
            $this->getRgtName(),
            $this->getDepthName(),
        ])) {
            NodeContext::markTreeChanged($this);
        }

        return true;
    }

    /**
     * Resolve the deferred parent and append the node to it.
     */
    protected function actionAppendToParentId(int|string $parentId): bool
    {
        $query = $this->newNestedSetQuery();

        if (static::isSoftDeletable()) {
            $query->withoutTrashed();
        }

        $parent = $query->findOrFail($parentId);

        $this->assertNodeInTree($parent)
            ->assertNotDescendant($parent)
            ->assertSameTree($parent);

        $this->setParent($parent)->dirtyBounds();

        return $this->actionAppendOrPrepend($parent);
    }

    /**
     * Make a root node.
     */
    protected function actionRoot(): bool
    {
        $this->setParent(null)->dirtyBounds();

        // Simplest case that does not affect other nodes.
        if (! $this->exists) {
            $cut = $this->getLowerBound() + 1;

            $this->setLft($cut);
            $this->setRgt($cut + 1);
            $this->setDepth(0);

            return true;
        }

        return $this->insertAt($this->getLowerBound() + 1, 0);
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
        $parent->prepareForNestedSetMutation();

        $this->assertNodeInTree($parent)
            ->assertNotDescendant($parent)
            ->assertSameTree($parent)
            ->setParent($parent)
            ->dirtyBounds();

        $cut = $prepend ? $parent->getLft() + 1 : $parent->getRgt();
        $parentDepth = $parent->getDepth();
        $targetDepth = $parentDepth === null ? null : $parentDepth + 1;

        if (! $this->insertAt($cut, $targetDepth)) {
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
     * Apply the current parent of a sibling without resolving its relation.
     */
    protected function setParentFromSibling(self $node): static
    {
        $parentId = $node->getParentId();

        if ($parentId === null) {
            return $this->setParent(null);
        }

        $this->setParentId($parentId);
        $this->unsetRelation('parent');

        return $this;
    }

    /**
     * Insert node before or after another node.
     */
    protected function actionBeforeOrAfter(self $node, bool $after = false): bool
    {
        $node->prepareForNestedSetMutation();

        $this->assertNodeInTree($node)
            ->assertNotDescendant($node)
            ->assertSameTree($node)
            ->setParentFromSibling($node)
            ->dirtyBounds();

        return $this->insertAt(
            $after ? $node->getRgt() + 1 : $node->getLft(),
            $node->getDepth(),
        );
    }

    /**
     * Refresh node's crucial attributes.
     */
    public function refreshNode(): void
    {
        $this->refreshNodeAttributes($this->getStructuralColumns());
    }

    /**
     * Refresh structural values without erasing staged movement dirtiness.
     */
    protected function refreshNodeForMove(): void
    {
        $this->refreshNodeAttributes($this->getStructuralColumns(), false);
    }

    /**
     * Prepare a model to participate in a structural mutation.
     */
    protected function prepareForNestedSetMutation(array $extraColumns = []): void
    {
        $this->assertNestedSetScopeIsUnchanged();
        $this->refreshNodeAttributes([
            ...$this->getMutationIdentityColumns(),
            ...$extraColumns,
        ]);
        $this->assertConcreteNestedSetScope('mutation');
    }

    /**
     * Load persisted mutation identity when it is incomplete or stale.
     */
    protected function ensureMutationIdentityIsLoaded(): void
    {
        $this->refreshNodeAttributes($this->getMutationIdentityColumns());
    }

    /**
     * Refresh selected attributes from the exact row on the write connection.
     */
    protected function refreshNodeAttributes(array $columns, bool $syncOriginal = true): void
    {
        if (! $this->exists) {
            return;
        }

        if ($this->hasLoadedNodeAttributes($columns) && NodeContext::isCurrent($this)) {
            return;
        }

        $attributes = $this->getPersistedNodeAttributes($columns);

        $this->attributes = array_merge($this->attributes, $attributes);

        if ($syncOriginal) {
            $this->syncOriginalAttributes($columns);
        }

        NodeContext::markCurrent($this);
        $this->unsetNestedSetRelations();
    }

    /**
     * Read selected attributes from the exact persisted row.
     */
    protected function getPersistedNodeAttributes(array $columns): array
    {
        return $this->newModelQuery()
            ->useWritePdo()
            ->whereKey($this->getKey())
            ->firstOrFail($columns)
            ->getAttributes();
    }

    /**
     * Get the columns that define structural position.
     *
     * @return list<string>
     */
    protected function getStructuralColumns(): array
    {
        return [
            $this->getLftName(),
            $this->getRgtName(),
            $this->getDepthName(),
        ];
    }

    /**
     * Get the columns that identify one mutable tree row.
     *
     * @return list<string>
     */
    protected function getMutationIdentityColumns(): array
    {
        return [
            ...$this->getStructuralColumns(),
            $this->getParentIdName(),
            ...$this->getScopeAttributes(),
        ];
    }

    /**
     * Determine whether every selected node attribute is loaded.
     */
    protected function hasLoadedNodeAttributes(array $columns): bool
    {
        foreach ($columns as $column) {
            if (! array_key_exists($column, $this->attributes)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Relation to the parent.
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(static::class, $this->getParentIdName())
            ->setModel($this);
    }

    /**
     * Relation to children.
     */
    public function children(): HasMany
    {
        return $this->hasMany(static::class, $this->getParentIdName())
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
    public function siblings(): SiblingsRelation
    {
        return new SiblingsRelation($this->newQuery(), $this);
    }

    /**
     * Get the relation for the node siblings and the node itself.
     */
    public function siblingsAndSelf(): SiblingsRelation
    {
        return new SiblingsRelation($this->newQuery(), $this, true);
    }

    /**
     * Get the node siblings and the node itself.
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
        $this->assertParentageLoaded();

        return $this->nextNodes()
            ->where(
                $this->qualifyColumn($this->getParentIdName()),
                '=',
                $this->getParentId(),
            );
    }

    /**
     * Get query for siblings before the node.
     */
    public function prevSiblings(): QueryBuilder
    {
        $this->assertParentageLoaded();

        return $this->prevNodes()
            ->where(
                $this->qualifyColumn($this->getParentIdName()),
                '=',
                $this->getParentId(),
            );
    }

    /**
     * Get query for nodes after current node.
     */
    public function nextNodes(): QueryBuilder
    {
        $this->assertBoundsLoaded();
        $this->assertScopeLoaded();

        return $this->newScopedQuery()
            ->where(
                $this->qualifyColumn($this->getLftName()),
                '>',
                $this->getLft(),
            );
    }

    /**
     * Get query for nodes before current node in reversed order.
     */
    public function prevNodes(): QueryBuilder
    {
        $this->assertBoundsLoaded();
        $this->assertScopeLoaded();

        return $this->newScopedQuery()
            ->where(
                $this->qualifyColumn($this->getLftName()),
                '<',
                $this->getLft(),
            );
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
        if ($this->exists) {
            $this->prepareForNestedSetMutation();
        }

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

    /**
     * Prepare the node for insertion as a child.
     */
    public function appendOrPrependTo(self $parent, bool $prepend = false): static
    {
        $this->prepareForNestedSetMutation();
        $parent->prepareForNestedSetMutation();

        $this->assertNodeInTree($parent)
            ->assertNotDescendant($parent)
            ->assertSameTree($parent);

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

    /**
     * Prepare the node for insertion beside another node.
     */
    public function beforeOrAfterNode(self $node, bool $after = false): static
    {
        $this->prepareForNestedSetMutation();
        $node->prepareForNestedSetMutation();

        $this->assertNodeInTree($node)
            ->assertNotDescendant($node)
            ->assertSameTree($node);

        $this->setParentFromSibling($node)->dirtyBounds();

        return $this->setNodeAction('beforeOrAfter', $node, $after);
    }

    /**
     * Insert self after a node and save.
     */
    public function insertAfterNode(self $node): bool
    {
        if (! $this->afterNode($node)->save()) {
            return false;
        }

        $node->refreshNode();

        return true;
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

    /**
     * Set raw structural values.
     */
    public function rawNode(
        int $lft,
        int $rgt,
        int|string|null $parentId,
        ?int $depth,
    ): static {
        $this->prepareForNestedSetMutation();

        $this->setLft($lft)
            ->setRgt($rgt)
            ->setParentId($parentId)
            ->setDepth($depth);

        return $this->setNodeAction('raw');
    }

    /**
     * Move node up given amount of positions.
     */
    public function up(int $amount = 1): bool
    {
        if ($amount < 1) {
            return false;
        }

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
        if ($amount < 1) {
            return false;
        }

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
    protected function insertAt(int $position, ?int $targetDepth = null): bool
    {
        return $this->exists
            ? $this->moveNode($position, $targetDepth)
            : $this->insertNode($position, $targetDepth);
    }

    /**
     * Move a node to the new position.
     */
    protected function moveNode(int $position, ?int $targetDepth = null): bool
    {
        $this->refreshNodeForMove();

        $lft = $this->getLft();
        $rgt = $this->getRgt();
        $height = $rgt - $lft + 1;

        $updated = $this->newNestedSetQuery()->moveNode(
            $this->getKey(),
            $position,
            $targetDepth,
            [
                $this->getLftName() => $lft,
                $this->getRgtName() => $rgt,
                $this->getDepthName() => $this->getDepth(),
            ],
        ) > 0;

        if ($updated) {
            if ($position > $lft) {
                $this->setLft($position - $height);
                $this->setRgt($position - 1);
            } else {
                $this->setLft($position);
                $this->setRgt($position + $height - 1);
            }

            if ($targetDepth !== null) {
                $this->setDepth($targetDepth);
            } else {
                $this->refreshNode();
            }

            $this->syncOriginalAttributes([
                $this->getLftName(),
                $this->getRgtName(),
                $this->getDepthName(),
            ]);

            $this->unsetNestedSetRelations();
        }

        return $updated;
    }

    /**
     * Insert new node at specified position.
     */
    protected function insertNode(int $position, ?int $targetDepth = null): bool
    {
        $this->newNestedSetQuery()->makeGap($position, 2);

        $height = $this->getNodeHeight();

        $this->setLft($position);
        $this->setRgt($position + $height - 1);
        $this->setDepth($targetDepth ?? $this->newNestedSetQuery()->depthForPosition($position));

        return true;
    }

    /**
     * Update the tree when the node is removed physically.
     */
    protected function deleteDescendants(): void
    {
        $lft = $this->getLft();
        $rgt = $this->getRgt();

        $method = static::isSoftDeletable() && $this->forceDeleting
            ? 'forceDelete'
            : 'delete';

        if ($this->shouldFireDescendantEvents()) {
            $this->deleteDescendantsWithEvents($method === 'forceDelete');
        } else {
            $this->descendants()->{$method}();
        }

        if ($this->hasForceDeleting()) {
            $height = $rgt - $lft + 1;

            $this->newNestedSetQuery()->makeGap($rgt + 1, -$height);

            // In case if user wants to re-create the node
            $this->makeRoot();
        }
    }

    /**
     * Determine whether descendant model events should be fired during deletion.
     */
    protected function shouldFireDescendantEvents(): bool
    {
        return false;
    }

    /**
     * Get the descendant deletion chunk size.
     */
    protected function getDescendantDeleteChunkSize(): int
    {
        return 1000;
    }

    /**
     * Delete descendants through their model lifecycle in children-first chunks.
     */
    protected function deleteDescendantsWithEvents(bool $forceDelete): void
    {
        $lftName = $this->getLftName();
        $query = $this->newNestedSetQuery()
            ->where($lftName, '>', $this->getLft())
            ->where($lftName, '<', $this->getRgt())
            ->orderBy($lftName, 'desc');

        if (static::isSoftDeletable() && ! $forceDelete) {
            $query->whereNull($this->getDeletedAtColumn());
        }

        $cursor = null;

        do {
            $chunk = clone $query;

            if ($cursor !== null) {
                $chunk->where($lftName, '<', $cursor);
            }

            $descendants = $chunk
                ->limit($this->getDescendantDeleteChunkSize())
                ->get();

            foreach ($descendants as $descendant) {
                $cursor = $descendant->getLft(); /* @phpstan-ignore method.notFound */
                $descendant->deletingAsDescendant = true; /* @phpstan-ignore property.notFound */

                try {
                    $deleted = $forceDelete
                        ? $descendant->forceDelete()
                        : $descendant->delete();

                    if ($deleted === false) {
                        throw new LogicException(sprintf(
                            'Deleting nested set descendant [%s] with key [%s] was vetoed.',
                            $descendant::class,
                            $descendant->getKey() ?? 'null',
                        ));
                    }
                } finally {
                    $descendant->deletingAsDescendant = false; /* @phpstan-ignore property.notFound */
                }
            }
        } while ($descendants->isNotEmpty());
    }

    /**
     * Restore the descendants.
     */
    protected function restoreDescendants(DateTimeInterface|int|string $deletedAt): void
    {
        $this->descendants()
            ->where($this->getDeletedAtColumn(), '>=', $deletedAt)
            ->restore();
    }

    /**
     * Get a new base query that includes deleted nodes.
     */
    public function newNestedSetQuery(?string $table = null): QueryBuilder
    {
        $builder = $this->newQuery()->withoutGlobalScopes();

        return $this->applyNestedSetScope($builder, $table);
    }

    /**
     * Get a new query with ordinary visibility and the concrete tree scope.
     */
    public function newScopedQuery(?string $table = null): QueryBuilder
    {
        return $this->applyNestedSetScope($this->newQuery(), $table);
    }

    /**
     * Apply the concrete tree scope to the query.
     *
     * @template TQuery of BaseQueryBuilder|EloquentBuilder
     *
     * @param TQuery $query
     * @return TQuery
     */
    public function applyNestedSetScope(
        BaseQueryBuilder|EloquentBuilder $query,
        ?string $table = null,
    ): BaseQueryBuilder|EloquentBuilder {
        $scope = $this->getNestedSetScope();

        if ($scope === []) {
            return $query;
        }

        if (! $table) {
            $table = $this->getTable();
        }

        foreach ($scope as $attribute => $value) {
            $query->where(
                $table . '.' . $attribute,
                '=',
                $value,
            );
        }

        return $query;
    }

    /**
     * Get the attributes that partition nested set trees.
     */
    protected function getScopeAttributes(): array
    {
        return [];
    }

    /**
     * Determine whether every configured scope attribute is loaded.
     */
    protected function hasCompleteNestedSetScope(): bool
    {
        foreach ($this->getScopeAttributes() as $attribute) {
            if (! array_key_exists($attribute, $this->attributes)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Assert that an existing model has not changed tree scope.
     */
    protected function assertNestedSetScopeIsUnchanged(): void
    {
        $scopeAttributes = $this->getScopeAttributes();

        if (! $this->exists || $scopeAttributes === []) {
            return;
        }

        $missingOriginals = [];

        foreach ($scopeAttributes as $attribute) {
            if (! array_key_exists($attribute, $this->original)) {
                $missingOriginals[] = $attribute;
            }
        }

        if ($missingOriginals !== []) {
            $persisted = $this->getPersistedNodeAttributes($this->getMutationIdentityColumns());

            foreach ($persisted as $attribute => $value) {
                if (! array_key_exists($attribute, $this->attributes)) {
                    $this->attributes[$attribute] = $value;
                }

                if (! array_key_exists($attribute, $this->original)) {
                    $this->original[$attribute] = $value;
                }
            }
        }

        foreach ($scopeAttributes as $attribute) {
            if ($this->isDirty($attribute)) {
                throw new LogicException(sprintf(
                    'Nested set scope attribute [%s] cannot be changed on an existing [%s] model.',
                    $attribute,
                    static::class,
                ));
            }
        }
    }

    /**
     * Assert that a model selects one concrete nested set scope.
     */
    protected function assertConcreteNestedSetScope(string $operation): void
    {
        foreach ($this->getScopeAttributes() as $attribute) {
            if (! array_key_exists($attribute, $this->attributes)) {
                throw new LogicException(sprintf(
                    'Nested set %s for [%s] requires a concrete scoped([...]) selection because attribute [%s] was not selected.',
                    $operation,
                    static::class,
                    $attribute,
                ));
            }
        }
    }

    /**
     * Get the normalized scope values for this tree.
     *
     * @return array<string, null|int|string>
     */
    public function getNestedSetScope(): array
    {
        $scope = [];

        foreach ($this->getScopeAttributes() as $attribute) {
            $scope[$attribute] = $this->normalizeNestedSetScopeValue(
                $attribute,
                $this->getAttributeValue($attribute),
            );
        }

        return $scope;
    }

    /**
     * Normalize a nested set scope value for SQL and identity comparisons.
     */
    protected function normalizeNestedSetScopeValue(string $attribute, mixed $value): int|string|null
    {
        $value = enum_value($value);

        return match (true) {
            $value === null, is_int($value), is_string($value) => $value,
            is_bool($value) => (int) $value,
            // Mirror Grammar::getDateFormat() without resolving a
            // connection for each eager result.
            $value instanceof DateTimeInterface => $value->format(
                $this->dateFormat ?: 'Y-m-d H:i:s',
            ),
            $value instanceof Stringable => (string) $value,
            default => throw new LogicException(sprintf(
                'Nested set model [%s] has unsupported scope value [%s] for attribute [%s].',
                static::class,
                get_debug_type($value),
                $attribute,
            )),
        };
    }

    /**
     * Get the stable identity key for this tree scope.
     */
    public function getNestedSetScopeKey(): string
    {
        if (! $this->hasCompleteNestedSetScope()) {
            return 'incomplete:' . spl_object_id($this);
        }

        $key = '';

        foreach ($this->getNestedSetScope() as $value) {
            $key .= $value === null
                ? '-1:'
                : strlen((string) $value) . ':' . $value;
        }

        return $key;
    }

    /**
     * Begin a query for one concrete nested set scope.
     */
    public static function scoped(array $attributes): QueryBuilder
    {
        $instance = new static;

        $instance->setRawAttributes($attributes);

        return $instance->newScopedQuery();
    }

    /**
     * Create a new nested set collection.
     *
     * @return Collection<int, TModel>
     */
    public function newCollection(array $models = []): Collection
    {
        return new Collection($models);
    }

    /**
     * Use `children` key on `$attributes` to create child nodes.
     */
    public static function create(array $attributes = [], ?self $parent = null): static
    {
        $children = Arr::pull($attributes, 'children');

        $instance = new static($attributes);

        if ($parent) {
            $instance->appendToNode($parent);
        }

        $instance->save();

        $relation = new Collection;

        foreach ((array) $children as $child) {
            $relation->add(static::create($child, $instance));
        }

        $instance->refreshNode();

        $relationParent = clone $instance;
        $relationParent->setRelations([]);

        foreach ($relation as $child) {
            $child->setRelation('parent', $relationParent);
        }

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
     */
    public function setParentIdAttribute(int|string|null $value): void
    {
        $parentIdName = $this->getParentIdName();
        $hasCurrent = array_key_exists($parentIdName, $this->attributes);
        $current = $this->getParentId();

        if ($hasCurrent && (
            $current === $value
            || ($current !== null && $value !== null && (string) $current === (string) $value)
        )) {
            return;
        }

        if ($value === null) {
            $this->makeRoot();

            return;
        }

        $this->setParentId($value);
        $this->setNodeAction('appendToParentId', $value);
    }

    /**
     * Get whether node is root.
     */
    public function isRoot(): bool
    {
        return array_key_exists($this->getParentIdName(), $this->attributes)
            && $this->getParentId() === null;
    }

    /**
     * Determine whether the node is a leaf.
     */
    public function isLeaf(): bool
    {
        return $this->getLft() + 1 === $this->getRgt();
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
     * Get the depth column name.
     */
    public function getDepthName(): string
    {
        return NestedSet::DEPTH;
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
    public function getParentId(): int|string|null
    {
        return $this->getAttributeValue($this->getParentIdName());
    }

    /**
     * Get the node depth.
     */
    public function getDepth(): ?int
    {
        $value = $this->getAttributeValue($this->getDepthName());

        return $value === null ? null : (int) $value;
    }

    /**
     * Returns node that is next to current node without constraining to siblings.
     * This can be either a next sibling or a next sibling of the parent node.
     *
     * @return null|TModel
     */
    public function getNextNode(array $columns = ['*']): ?Model
    {
        return $this->nextNodes()->defaultOrder()->first($columns);
    }

    /**
     * Returns node that is before current node without constraining to siblings.
     * This can be either a prev sibling or parent node.
     *
     * @return null|TModel
     */
    public function getPrevNode(array $columns = ['*']): ?Model
    {
        return $this->prevNodes()->defaultOrder('desc')->first($columns);
    }

    /**
     * Get the node's ancestors.
     *
     * @return Collection<int, TModel>
     */
    public function getAncestors(array $columns = ['*']): Collection
    {
        return $this->ancestors()->get($columns);
    }

    /**
     * Get the node's descendants.
     *
     * @return Collection<int, TModel>
     */
    public function getDescendants(array $columns = ['*']): Collection
    {
        return $this->descendants()->get($columns);
    }

    /**
     * Get the node's siblings.
     *
     * @return Collection<int, TModel>
     */
    public function getSiblings(array $columns = ['*']): Collection
    {
        return $this->siblings()->get($columns);
    }

    /**
     * Get siblings after the node.
     *
     * @return Collection<int, TModel>
     */
    public function getNextSiblings(array $columns = ['*']): Collection
    {
        return $this->nextSiblings()->get($columns);
    }

    /**
     * Get siblings before the node.
     *
     * @return Collection<int, TModel>
     */
    public function getPrevSiblings(array $columns = ['*']): Collection
    {
        return $this->prevSiblings()->get($columns);
    }

    /**
     * Get the next sibling.
     *
     * @return null|TModel
     */
    public function getNextSibling(array $columns = ['*']): ?Model
    {
        return $this->nextSiblings()->defaultOrder()->first($columns);
    }

    /**
     * Get the previous sibling.
     *
     * @return null|TModel
     */
    public function getPrevSibling(array $columns = ['*']): ?Model
    {
        return $this->prevSiblings()->defaultOrder('desc')->first($columns);
    }

    /**
     * Get whether a node is a descendant of other node.
     */
    public function isDescendantOf(self $other): bool
    {
        $lft = $this->getLft();
        $otherLft = $other->getLft();
        $otherRgt = $other->getRgt();

        return $this->exists
            && $other->exists
            && $lft !== null
            && $otherLft !== null
            && $otherRgt !== null
            && $this->isSameTree($other)
            && $lft > $otherLft
            && $lft < $otherRgt
            && ! $this->isSameNode($other);
    }

    /**
     * Get whether a node is itself or a descendant of other node.
     */
    public function isSelfOrDescendantOf(self $other): bool
    {
        $lft = $this->getLft();
        $otherLft = $other->getLft();
        $otherRgt = $other->getRgt();

        return $this->exists
            && $other->exists
            && $this->isSameTree($other)
            && (
                $this->isSameNode($other)
                || (
                    $lft !== null
                    && $otherLft !== null
                    && $otherRgt !== null
                    && $lft > $otherLft
                    && $lft < $otherRgt
                )
            );
    }

    /**
     * Get whether the node is immediate children of other node.
     */
    public function isChildOf(self $other): bool
    {
        $parentId = $this->getParentId();
        $otherKey = $other->getKey();

        return $this->exists
            && $other->exists
            && $parentId !== null
            && $otherKey !== null
            && $this->isSameTree($other)
            && (string) $parentId === (string) $otherKey;
    }

    /**
     * Get whether the node is a sibling of another node.
     */
    public function isSiblingOf(self $other): bool
    {
        if (! $this->exists
            || ! $other->exists
            || ! array_key_exists($this->getParentIdName(), $this->attributes)
            || ! array_key_exists($other->getParentIdName(), $other->attributes)
            || ! $this->isSameTree($other)
            || $this->isSameNode($other)
        ) {
            return false;
        }

        $parentId = $this->getParentId();
        $otherParentId = $other->getParentId();

        return $parentId === null || $otherParentId === null
            ? $parentId === $otherParentId
            : (string) $parentId === (string) $otherParentId;
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

    /**
     * Get whether user is intended to delete the model from database entirely.
     */
    protected function hasForceDeleting(): bool
    {
        return ! static::isSoftDeletable() || $this->forceDeleting;
    }

    /**
     * Get the node bounds.
     *
     * @return array{?int, ?int}
     */
    public function getBounds(): array
    {
        return [$this->getLft(), $this->getRgt()];
    }

    /**
     * Set the left bound.
     */
    public function setLft(?int $value): static
    {
        $this->attributes[$this->getLftName()] = $value;

        return $this;
    }

    /**
     * Set the right bound.
     */
    public function setRgt(?int $value): static
    {
        $this->attributes[$this->getRgtName()] = $value;

        return $this;
    }

    /**
     * Set the parent key.
     */
    public function setParentId(int|string|null $value): static
    {
        $this->attributes[$this->getParentIdName()] = $value;

        return $this;
    }

    /**
     * Set the node depth.
     */
    public function setDepth(?int $value): static
    {
        $this->attributes[$this->getDepthName()] = $value;

        return $this;
    }

    /**
     * Mark the stored bounds as dirty.
     */
    protected function dirtyBounds(): static
    {
        $this->original[$this->getLftName()] = null;
        $this->original[$this->getRgtName()] = null;

        return $this;
    }

    /**
     * Forget relations derived from the node's structural position.
     */
    protected function unsetNestedSetRelations(): void
    {
        foreach ([
            'parent',
            'children',
            'ancestors',
            'descendants',
            'siblings',
            'siblingsAndSelf',
        ] as $relation) {
            $this->unsetRelation($relation);
        }
    }

    /**
     * Assert that a node is not this node's descendant.
     */
    protected function assertNotDescendant(self $node): static
    {
        if ($node === $this || $this->isSameNode($node) || $node->isDescendantOf($this)) {
            throw new LogicException('Node must not be a descendant.');
        }

        return $this;
    }

    /**
     * Assert that a node has persisted tree bounds.
     */
    protected function assertNodeInTree(self $node): static
    {
        if (($node->getLft() ?? 0) < 1 || ($node->getRgt() ?? 0) < 1) {
            throw new LogicException('Node must be part of a tree.');
        }

        return $this;
    }

    /**
     * Assert that this node has loaded bounds.
     */
    protected function assertBoundsLoaded(): void
    {
        if ($this->getLft() === null || $this->getRgt() === null) {
            throw new LogicException(sprintf(
                'Nested set node [%s] must have loaded bounds.',
                static::class,
            ));
        }
    }

    /**
     * Assert that this node has loaded parentage.
     */
    protected function assertParentageLoaded(): void
    {
        if (! array_key_exists($this->getParentIdName(), $this->attributes)) {
            throw new LogicException(sprintf(
                'Nested set node [%s] must have a loaded parent.',
                static::class,
            ));
        }
    }

    /**
     * Assert that this node has loaded scope attributes.
     */
    protected function assertScopeLoaded(): void
    {
        foreach ($this->getScopeAttributes() as $attribute) {
            if (! array_key_exists($attribute, $this->attributes)) {
                throw new LogicException(sprintf(
                    'Nested set node [%s] must have scope attribute [%s] selected.',
                    static::class,
                    $attribute,
                ));
            }
        }
    }

    /**
     * Assert that a node belongs to the same nested set tree.
     */
    protected function assertSameTree(self $node): static
    {
        if (! $this->isSameTree($node)) {
            throw new LogicException('Nodes must be in the same tree.');
        }

        return $this;
    }

    /**
     * Determine whether a node belongs to the same concrete scope.
     */
    protected function isSameScope(self $node): bool
    {
        return $this->hasCompleteNestedSetScope()
            && $node->hasCompleteNestedSetScope()
            && $this->getNestedSetScopeKey() === $node->getNestedSetScopeKey();
    }

    /**
     * Determine whether the models address the same nested set tree.
     */
    protected function isSameTree(self $node): bool
    {
        return NodeContext::structuralIdentity($this) === NodeContext::structuralIdentity($node)
            && $this->isSameScope($node);
    }

    /**
     * Determine whether the models address the same persisted row.
     */
    protected function isSameNode(self $node): bool
    {
        $key = $this->getKey();
        $nodeKey = $node->getKey();

        if ($key === null || $nodeKey === null) {
            return false;
        }

        if ($this->is($node)) {
            return true;
        }

        return (string) $key === (string) $nodeKey
            && NodeContext::structuralIdentity($this) === NodeContext::structuralIdentity($node);
    }

    /**
     * Clone the node without structural attributes.
     */
    public function replicate(?array $except = null): static
    {
        $defaults = [
            $this->getParentIdName(),
            $this->getLftName(),
            $this->getRgtName(),
            $this->getDepthName(),
        ];

        $except = $except ? array_unique(array_merge($except, $defaults)) : $defaults;

        return parent::replicate($except);
    }
}
