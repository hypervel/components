<?php

declare(strict_types=1);

namespace Hypervel\Data\Support\Creation;

use Hypervel\Data\Contracts\BaseData;
use Hypervel\Data\Normalizers\Normalized\UnknownProperty;
use Hypervel\Pagination\AbstractCursorPaginator;
use Hypervel\Pagination\AbstractPaginator;

final class ConstructionState
{
    /** @var array<array-key, mixed> */
    private array $payload = [];

    /** @var null|array<array-key, mixed> */
    private ?array $unknownInput = null;

    /**
     * @var array{
     *     class: null|class-string<BaseData>,
     *     mappings: array<string, array-key>,
     *     children: array<string, array>,
     *     autoLazy?: array<string, array{source: mixed, replay?: AutoLazyReplayMode}>,
     *     paginatorSource?: AbstractCursorPaginator|AbstractPaginator,
     *     uniform?: false,
     *     items?: array<array-key, array>
     * }
     */
    private array $structure;

    /** @var list<array{payloadPath: non-empty-list<array-key>, structureKey: ?string, itemKey: null|array-key}> */
    private array $path = [];

    /**
     * Create construction state for one root operation.
     *
     * @param class-string<BaseData> $class
     */
    private function __construct(
        public readonly CreationContext $context,
        string $class,
    ) {
        $this->structure = self::newStructureNode($class);
    }

    /**
     * Create construction state for one root operation.
     *
     * @param class-string<BaseData> $class
     */
    public static function create(CreationContext $context, string $class): self
    {
        return new self($context, $class);
    }

    /**
     * Enter a nested data property.
     *
     * @param non-empty-list<array-key> $payloadPath
     */
    public function enterProperty(string $property, array $payloadPath): void
    {
        $this->path[] = [
            'payloadPath' => $payloadPath,
            'structureKey' => $property,
            'itemKey' => null,
        ];
    }

    /**
     * Enter one data collection item.
     */
    public function enterItem(string|int $index): void
    {
        $this->path[] = [
            'payloadPath' => [$index],
            'structureKey' => null,
            'itemKey' => $index,
        ];
    }

    /**
     * Leave the current property or collection item.
     */
    public function leave(): void
    {
        array_pop($this->path);
    }

    /**
     * Get the current traversal depth.
     */
    public function depth(): int
    {
        return count($this->path);
    }

    /**
     * Get the current wire-key path.
     *
     * @return list<array-key>
     */
    public function path(): array
    {
        $path = [];

        foreach ($this->path as $segment) {
            array_push($path, ...$segment['payloadPath']);
        }

        return $path;
    }

    /**
     * Write a mapped property value beneath the current path.
     *
     * @param non-empty-list<array-key> $path
     */
    public function writePropertyValue(array $path, mixed $value): void
    {
        $this->writeAtPath(
            [...$this->path(), ...$path],
            $value,
            false,
        );
    }

    /**
     * Write a finished mapped property value beneath the current path.
     *
     * @param non-empty-list<array-key> $path
     */
    public function writeFinishedPropertyValue(array $path, mixed $value): void
    {
        $this->writeAtPath(
            [...$this->path(), ...$path],
            $value,
            true,
        );
    }

    /**
     * Write a raw collection item beneath the current path.
     */
    public function writeItemValue(string|int $key, mixed $value): void
    {
        $this->writeAtPath([...$this->path(), $key], $value, false);
    }

    /**
     * Write a finished raw collection item beneath the current path.
     */
    public function writeFinishedItemValue(string|int $key, mixed $value): void
    {
        $this->writeAtPath([...$this->path(), $key], $value, true);
    }

    /**
     * Determine if a value exists beneath the current path.
     *
     * @param non-empty-list<array-key> $path
     */
    public function hasValue(array $path): bool
    {
        $slot = $this->valueAtPath($path);

        return ! $slot instanceof UnknownProperty;
    }

    /**
     * Get a value beneath the current path.
     *
     * @param non-empty-list<array-key> $path
     */
    public function getValue(array $path): mixed
    {
        $value = $this->valueAtPath($path);

        return $value instanceof UnknownProperty ? null : $value;
    }

