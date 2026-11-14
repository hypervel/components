<?php

declare(strict_types=1);

namespace Hypervel\Concurrency;

use Hypervel\Support\Json;
use Hypervel\Support\Str;
use RuntimeException;
use SensitiveParameter;
use Throwable;

/**
 * Decode serialized-closure responses produced by the framework subprocess command.
 *
 * Authentication separates the framework's result envelope from task output.
 * Task results may contain objects, so decode only authenticated envelopes before
 * allowing unrestricted unserialization or remote exception reconstruction.
 *
 * @internal
 *
 * @phpstan-type ResultEnvelope array{successful: false, exception: string, message: string, parameters: array<string, mixed>}|array{successful: true, result: string}
 */
class SerializedClosureResult
{
    public const string ENVELOPE_MARKER = '@@HYPERVEL-CONCURRENCY-V1@@';

    public const string KEY_ENVIRONMENT_VARIABLE = 'HYPERVEL_CONCURRENCY_HMAC_KEY';

    /**
     * Extract the signed result envelope from the given process output.
     *
     * @return ResultEnvelope
     *
     * @throws RuntimeException
     */
    public static function parseEnvelope(string $output, #[SensitiveParameter] string $key): array
    {
        $length = strlen($output);
        $position = strrpos($output, self::ENVELOPE_MARKER);

        while ($position !== false) {
            if (preg_match('/\G([A-Za-z0-9+\/=]+)@@([0-9a-f]{64})@@/', $output, $matches, 0, $position + strlen(self::ENVELOPE_MARKER)) === 1
                && hash_equals(hash_hmac('sha256', $matches[1], $key), $matches[2])) {
                return Json::decode(base64_decode($matches[1]));
            }

            $position = $position === 0 ? false : strrpos($output, self::ENVELOPE_MARKER, $position - $length - 1);
        }

        throw new RuntimeException('The concurrent process did not return a valid result envelope. Output: ' . Str::limit(trim($output), 200));
    }

    /**
     * Return the authenticated result or throw the reconstructed remote exception.
     *
     * @param ResultEnvelope $payload
     *
     * @throws Throwable
     */
    public static function decode(array $payload): mixed
    {
        if ($payload['successful'] === false) {
            $exceptionClass = $payload['exception'];
            $message = $payload['message'];
            $parameters = $payload['parameters'];

            try {
                if (! is_a($exceptionClass, Throwable::class, true)) {
                    throw new RuntimeException("The transported exception class [{$exceptionClass}] is not an available Throwable.");
                }

                $exception = new $exceptionClass(...$parameters);
            } catch (Throwable $constructionException) {
                throw new RuntimeException($message, previous: $constructionException);
            }

            throw $exception;
        }

        return unserialize(base64_decode($payload['result']));
    }
}
