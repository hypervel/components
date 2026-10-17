<?php

declare(strict_types=1);

namespace Hypervel\Mcp\Server\Completions;

use Hypervel\Contracts\Support\Arrayable;
use InvalidArgumentException;
use UnitEnum;

/**
 * @implements Arrayable<array<string, mixed>>
 */
abstract class CompletionResponse implements Arrayable
{
    protected const int MAX_VALUES = 100;

    /**
     * Create a completion response.
     *
     * @param array<int, string> $values
     */
    public function __construct(
        protected array $values,
        protected bool $hasMore = false,
        protected ?int $total = null,
    ) {
        if (count($values) > self::MAX_VALUES) {
            throw new InvalidArgumentException(
                sprintf('Completion values cannot exceed %d items (received %d)', self::MAX_VALUES, count($values))
            );
        }

        $this->values = array_values($values);
    }

    /**
     * Create an empty completion response.
     */
    public static function empty(): CompletionResponse
    {
        return new DirectCompletionResponse([]);
    }

    /**
     * Create completions to match against an argument prefix.
     *
     * @param array<int, string>|class-string<UnitEnum> $items
     */
    public static function match(array|string $items): CompletionResponse
    {
        if (is_string($items)) {
            return new EnumCompletionResponse($items);
        }

        return new ArrayCompletionResponse($items);
    }

    /**
     * Create a response containing the given completions.
     *
     * @param array<int, string>|string $items
     */
    public static function result(array|string $items): CompletionResponse
    {
        if (is_array($items)) {
            $total = count($items);
            $hasMore = $total > self::MAX_VALUES;
            $truncated = array_slice($items, 0, self::MAX_VALUES);

            return new DirectCompletionResponse($truncated, $hasMore, $total);
        }

        return new DirectCompletionResponse([$items], false);
    }

    /**
     * Resolve completions for an argument value.
     */
    abstract public function resolve(string $value): CompletionResponse;

    /**
     * Get the completion values.
     *
     * @return array<int, string>
     */
    public function values(): array
    {
        return $this->values;
    }

    /**
     * Determine whether more completions are available.
     */
    public function hasMore(): bool
    {
        return $this->hasMore;
    }

    /**
     * Convert the completion response to an array.
     *
     * @return array{values: list<string>, total?: int, hasMore: bool}
     */
    public function toArray(): array
    {
        $total = $this->total ?? ($this->hasMore ? null : count($this->values));

        return [
            'values' => $this->values,
            ...$total === null ? [] : ['total' => $total],
            'hasMore' => $this->hasMore,
        ];
    }
}
