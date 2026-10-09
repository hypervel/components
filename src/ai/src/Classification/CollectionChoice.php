<?php

declare(strict_types=1);

namespace Hypervel\Ai\Classification;

use BackedEnum;
use Closure;
use Hypervel\Ai\Classification;
use Hypervel\Ai\Enums\Lab;
use Hypervel\Ai\Providers\Provider;
use Hypervel\Ai\Responses\Data\ChoiceAnswer;
use Hypervel\Support\Collection;
use InvalidArgumentException;
use Stringable;
use UnitEnum;

final class CollectionChoice
{
    /**
     * The option names mapped to their descriptions.
     *
     * @var array<string, null|array<string, mixed>|string>
     */
    private array $options = [];

    /**
     * The option names mapped to the items they represent.
     *
     * @var array<string, mixed>
     */
    private array $items = [];

    /**
     * Create a new choice between the items of the given collection.
     *
     * A string-keyed collection of strings without a "by" or "describe" resolver maps option names to descriptions, and its keys are chosen.
     *
     * @param Collection<array-key, mixed> $collection
     * @param null|(Closure(mixed): mixed)|string $by the field or closure that names each item
     * @param null|array<int, string>|(Closure(mixed): mixed)|string $describe the field, fields, or closure that describe each item
     *
     * @throws InvalidArgumentException if an item cannot be named or two items share a name
     */
    public function __construct(
        Collection $collection,
        Closure|string|null $by = null,
        Closure|array|string|null $describe = null,
    ) {
        $inline = is_null($by) && is_null($describe) && $collection->isNotEmpty()
            && $collection->every(fn (mixed $value, int|string $key): bool => is_string($key) && is_string($value));

        foreach ($inline ? $collection->keys() : $collection->values() as $item) {
            $name = $this->resolveLabel($item, $by);

            if (array_key_exists($name, $this->items)) {
                throw new InvalidArgumentException("Multiple items resolve to the option name [{$name}].");
            }

            $this->options[$name] = $inline ? $collection->get($item) : $this->resolveDescription($item, $describe);
            $this->items[$name] = $item;
        }
    }

    /**
     * Choose the item that best answers the question about the given text.
     *
     * @param array<string, mixed>|string $text
     * @param null|float $threshold the minimum probability of the chosen option; below it, null is returned
     *
     * @throws InvalidArgumentException if fewer than two options are available
     */
    public function decide(
        string $question,
        string|array $text,
        ?float $threshold = null,
        Provider|Lab|array|string|null $provider = null,
        ?string $model = null,
        ?int $timeout = null,
    ): mixed {
        $request = Classification::of($text)->question('decision', new Choice($question, $this->options));

        if (! is_null($timeout)) {
            $request->timeout($timeout);
        }

        /** @var ChoiceAnswer $answer */
        $answer = $request->classify($provider, $model)->answer('decision');

        if (! is_null($threshold) && $answer->probabilityOf($answer->choice) < $threshold) {
            return null;
        }

        return $this->items[$answer->choice] ?? null;
    }

    /**
     * Resolve the option name for the given item.
     *
     * @throws InvalidArgumentException if the item cannot be named
     */
    private function resolveLabel(mixed $item, Closure|string|null $by): string
    {
        $label = match (true) {
            $by instanceof Closure => $by($item),
            is_string($by) => data_get($item, $by),
            $item instanceof BackedEnum && is_string($item->value) => $item->value,
            $item instanceof UnitEnum => $item->name,
            default => $item,
        };

        if (! is_string($label) && ! $label instanceof Stringable) {
            throw new InvalidArgumentException('Unable to determine an option name for the item; pass a "by" field or closure.');
        }

        return (string) $label;
    }

    /**
     * Resolve the option description for the given item.
     *
     * @param null|array<int, string>|Closure|string $describe
     * @return null|array<string, mixed>|string
     */
    private function resolveDescription(mixed $item, Closure|array|string|null $describe): string|array|null
    {
        return match (true) {
            $describe instanceof Closure => $describe($item),
            is_array($describe) => (new Collection($describe))
                ->mapWithKeys(fn (string $field): array => [$field => data_get($item, $field)])->all(),
            is_string($describe) => data_get($item, $describe),
            default => null,
        };
    }
}