    /**
     * Get the complete construction payload.
     *
     * @return array<array-key, mixed>
     */
    public function payload(): array
    {
        return $this->payload;
    }

    /**
     * Get the payload at the current traversal path.
     */
    public function currentPayload(): mixed
    {
        return $this->payloadAtCurrentPath();
    }

    /**
     * Replace the complete construction payload.
     *
     * @param array<array-key, mixed> $payload
     */
    public function replacePayload(array $payload): void
    {
        $this->payload = $payload;
    }

    /**
     * Record strict input beneath the current traversal path.
     *
     * @param array<array-key, mixed> $input
     */
    public function recordUnknownInput(array $input): void
    {
        $path = $this->path();

        if ($path === [] && $this->unknownInput === null) {
            $this->unknownInput = $input;

            return;
        }

        $this->unknownInput ??= [];
        $target = &$this->unknownInput;

        foreach ($path as $key) {
            if (! array_key_exists($key, $target) || ! is_array($target[$key])) {
                $target[$key] = [];
            }

            $target = &$target[$key];
        }

        $target = $this->mergeUnknownInput($target, $input);
    }

    /**
     * Get the merged strict input tree.
     *
     * @return null|array<array-key, mixed>
     */
    public function unknownInput(): ?array
    {
        return $this->unknownInput;
    }

    /**
     * Record the chosen wire key for a property on the current node.
     */
    public function recordMapping(string $property, string|int $mappedKey): void
    {
        $template = &$this->ensureStructureNodeAtCurrentPath();

        if (! array_key_exists($property, $template['mappings'])) {
            $template['mappings'][$property] = $mappedKey;

            return;
        }

        if ($template['mappings'][$property] === $mappedKey) {
            return;
        }

        if (! $this->pathContainsItem()) {
            $template['mappings'][$property] = $mappedKey;

            return;
        }

        $override = &$this->ensureOverrideNodeAtCurrentPath();
        $override['mappings'][$property] = $mappedKey;
        $this->markEnclosingCollectionsNonUniform();
    }

    /**
     * Replace one selected wire key during hook reconciliation.
     */
    public function replaceMapping(string $property, string|int $mappedKey): void
    {
        $template = &$this->ensureStructureNodeAtCurrentPath();

        if (! $this->pathContainsItem()) {
            $template['mappings'][$property] = $mappedKey;

            return;
        }

        $override = &$this->ensureOverrideNodeAtCurrentPath();
        unset($override['mappings'][$property]);

        if (($template['mappings'][$property] ?? null) !== $mappedKey) {
            $override['mappings'][$property] = $mappedKey;
            $this->markEnclosingCollectionsNonUniform();
        }
    }

    /**
     * Clear one changed child selection during hook reconciliation.
     */
    public function clearChildStructure(string $property): void
    {
        if (! $this->pathContainsItem()) {
            $node = &$this->ensureStructureNodeAtCurrentPath();
            unset($node['children'][$property]);

            return;
        }

        $override = &$this->ensureOverrideNodeAtCurrentPath();
        unset($override['children'][$property]);
        $this->markEnclosingCollectionsNonUniform();
    }

    /**
     * Reset the current class-owned selections during morph reconciliation.
     */
    public function resetNodeStructure(): void
    {
        if ($this->pathContainsItem()) {
            $node = &$this->ensureOverrideNodeAtCurrentPath();
        } else {
            $node = &$this->ensureStructureNodeAtCurrentPath();
        }

        $node['class'] = null;
        $node['mappings'] = [];
        $node['children'] = [];
        unset($node['autoLazy'], $node['paginatorSource']);

        if ($this->pathContainsItem()) {
            $this->markEnclosingCollectionsNonUniform();
        }
    }

    /**
     * Determine if a wire key was recorded for a property on the current node.
     */
    public function hasOriginalKey(string $property): bool
    {
        $override = $this->overrideNodeAtCurrentPath();

        if ($override !== null && array_key_exists($property, $override['mappings'])) {
            return true;
        }

        $template = $this->structureNodeAtCurrentPath();

        return $template !== null && array_key_exists($property, $template['mappings']);
    }

