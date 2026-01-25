<?php

declare(strict_types=1);

namespace Hypervel\Validation\Rules;

use Hypervel\Support\Arr;
use Hypervel\Support\Collection;
use Hypervel\Support\Facades\Validator;
use Hypervel\Support\Str;
use Hypervel\Support\Traits\Conditionable;
use Hypervel\Support\Traits\Macroable;
use Hypervel\Contracts\Validation\DataAwareRule;
use Hypervel\Contracts\Validation\Rule;
use Hypervel\Contracts\Validation\Validator as ValidatorContract;
use Hypervel\Contracts\Validation\ValidatorAwareRule;
use InvalidArgumentException;
use Stringable;

class File implements Rule, DataAwareRule, ValidatorAwareRule
{
    use Conditionable;
    use Macroable;

    /**
     * The MIME types that the given file should match. This array may also contain file extensions.
     */
    protected array $allowedMimetypes = [];

    /**
     * The extensions that the given file should match.
     */
    protected array $allowedExtensions = [];

    /**
     * The minimum size in kilobytes that the file can be.
     */
    protected ?int $minimumFileSize = null;

    /**
     * The maximum size in kilobytes that the file can be.
     */
    protected ?int $maximumFileSize = null;

    /**
     * An array of custom rules that will be merged into the validation rules.
     */
    protected array $customRules = [];

    /**
     * The error message after validation, if any.
     */
    protected array $messages = [];

    /**
     * The data under validation.
     */
    protected array $data = [];

    /**
     * The validator performing the validation.
     */
    protected ?ValidatorContract $validator = null;

    /**
     * The callback that will generate the "default" version of the file rule.
     *
     * @var null|array|callable|string
     */
    public static $defaultCallback;

    /**
     * Set the default callback to be used for determining the file default rules.
     *
     * If no arguments are passed, the default file rule configuration will be returned.
     *
     * @param null|callable|static $callback
     */
    public static function defaults(mixed $callback = null): ?static
    {
        if (is_null($callback)) {
            return static::default();
        }

        if (! is_callable($callback) && ! $callback instanceof static) { // @phpstan-ignore instanceof.alwaysTrue, booleanAnd.alwaysFalse (callable values like closures are not instances)
            throw new InvalidArgumentException('The given callback should be callable or an instance of ' . static::class);
        }

        static::$defaultCallback = $callback;

        return null;
    }

    /**
     * Get the default configuration of the file rule.
     */
    public static function default(): mixed
    {
        $file = is_callable(static::$defaultCallback)
            ? call_user_func(static::$defaultCallback)
            : static::$defaultCallback;

        return $file instanceof Rule ? $file : new self();
    }

    /**
     * Limit the uploaded file to only image types.
     */
    public static function image(bool $allowSvg = false): ImageFile
    {
        return new ImageFile($allowSvg);
    }

    /**
     * Limit the uploaded file to the given MIME types or file extensions.
     *
     * @param array<int, string>|string $mimetypes
     */
    public static function types(array|string $mimetypes): static
    {
        return tap(new static(), fn ($file) => $file->allowedMimetypes = (array) $mimetypes);
    }

    /**
     * Limit the uploaded file to the given file extensions.
     */
    public function extensions(array|string $extensions): static
    {
        $this->allowedExtensions = (array) $extensions;

        return $this;
    }

    /**
     * Indicate that the uploaded file should be exactly a certain size in kilobytes.
     */
    public function size(int|string $size): static
    {
        $this->minimumFileSize = (int) $this->toKilobytes($size);
        $this->maximumFileSize = $this->minimumFileSize;

        return $this;
    }

    /**
     * Indicate that the uploaded file should be between a minimum and maximum size in kilobytes.
     */
    public function between(int|string $minSize, int|string $maxSize): static
    {
        $this->minimumFileSize = (int) $this->toKilobytes($minSize);
        $this->maximumFileSize = (int) $this->toKilobytes($maxSize);

        return $this;
    }

