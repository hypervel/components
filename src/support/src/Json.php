<?php

declare(strict_types=1);

namespace Hypervel\Support;

use Hypervel\Contracts\Support\Arrayable;
use Hypervel\Contracts\Support\Jsonable;
use JsonException;

class Json
{
    /**
     * Encode a value to JSON.
     *
     * @throws JsonException
     */
    public static function encode(mixed $data, int $flags = JSON_UNESCAPED_UNICODE, int $depth = 512): string
    {
        if ($data instanceof Jsonable) {
            return $data->toJson();
        }

        if ($data instanceof Arrayable) {
            $data = $data->toArray();
        }

        return json_encode($data, $flags | JSON_THROW_ON_ERROR, $depth);
    }

    /**
     * Decode a JSON string.
     *
     * @throws JsonException
     */
    public static function decode(string $json, bool $assoc = true, int $depth = 512, int $flags = 0): mixed
    {
        return json_decode($json, $assoc, $depth, $flags | JSON_THROW_ON_ERROR);
    }
}
