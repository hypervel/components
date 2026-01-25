<?php

declare(strict_types=1);

namespace Hypervel\Support;

use BackedEnum;
use Hypervel\Contracts\Support\Arrayable;
use Hypervel\Contracts\Support\Jsonable;
use Hypervel\Support\Str;
use Hyperf\ViewEngine\Contract\Htmlable;
use JsonException;
use JsonSerializable;
use Stringable;

class Js implements Htmlable, Stringable
{
    /**
     * The JavaScript string.
     */
    protected string $js;

    /**
     * Flags that should be used when encoding to JSON.
     *
     * @var int
     */
    protected const REQUIRED_FLAGS = JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_THROW_ON_ERROR;

    /**
     * Create a new class instance.
     *
     * @throws JsonException
     */
    public function __construct(mixed $data, int $flags = 0, int $depth = 512)
    {
        $this->js = $this->convertDataToJavaScriptExpression($data, $flags, $depth);
    }

    /**
     * Create a new JavaScript string from the given data.
     *
     * @throws JsonException
     */
    public static function from(mixed $data, int $flags = 0, int $depth = 512): static
    {
        return new static($data, $flags, $depth);
    }

    /**
     * Convert the given data to a JavaScript expression.
     *
     * @throws JsonException
     */
    protected function convertDataToJavaScriptExpression(mixed $data, int $flags = 0, int $depth = 512): string
    {
        if ($data instanceof self) {
            return $data->toHtml();
        }

        if ($data instanceof BackedEnum) {
            $data = $data->value;
        }

        $json = static::encode($data, $flags, $depth);

        if (is_string($data)) {
            return "'" . substr($json, 1, -1) . "'";
        }

        return $this->convertJsonToJavaScriptExpression($json, $flags);
    }

    /**
     * Encode the given data as JSON.
     *
     * @throws JsonException
     */
    public static function encode(mixed $data, int $flags = 0, int $depth = 512): string
    {
        if ($data instanceof Jsonable) {
            return (string) $data;
        }

        if ($data instanceof Arrayable && ! ($data instanceof JsonSerializable)) {
            $data = $data->toArray();
        }

        return json_encode($data, $flags | static::REQUIRED_FLAGS, $depth);
    }

    /**
     * Convert the given JSON to a JavaScript expression.
     *
     * @throws JsonException
     */
    protected function convertJsonToJavaScriptExpression(string $json, int $flags = 0): string
    {
        if ($json === '[]' || $json === '{}') {
            return $json;
        }

        if (Str::startsWith($json, ['"', '{', '['])) {
            return "JSON.parse('" . substr(json_encode($json, $flags | static::REQUIRED_FLAGS), 1, -1) . "')";
        }

        return $json;
    }

    /**
     * Get the string representation of the data for use in HTML.
     */
    public function toHtml(): string
    {
        return $this->js;
    }

    /**
     * Get the string representation of the data for use in HTML.
     */
    public function __toString(): string
    {
        return $this->toHtml();
    }
}
