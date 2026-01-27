<?php

declare(strict_types=1);

namespace Hypervel\Validation\Rules;

use Hypervel\Conditionable\Traits\Conditionable;
use Stringable;

class Dimensions implements Stringable
{
    use Conditionable;

    /**
     * Create a new dimensions rule instance.
     *
     * @param array $constraints the constraints for the dimensions rule
     */
    public function __construct(
        protected array $constraints = []
    ) {
    }

    /**
     * Set the "width" constraint.
     */
    public function width(int|string $value): static
    {
        $this->constraints['width'] = $value;

        return $this;
    }

    /**
     * Set the "height" constraint.
     */
    public function height(int|string $value): static
    {
        $this->constraints['height'] = $value;

        return $this;
    }

    /**
     * Set the "min width" constraint.
     */
    public function minWidth(int|string $value): static
    {
        $this->constraints['min_width'] = $value;

        return $this;
    }

    /**
     * Set the "min height" constraint.
     */
    public function minHeight(int|string $value): static
    {
        $this->constraints['min_height'] = $value;

        return $this;
    }

    /**
     * Set the "max width" constraint.
     */
    public function maxWidth(int|string $value): static
    {
        $this->constraints['max_width'] = $value;

        return $this;
    }

    /**
     * Set the "max height" constraint.
     */
    public function maxHeight(int|string $value): static
    {
        $this->constraints['max_height'] = $value;

        return $this;
    }

    /**
     * Set the "ratio" constraint.
     */
    public function ratio(float|string $value): static
    {
        $this->constraints['ratio'] = $value;

        return $this;
    }

    /**
     * Set the minimum aspect ratio.
     */
    public function minRatio(float|string $value): static
    {
        $this->constraints['min_ratio'] = $value;

        return $this;
    }

    /**
     * Set the maximum aspect ratio.
     */
    public function maxRatio(float|string $value): static
    {
        $this->constraints['max_ratio'] = $value;

        return $this;
    }

    /**
     * Set the aspect ratio range.
     */
    public function ratioBetween(float|string $min, float|string $max): static
    {
        $this->constraints['min_ratio'] = $min;
        $this->constraints['max_ratio'] = $max;

        return $this;
    }

    /**
     * Convert the rule to a validation string.
     */
    public function __toString(): string
    {
        $result = '';

        foreach ($this->constraints as $key => $value) {
            $result .= "{$key}={$value},";
        }

        return 'dimensions:' . substr($result, 0, -1);
    }
}