    /**
     * Indicate that the uploaded file should be no less than the given number of kilobytes.
     */
    public function min(int|string $size): static
    {
        $this->minimumFileSize = (int) $this->toKilobytes($size);

        return $this;
    }

    /**
     * Indicate that the uploaded file should be no more than the given number of kilobytes.
     */
    public function max(int|string $size): static
    {
        $this->maximumFileSize = (int) $this->toKilobytes($size);

        return $this;
    }

    /**
     * Convert a potentially human-friendly file size to kilobytes.
     */
    protected function toKilobytes(int|string $size): mixed
    {
        if (! is_string($size)) {
            return $size;
        }

        $size = strtolower(trim($size));

        $value = floatval($size);

        return round(match (true) {
            Str::endsWith($size, 'kb') => $value * 1,
            Str::endsWith($size, 'mb') => $value * 1_000,
            Str::endsWith($size, 'gb') => $value * 1_000_000,
            Str::endsWith($size, 'tb') => $value * 1_000_000_000,
            default => throw new InvalidArgumentException('Invalid file size suffix.'),
        });
    }

    /**
     * Specify additional validation rules that should be merged with the default rules during validation.
     */
    public function rules(array|string|Stringable $rules): static
    {
        $this->customRules = array_merge($this->customRules, Arr::wrap($rules));

        return $this;
    }

    /**
     * Determine if the validation rule passes.
     */
    public function passes(string $attribute, mixed $value): bool
    {
        $this->messages = [];

        $validator = Validator::make(
            $this->data,
            [$attribute => $this->buildValidationRules()],
            $this->validator->customMessages, // @phpstan-ignore-line
            $this->validator->customAttributes // @phpstan-ignore-line
        );

        if ($validator->fails()) {
            return $this->fail($validator->messages()->all());
        }

        return true;
    }

    /**
     * Build the array of underlying validation rules based on the current state.
     */
    protected function buildValidationRules(): array
    {
        $rules = ['file'];

        $rules = array_merge($rules, $this->buildMimetypes());

        if (! empty($this->allowedExtensions)) {
            $rules[] = 'extensions:' . implode(',', array_map(strtolower(...), $this->allowedExtensions));
        }

        $rules[] = match (true) {
            is_null($this->minimumFileSize) && is_null($this->maximumFileSize) => null,
            is_null($this->maximumFileSize) => "min:{$this->minimumFileSize}",
            is_null($this->minimumFileSize) => "max:{$this->maximumFileSize}",
            $this->minimumFileSize !== $this->maximumFileSize => "between:{$this->minimumFileSize},{$this->maximumFileSize}",
            default => "size:{$this->minimumFileSize}",
        };

        return array_merge(array_filter($rules), $this->customRules);
    }

    /**
     * Separate the given mimetypes from extensions and return an array of correct rules to validate against.
     */
    protected function buildMimetypes(): array
    {
        if (count($this->allowedMimetypes) === 0) {
            return [];
        }

        $rules = [];

        $mimetypes = array_filter(
            $this->allowedMimetypes,
            fn ($type) => str_contains($type, '/')
        );

        $mimes = array_diff($this->allowedMimetypes, $mimetypes);

        if (count($mimetypes) > 0) {
            $rules[] = 'mimetypes:' . implode(',', $mimetypes);
        }

        if (count($mimes) > 0) {
            $rules[] = 'mimes:' . implode(',', $mimes);
        }

        return $rules;
    }

    /**
     * Adds the given failures, and return false.
     */
    protected function fail(array|string $messages): bool
    {
        $messages = Collection::wrap($messages)
            ->map(fn ($message) => $this->validator->getTranslator()->get($message))
            ->all();

        $this->messages = array_merge($this->messages, $messages);

        return false;
    }

    /**
     * Get the validation error message.
     */
    public function message(): array
    {
        return $this->messages;
    }

    /**
     * Set the current validator.
     */
    public function setValidator(ValidatorContract $validator): static
    {
        $this->validator = $validator;

        return $this;
    }

    /**
     * Set the current data under validation.
     */
    public function setData(array $data): static
    {
        $this->data = $data;

        return $this;
    }
}
