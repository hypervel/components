<?php

declare(strict_types=1);

namespace Hypervel\Notifications\Slack\BlockKit\Elements\Selects;

use Hypervel\Notifications\Slack\BlockKit\Elements\Traits\GeneratesDefaultIds;
use InvalidArgumentException;

class StaticSelectElement extends SelectElement
{
    use GeneratesDefaultIds;

    /**
     * The select element options.
     *
     * @var array<string, SelectOption>
     */
    private array $options = [];

    /**
     * The initially selected option, if applicable.
     */
    private ?SelectOption $initialOption = null;

    /**
     * Create a new static select element instance.
     */
    public function __construct(?string $text = null)
    {
        $this->id($this->resolveDefaultId('static_select_', $text));
    }

    /**
     * Add an option to the select element.
     */
    public function addOption(string $text, string $value): static
    {
        $this->options[$value] = new SelectOption($text, $value);

        return $this;
    }

    /**
     * Set the default selected option for the select element.
     */
    public function initialOption(string $value): static
    {
        $option = $this->options[$value] ?? null;

        if ($option === null) {
            throw new InvalidArgumentException("Unknown option value: {$value}.");
        }

        $this->initialOption = $option;

        return $this;
    }

    /**
     * Get the instance as an array.
     */
    public function toArray(): array
    {
        $options = array_values($this->options);

        $options = array_map(fn (SelectOption $option) => $option->toArray(), $options);

        return array_filter(array_merge([
            'type' => 'static_select',
            'options' => $options,
            'initial_option' => $this->initialOption?->toArray(),
        ], parent::toArray()), fn ($value): bool => $value !== null);
    }
}
