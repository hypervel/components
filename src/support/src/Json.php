<?php

declare(strict_types=1);

namespace Hypervel\Support;

use Hypervel\Contracts\Support\Arrayable;
use Hypervel\Contracts\Support\Jsonable;
use JsonException;

class Json
{
    /** Maximum number of nested JSON containers. */
    public const int MAXIMUM_NESTING_DEPTH = 512;

    /**
     * Encode a value to JSON.
     *
     * @throws JsonException
     */
    public static function encode(mixed $data, int $flags = JSON_UNESCAPED_UNICODE, int $depth = self::MAXIMUM_NESTING_DEPTH): string
    {
        if ($data instanceof Jsonable) {
            // Jsonable owns its nesting limit because its contract accepts only flags.
            return $data->toJson($flags | JSON_THROW_ON_ERROR);
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
    public static function decode(string $json, bool $assoc = true, int $depth = self::MAXIMUM_NESTING_DEPTH, int $flags = 0): mixed
    {
        return json_decode($json, $assoc, self::nativeDecodingDepth($depth), $flags | JSON_THROW_ON_ERROR);
    }

    /**
     * Decode JSON with every number preserved as its original string.
     *
     * @throws JsonException
     */
    public static function decodeNumbersAsStrings(string $json, bool $assoc = true, int $depth = self::MAXIMUM_NESTING_DEPTH): mixed
    {
        // Quoting numbers before validation could make malformed JSON valid, such as {1:2}.
        if (! self::validate($json, $depth)) {
            throw new JsonException(json_last_error_msg(), json_last_error());
        }

        $length = strlen($json);
        $offset = 0;
        $start = 0;
        $quoted = '';

        while ($offset < $length) {
            $offset += strcspn($json, '"-0123456789', $offset);

            if ($offset === $length) {
                break;
            }

            if ($json[$offset] === '"') {
                ++$offset;

                while (true) {
                    $offset += strcspn($json, '"\\', $offset);

                    if ($json[$offset] === '"') {
                        ++$offset;

                        break;
                    }

                    $offset += 2;
                }

                continue;
            }

            $numberLength = strspn($json, '0123456789+-.eE', $offset);
            $quoted .= substr($json, $start, $offset - $start)
                . '"' . substr($json, $offset, $numberLength) . '"';
            $offset += $numberLength;
            $start = $offset;
        }

        return self::decode($quoted . substr($json, $start), $assoc, $depth);
    }

    /**
     * Validate a JSON string.
     *
     * @param int-mask<JSON_INVALID_UTF8_IGNORE> $flags
     */
    public static function validate(string $json, int $depth = self::MAXIMUM_NESTING_DEPTH, int $flags = 0): bool
    {
        return json_validate($json, self::nativeDecodingDepth($depth), $flags);
    }

    /**
     * Convert the public container limit to PHP's decoding depth unit.
     */
    private static function nativeDecodingDepth(int $depth): int
    {
        return $depth > 0 && $depth < PHP_INT_MAX ? $depth + 1 : $depth;
    }
}
