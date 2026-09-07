<?php

declare(strict_types=1);

namespace Hypervel\Concurrency;

use Hypervel\Support\Json;
use RuntimeException;
use Throwable;

/**
 * Decode serialized-closure responses produced by the framework subprocess command.
 *
 * The subprocess stream may also contain output from the application task, so the
 * response envelope is validated before use. Task results may contain objects, so
 * successful responses require unrestricted unserialization. Callers must never
 * pass external input to this decoder.
 *
 * @internal
 */
class SerializedClosureResult
{
    /**
     * Return the unserialized result or throw the reconstructed remote exception.
     *
     * @throws Throwable
     */
    public static function decode(string $output): mixed
    {
        if (($position = strpos($output, "\x1f\x8b")) !== false) {
            $output = substr($output, 0, $position);
        }

        $payload = Json::decode($output);

        if (! is_array($payload)
            || ! array_key_exists('successful', $payload)
            || ! is_bool($payload['successful'])) {
            throw new RuntimeException('Invalid serialized closure response envelope.');
        }

        /** @var array{
         *     successful: bool,
         *     result?: string,
         *     exception?: class-string<Throwable>,
         *     message?: string,
         *     parameters?: array<string, mixed>
         * } $payload
         */
        if ($payload['successful'] === false) {
            if ((array_key_exists('exception', $payload) && ! is_string($payload['exception']))
                || (array_key_exists('message', $payload) && ! is_string($payload['message']))
                || (array_key_exists('parameters', $payload) && ! is_array($payload['parameters']))) {
                throw new RuntimeException('Invalid serialized closure response envelope.');
            }

            $exceptionClass = $payload['exception'] ?? RuntimeException::class;
            $message = $payload['message'] ?? 'Serialized closure execution failed.';
            $parameters = $payload['parameters'] ?? ['message' => $message];

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

        $encodedResult = $payload['result'] ?? null;
        $serializedResult = is_string($encodedResult)
            ? base64_decode($encodedResult, true)
            : false;

        if ($serializedResult === false) {
            throw new RuntimeException('Unable to decode the serialized closure result.');
        }

        // Malformed payloads warn and return false, which is also a valid serialized result.
        $unserializedResult = @unserialize($serializedResult);

        if ($unserializedResult === false && $serializedResult !== serialize(false)) {
            throw new RuntimeException('Unable to decode the serialized closure result.');
        }

        return $unserializedResult;
    }
}