    /**
     * Get the chosen wire key for a property on the current node.
     */
    public function originalKey(string $property): string|int
    {
        $override = $this->overrideNodeAtCurrentPath();

        if ($override !== null && array_key_exists($property, $override['mappings'])) {
            return $override['mappings'][$property];
        }

        $template = $this->structureNodeAtCurrentPath();

        if ($template === null) {
            return $property;
        }

        return $template['mappings'][$property] ?? $property;
    }

    /**
     * Record the concrete data class selected for the current node.
     *
     * @param class-string<BaseData> $class
     */
    public function setNodeClass(string $class): void
    {
        $template = &$this->ensureStructureNodeAtCurrentPath();

        if ($template['class'] === null) {
            $template['class'] = $class;

            return;
        }

        if ($template['class'] === $class) {
            return;
        }

        if (! $this->pathContainsItem()) {
            $template['class'] = $class;

            return;
        }

        $override = &$this->ensureOverrideNodeAtCurrentPath();
        $override['class'] = $class;
        $this->markEnclosingCollectionsNonUniform();
    }

    /**
     * Get the concrete data class selected for the current node.
     *
     * @return null|class-string<BaseData>
     */
    public function nodeClass(): ?string
    {
        $override = $this->overrideNodeAtCurrentPath();

        if ($override !== null && $override['class'] !== null) {
            return $override['class'];
        }

        return $this->structureNodeAtCurrentPath()['class'] ?? null;
    }

    /**
     * Record one automatic lazy property recipe.
     */
    public function recordAutoLazy(
        string $property,
        mixed $source,
        ?AutoLazyReplayMode $replay = null,
    ): void {
        if ($this->pathContainsItem()) {
            $node = &$this->ensureOverrideNodeAtCurrentPath();
        } else {
            $node = &$this->ensureStructureNodeAtCurrentPath();
        }

        $node['autoLazy'][$property] = ['source' => $source];

        if ($replay !== null) {
            $node['autoLazy'][$property]['replay'] = $replay;
        }
    }

    /**
     * Get one automatic lazy property recipe.
     *
     * @return array{source: mixed, replay?: AutoLazyReplayMode}|UnknownProperty
     */
    public function autoLazy(string $property): array|UnknownProperty
    {
        $node = $this->pathContainsItem()
            ? $this->overrideNodeAtCurrentPath()
            : $this->structureNodeAtCurrentPath();

        if ($node !== null && array_key_exists($property, $node['autoLazy'] ?? [])) {
            return $node['autoLazy'][$property];
        }

        return UnknownProperty::create();
    }

    /**
     * Record the paginator source for the current node.
     */
    public function recordPaginatorSource(AbstractPaginator|AbstractCursorPaginator $source): void
    {
        if ($this->pathContainsItem()) {
            $node = &$this->ensureOverrideNodeAtCurrentPath();
        } else {
            $node = &$this->ensureStructureNodeAtCurrentPath();
        }

        $node['paginatorSource'] = $source;
    }

    /**
     * Get the paginator source for the current node.
     *
     * Item reads never fall back to the collection template because paginator
     * metadata belongs to one concrete source value.
     */
    public function paginatorSource(): AbstractPaginator|AbstractCursorPaginator|null
    {
        $node = $this->pathContainsItem()
            ? $this->overrideNodeAtCurrentPath()
            : $this->structureNodeAtCurrentPath();

        return $node['paginatorSource'] ?? null;
    }

    /**
     * Clear the paginator source for the current node.
     */
    public function clearPaginatorSource(): void
    {
        $node = &$this->structure;

        foreach ($this->path as $segment) {
            if ($segment['itemKey'] !== null) {
                if (! array_key_exists($segment['itemKey'], $node['items'] ?? [])) {
                    return;
                }

                $node = &$node['items'][$segment['itemKey']];

                continue;
            }

            $key = $segment['structureKey'];

            if (! array_key_exists($key, $node['children'])) {
                return;
            }

            $node = &$node['children'][$key];
        }

        unset($node['paginatorSource']);
    }

    /**
     * Determine if the current collection has one uniform recursive structure.
     */
    public function isCurrentCollectionUniform(): bool
    {
        $node = $this->structureNodeAtCurrentPath();

        return $node === null || ($node['uniform'] ?? true);
    }

