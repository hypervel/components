<?php

declare(strict_types=1);

namespace Hypervel\Mcp\Server;

use Hypervel\Container\Container;
use Hypervel\Contracts\Support\Arrayable;
use Hypervel\Mcp\Schema\Icon;
use Hypervel\Mcp\Server\Attributes\Description;
use Hypervel\Mcp\Server\Attributes\Name;
use Hypervel\Mcp\Server\Attributes\Title;
use Hypervel\Mcp\Server\Concerns\HasIcons;
use Hypervel\Mcp\Server\Concerns\HasMeta;
use Hypervel\Support\Str;

/**
 * @implements Arrayable<array<string, mixed>>
 */
abstract class Primitive implements Arrayable
{
    use HasIcons;
    use HasMeta;

    protected string $name = '';

    protected string $title = '';

    protected string $description = '';

    /**
     * Get the primitive name.
     */
    public function name(): string
    {
        $attribute = $this->resolveAttribute(Name::class);

        return $attribute->value ?? ($this->name !== '' ? $this->name : Str::kebab(class_basename($this)));
    }

    /**
     * Get the primitive title.
     */
    public function title(): string
    {
        $attribute = $this->resolveAttribute(Title::class);

        return $attribute->value ?? ($this->title !== '' ? $this->title : Str::headline(class_basename($this)));
    }

    /**
     * Get the primitive description.
     */
    public function description(): string
    {
        $attribute = $this->resolveAttribute(Description::class);

        return $attribute->value ?? ($this->description !== '' ? $this->description : Str::headline(class_basename($this)));
    }

    /**
     * Get the primitive metadata.
     *
     * @return null|array<string, mixed>
     */
    public function meta(): ?array
    {
        return $this->meta;
    }

    /**
     * Get the primitive icons.
     *
     * @return list<Icon>
     */
    public function icons(): array
    {
        return [];
    }

    /**
     * Merge the resolved icons into the primitive array.
     *
     * @template T of array<string, mixed>
     *
     * @param T $baseArray
     * @return array{icons?: list<array<string, mixed>>}&T
     */
    protected function mergeIcons(array $baseArray): array
    {
        $icons = $this->resolvedIcons();

        if ($icons === []) {
            return $baseArray;
        }

        return [...$baseArray, 'icons' => array_map(fn (Icon $icon): array => $icon->toArray(), $icons)];
    }

    /**
     * Determine whether the primitive may be registered for this request.
     */
    public function eligibleForRegistration(): bool
    {
        if (method_exists($this, 'shouldRegister')) {
            return Container::getInstance()->call([$this, 'shouldRegister']);
        }

        return true;
    }

    /**
     * Convert the primitive to its method call representation.
     *
     * @return array<string, mixed>
     */
    abstract public function toMethodCall(): array;

    /**
     * Convert the primitive to an array.
     *
     * @return array<string, mixed>
     */
    abstract public function toArray(): array;
}
