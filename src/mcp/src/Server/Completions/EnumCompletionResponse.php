<?php

declare(strict_types=1);

namespace Hypervel\Mcp\Server\Completions;

use BackedEnum;
use InvalidArgumentException;
use UnitEnum;

class EnumCompletionResponse extends CompletionResponse
{
    /**
     * Create an enum-backed completion response.
     *
     * @param class-string<UnitEnum> $enumClass
     */
    public function __construct(private string $enumClass)
    {
        if (! enum_exists($enumClass)) {
            throw new InvalidArgumentException("Class [{$enumClass}] is not an enum.");
        }

        parent::__construct([]);
    }

    /**
     * Resolve matching enum completions for the given prefix.
     */
    public function resolve(string $value): DirectCompletionResponse
    {
        $enumValues = array_map(
            fn (UnitEnum $case): string => $case instanceof BackedEnum ? (string) $case->value : $case->name,
            $this->enumClass::cases()
        );

        $filtered = CompletionHelper::filterByPrefix($enumValues, $value);

        $total = count($filtered);
        $hasMore = $total > self::MAX_VALUES;

        $truncated = array_slice($filtered, 0, self::MAX_VALUES);

        return new DirectCompletionResponse($truncated, $hasMore, $total);
    }
}
