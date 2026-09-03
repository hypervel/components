<?php

declare(strict_types=1);

namespace Hypervel\Data\Support\Transformation;

use Hypervel\Data\Exceptions\CannotPerformPartialOnDataField;

final readonly class PartialTree
{
    /**
     * Create a compiled partial tree.
     *
     * @param array<string, self> $children
     */
    private function __construct(
        public bool $selected,
        public bool $all,
        public array $children,
    ) {
    }

    /**
     * Compile partial paths into one immutable tree.
     *
     * @param list<string> $paths
     */
    public static function compile(array $paths): ?self
    {
        if ($paths === []) {
            return null;
        }

        $tree = ['selected' => false, 'all' => false, 'children' => []];

        foreach ($paths as $path) {
            foreach (self::parse($path) as $segments) {
                self::insert($tree, $segments);
            }
        }

        return self::hydrate($tree);
    }

    /**
     * Determine if this tree contains a property endpoint or descendant.
     */
    public function contains(string $property): bool
    {
        return $this->all || isset($this->children[$property]);
    }

    /**
     * Determine if this tree selects a property at the current level.
     */
    public function selects(string $property): bool
    {
        return $this->all || ($this->children[$property]->selected ?? false);
    }

    /**
     * Get the nested selection for a property.
     */
    public function child(string $property): ?self
    {
        if (isset($this->children[$property])) {
            return $this->children[$property];
        }

        if (! $this->all) {
            return null;
        }

        return $this->children === []
            ? $this
            : new self(selected: false, all: true, children: []);
    }

    /**
     * Merge another compiled selection into this tree.
     */
    public function merge(?self $other): self
    {
        if ($other === null) {
            return $this;
        }

        $children = [];

        foreach ($this->children as $property => $child) {
            $children[$property] = $child->merge($other->child($property));
        }

        foreach ($other->children as $property => $child) {
            $children[$property] ??= $child->merge($this->child($property));
        }

        return new self(
            $this->selected || $other->selected,
            $this->all || $other->all,
            $children,
        );
    }

    /**
     * Parse one familiar partial path into concrete segment lists.
     *
     * @return non-empty-list<non-empty-list<string>>
     */
    private static function parse(string $path): array
    {
        $path = trim($path);

        if ($path === '') {
            throw CannotPerformPartialOnDataField::invalidPath($path);
        }

        $segments = explode('.', $path);
        $prefix = [];

        foreach ($segments as $index => $segment) {
            $segment = trim($segment);

            if ($segment === '') {
                throw CannotPerformPartialOnDataField::invalidPath($path);
            }

            if ($segment === '*') {
                if ($index !== array_key_last($segments)) {
                    throw CannotPerformPartialOnDataField::invalidPath($path);
                }

                return [[...$prefix, '*']];
            }

            if (str_starts_with($segment, '{') || str_ends_with($segment, '}')) {
                if ($index !== array_key_last($segments)
                    || ! str_starts_with($segment, '{')
                    || ! str_ends_with($segment, '}')
                ) {
                    throw CannotPerformPartialOnDataField::invalidPath($path);
                }

                $fields = array_map('trim', explode(',', substr($segment, 1, -1)));

                if ($fields === [] || in_array('', $fields, true)) {
                    throw CannotPerformPartialOnDataField::invalidPath($path);
                }

                $paths = [];

                foreach (array_values(array_unique($fields)) as $field) {
                    if (str_contains($field, '*')
                        || str_contains($field, '{')
                        || str_contains($field, '}')
                    ) {
                        throw CannotPerformPartialOnDataField::invalidPath($path);
                    }

                    $paths[] = [...$prefix, $field];
                }

                return $paths;
            }

            if (str_contains($segment, '*')
                || str_contains($segment, '{')
                || str_contains($segment, '}')
            ) {
                throw CannotPerformPartialOnDataField::invalidPath($path);
            }

            $prefix[] = $segment;
        }

        return [$prefix];
    }

    /**
     * Insert one parsed path into the mutable build tree.
     *
     * @param array{selected: bool, all: bool, children: array<string, array>} $tree
     * @param non-empty-list<string> $segments
     */
    private static function insert(array &$tree, array $segments): void
    {
        $segment = array_shift($segments);

        if ($segment === '*') {
            $tree['all'] = true;

            return;
        }

        $tree['children'][$segment] ??= [
            'selected' => false,
            'all' => false,
            'children' => [],
        ];

        if ($segments === []) {
            $tree['children'][$segment]['selected'] = true;

            return;
        }

        self::insert($tree['children'][$segment], $segments);
    }

    /**
     * Hydrate an immutable tree from its mutable build representation.
     *
     * @param array{selected: bool, all: bool, children: array<string, array>} $tree
     */
    private static function hydrate(array $tree, bool $inheritedAll = false): self
    {
        $all = $inheritedAll || $tree['all'];
        $children = [];

        foreach ($tree['children'] as $property => $child) {
            $children[$property] = self::hydrate($child, $all);
        }

        return new self($tree['selected'], $all, $children);
    }
}
