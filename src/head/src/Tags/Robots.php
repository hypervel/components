<?php

declare(strict_types=1);

namespace Hypervel\Head\Tags;

use Hypervel\Head\Enums\RobotsRule;
use Hypervel\Head\Rendering\ResolvedHead;
use Hypervel\Head\Rendering\TagRenderer;

class Robots extends StringTagBuilder
{
    /**
     * Get the builder key.
     */
    public static function key(): string
    {
        return 'robots';
    }

    /**
     * Create a robots tag from directives.
     *
     * @param array<mixed, mixed>|RobotsRule|string $directives
     */
    public static function make(string|RobotsRule|array $directives): static
    {
        return new static(self::renderDirectives($directives));
    }

    /**
     * Build robots directives from a route attribute.
     */
    public static function fromRouteAttribute(string $key, mixed $value): ?self
    {
        if ($key !== static::key()) {
            return null;
        }

        if (is_array($value)) {
            if ($value === []) {
                return new static;
            }

            $directives = static::renderDirectives($value);

            return $directives === '' ? null : new static($directives);
        }

        return is_string($value) || $value instanceof RobotsRule ? static::make($value) : null;
    }

    /**
     * Render the robots meta tag.
     *
     * @return array<int, string>
     */
    public function toTags(ResolvedHead $head, TagRenderer $tags): array
    {
        return ($robots = $this->render()) ? [$tags->meta('name', 'robots', $robots, 'robots')] : [];
    }

    /**
     * Normalize robots directives into a comma-separated value.
     *
     * @param array<mixed, mixed>|RobotsRule|string $directives
     */
    protected static function renderDirectives(string|RobotsRule|array $directives): string
    {
        if (is_string($directives)) {
            return $directives;
        }

        if ($directives instanceof RobotsRule) {
            return $directives->value;
        }

        return implode(', ', array_values(array_filter(array_map(
            fn (mixed $directive): ?string => match (true) {
                $directive instanceof RobotsRule => $directive->value,
                is_string($directive) => $directive,
                default => null,
            },
            $directives,
        ))));
    }
}