    /**
     * Get the compiled structure tree.
     */
    public function structure(): array
    {
        return $this->structure;
    }

    /**
     * Create a detached baseline for one automatic lazy property.
     */
    public function snapshotForProperty(string $property): self
    {
        $snapshot = clone $this;
        /** @var array<array-key, mixed> $payload */
        $payload = $this->payloadAtCurrentPath();
        $snapshot->payload = self::payloadSkeleton($this->path(), $payload);
        $snapshot->unknownInput = null;

        $templatePath = [];
        $exactPath = [];

        foreach ($this->path as $segment) {
            if ($segment['structureKey'] !== null) {
                $step = ['children', $segment['structureKey']];
                $templatePath[] = $step;
                $exactPath[] = $step;
            } elseif ($segment['itemKey'] !== null) {
                $exactPath[] = ['items', $segment['itemKey']];
            }
        }

        $paths = [$templatePath];

        if ($exactPath !== $templatePath) {
            $paths[] = $exactPath;
        }

        $snapshot->structure = $this->pruneStructureNode($this->structure, $paths, $property);

        return $snapshot;
    }

    /**
     * Get the payload at the current traversal path.
     */
    private function payloadAtCurrentPath(): mixed
    {
        $slot = $this->payload;

        foreach ($this->path() as $key) {
            if (! is_array($slot) || ! array_key_exists($key, $slot)) {
                return null;
            }

            $slot = $slot[$key];
        }

        return $slot;
    }

    /**
     * Merge strict input while retaining the most structured observed value.
     *
     * @param array<array-key, mixed> $target
     * @param array<array-key, mixed> $source
     * @return array<array-key, mixed>
     */
    private function mergeUnknownInput(array $target, array $source): array
    {
        foreach ($source as $key => $value) {
            if (! array_key_exists($key, $target)) {
                $target[$key] = $value;

                continue;
            }

            if (! is_array($value)) {
                continue;
            }

            $target[$key] = is_array($target[$key])
                ? $this->mergeUnknownInput($target[$key], $value)
                : $value;
        }

        return $target;
    }

    /**
     * Get a value beneath the current path without collapsing absence into null.
     *
     * @param non-empty-list<array-key> $path
     */
    private function valueAtPath(array $path): mixed
    {
        $slot = $this->payloadAtCurrentPath();

        foreach ($path as $key) {
            if (! is_array($slot) || ! array_key_exists($key, $slot)) {
                return UnknownProperty::create();
            }

            $slot = $slot[$key];
        }

        return $slot;
    }

    /**
     * Write a value at an absolute payload path.
     *
     * @param non-empty-list<array-key> $path
     */
    private function writeAtPath(array $path, mixed $value, bool $finished): void
    {
        $slot = &$this->payload;
        $lastKey = array_pop($path);

        foreach ($path as $pathKey) {
            if (! array_key_exists($pathKey, $slot) || ! is_array($slot[$pathKey])) {
                $slot[$pathKey] = [];
            }

            $slot = &$slot[$pathKey];
        }

        $slot[$lastKey] = $value;

        if ($finished && $this->pathContainsItem()) {
            $this->markEnclosingCollectionsNonUniform();
        }
    }

    /**
     * Build a root-shaped payload skeleton ending in one complete node payload.
     *
     * @param list<array-key> $path
     * @return array<array-key, mixed>
     */
    private static function payloadSkeleton(array $path, array $payload): array
    {
        if ($path === []) {
            return $payload;
        }

        foreach (array_reverse($path) as $key) {
            $payload = [$key => $payload];
        }

        return $payload;
    }

