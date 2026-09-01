<?php

declare(strict_types=1);

namespace Hypervel\Data\Attributes\Validation;

use Attribute;
use Hypervel\Data\Exceptions\CannotBuildValidationRule;
use Hypervel\Data\Support\Validation\References\ExternalReference;
use Hypervel\Data\Support\Validation\ValidationPath;
use Hypervel\Support\Str;
use Hypervel\Validation\Rules\Dimensions as BaseDimensions;

#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
class Dimensions extends ObjectValidationAttribute
{
    /**
     * Create a dimensions rule attribute.
     */
    public function __construct(
        protected int|string|ExternalReference|null $minWidth = null,
        protected int|string|ExternalReference|null $minHeight = null,
        protected int|string|ExternalReference|null $maxWidth = null,
        protected int|string|ExternalReference|null $maxHeight = null,
        protected float|string|ExternalReference|null $ratio = null,
        protected int|string|ExternalReference|null $width = null,
        protected int|string|ExternalReference|null $height = null,
        protected ?BaseDimensions $rule = null,
    ) {
        if (
            $minWidth === null
            && $minHeight === null
            && $maxWidth === null
            && $maxHeight === null
            && $ratio === null
            && $width === null
            && $height === null
            && $rule === null
        ) {
            throw CannotBuildValidationRule::create('You must specify one of width, height, minWidth, minHeight, maxWidth, maxHeight, ratio or a dimensions rule.');
        }
    }

    /**
     * Get the Validator rule object.
     */
    public function getRule(ValidationPath $path): object|string
    {
        if ($this->rule !== null) {
            return $this->rule;
        }

        $minWidth = $this->normalizePossibleExternalReferenceParameter($this->minWidth);
        $minHeight = $this->normalizePossibleExternalReferenceParameter($this->minHeight);
        $maxWidth = $this->normalizePossibleExternalReferenceParameter($this->maxWidth);
        $maxHeight = $this->normalizePossibleExternalReferenceParameter($this->maxHeight);
        $ratio = $this->normalizePossibleExternalReferenceParameter($this->ratio);
        $width = $this->normalizePossibleExternalReferenceParameter($this->width);
        $height = $this->normalizePossibleExternalReferenceParameter($this->height);

        $rule = new BaseDimensions();

        if ($minWidth !== null) {
            $rule->minWidth($minWidth);
        }

        if ($minHeight !== null) {
            $rule->minHeight($minHeight);
        }

        if ($maxWidth !== null) {
            $rule->maxWidth($maxWidth);
        }

        if ($maxHeight !== null) {
            $rule->maxHeight($maxHeight);
        }

        if ($width !== null) {
            $rule->width($width);
        }

        if ($height !== null) {
            $rule->height($height);
        }

        if ($ratio !== null) {
            $rule->ratio($ratio);
        }

        return $rule;
    }

    /**
     * Get the Validator rule keyword.
     */
    public static function keyword(): string
    {
        return 'dimensions';
    }

    /**
     * Create the attribute from parsed string parameters.
     */
    public static function create(string ...$parameters): static
    {
        $parameters = collect($parameters)->mapWithKeys(function (string $parameter) {
            return [Str::camel(Str::before($parameter, '=')) => Str::after($parameter, '=')];
        })->all();

        return new static(...$parameters);
    }
}
