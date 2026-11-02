<?php

declare(strict_types=1);

namespace Hypervel\Telescope;

use Hypervel\Coroutine\Coroutine;
use Hypervel\Support\Json;
use Throwable;

class JsonNormalizer
{
    /**
     * Normalize an observed value, purging serialization failures except for cancellation.
     */
    public static function normalize(mixed $value): mixed
    {
        try {
            $json = json_encode(
                $value,
                JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR,
                Json::MAXIMUM_NESTING_DEPTH,
            );

            if ($json === false) {
                return Telescope::PURGED_VALUE;
            }

            return Json::decode($json);
        } catch (Throwable $exception) {
            if (Coroutine::causedByCancellation($exception)) {
                throw $exception;
            }

            return Telescope::PURGED_VALUE;
        }
    }
}