    /**
     * Retain only the template and exact structure spines for one property.
     *
     * @param list<list<array{0: 'children'|'items', 1: array-key}>> $paths
     */
    private function pruneStructureNode(array $node, array $paths, string $property): array
    {
        $pruned = self::newStructureNode($node['class']);

        if (isset($node['uniform'])) {
            $pruned['uniform'] = false;
        }

        $branches = [];

        foreach ($paths as $path) {
            if ($path === []) {
                if (array_key_exists($property, $node['mappings'])) {
                    $pruned['mappings'][$property] = $node['mappings'][$property];
                }

                if (array_key_exists($property, $node['children'])) {
                    $pruned['children'][$property] = $node['children'][$property];
                }

                continue;
            }

            [$collection, $key] = $path[0];
            $branches[$collection][$key][] = array_slice($path, 1);
        }

        foreach ($branches as $collection => $children) {
            foreach ($children as $key => $childPaths) {
                if (! array_key_exists($key, $node[$collection] ?? [])) {
                    continue;
                }

                $pruned[$collection][$key] = $this->pruneStructureNode(
                    $node[$collection][$key],
                    $childPaths,
                    $property,
                );
            }
        }

        return $pruned;
    }

    /**
     * Get the structure node at the current traversal path.
     */
    private function structureNodeAtCurrentPath(): ?array
    {
        $node = $this->structure;

        foreach ($this->path as $segment) {
            $key = $segment['structureKey'];

            if ($key === null) {
                continue;
            }

            if (! array_key_exists($key, $node['children'])) {
                return null;
            }

            $node = $node['children'][$key];
        }

        return $node;
    }

    /**
     * Get the sparse item override at the current traversal path.
     */
    private function overrideNodeAtCurrentPath(): ?array
    {
        if (! $this->pathContainsItem()) {
            return null;
        }

        $node = $this->structure;

        foreach ($this->path as $segment) {
            if ($segment['itemKey'] !== null) {
                if (! array_key_exists($segment['itemKey'], $node['items'] ?? [])) {
                    return null;
                }

                $node = $node['items'][$segment['itemKey']];

                continue;
            }

            $key = $segment['structureKey'];

            if (! array_key_exists($key, $node['children'])) {
                return null;
            }

            $node = $node['children'][$key];
        }

        return $node;
    }

    /**
     * Get or create the structure node at the current traversal path.
     */
    private function &ensureStructureNodeAtCurrentPath(): array
    {
        $node = &$this->structure;

        foreach ($this->path as $segment) {
            $key = $segment['structureKey'];

            if ($key === null) {
                continue;
            }

            if (! array_key_exists($key, $node['children'])) {
                $node['children'][$key] = self::newStructureNode();
            }

            $node = &$node['children'][$key];
        }

        return $node;
    }

    /**
     * Get or create the sparse item override at the current traversal path.
     */
    private function &ensureOverrideNodeAtCurrentPath(): array
    {
        $node = &$this->structure;

        foreach ($this->path as $segment) {
            if ($segment['itemKey'] !== null) {
                $node['items'] ??= [];
                $node['items'][$segment['itemKey']] ??= self::newStructureNode();
                $node = &$node['items'][$segment['itemKey']];

                continue;
            }

            $key = $segment['structureKey'];
            $node['children'][$key] ??= self::newStructureNode();
            $node = &$node['children'][$key];
        }

        return $node;
    }

    /**
     * Mark every collection surrounding the current value as non-uniform.
     */
    private function markEnclosingCollectionsNonUniform(): void
    {
        $this->ensureStructureNodeAtCurrentPath();
        $node = &$this->structure;

        foreach ($this->path as $segment) {
            if ($segment['itemKey'] !== null) {
                $node['uniform'] = false;

                continue;
            }

            $key = $segment['structureKey'];
            $node = &$node['children'][$key];
        }
    }

    /**
     * Determine if the current traversal path contains a collection item.
     */
    private function pathContainsItem(): bool
    {
        foreach ($this->path as $segment) {
            if ($segment['itemKey'] !== null) {
                return true;
            }
        }

        return false;
    }

    /**
     * Create an empty structure node.
     *
     * @param null|class-string<BaseData> $class
     * @return array{
     *     class: null|class-string<BaseData>,
     *     mappings: array<string, array-key>,
     *     children: array<string, array>,
     *     autoLazy?: array<string, array{source: mixed, replay?: AutoLazyReplayMode}>,
     *     paginatorSource?: AbstractCursorPaginator|AbstractPaginator,
     *     uniform?: false,
     *     items?: array<array-key, array>
     * }
     */
    private static function newStructureNode(?string $class = null): array
    {
        return [
            'class' => $class,
            'mappings' => [],
            'children' => [],
        ];
    }
}
