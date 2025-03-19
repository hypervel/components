<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Testing;

use Hyperf\Collection\Arr;
use Hyperf\Testing\Assert;
use Hyperf\Testing\AssertableJsonString;
use Hypervel\Foundation\Testing\Http\TestResponse;
use PHPUnit\Framework\ExpectationFailedException;
use ReflectionProperty;
use Throwable;

/**
 * @internal
 *
 * @mixin Assert
 */
class TestResponseAssert
{
    /**
     * Create a new TestResponse assertion helper.
     */
    private function __construct(protected TestResponse $response)
    {
    }

    /**
     * Create a new TestResponse assertion helper.
     */
    public static function withResponse(TestResponse $response): self
    {
        return new static($response);
    }

    /**
     * Pass method calls to the Assert class and decorate the exception message.
     *
     * @throws ExpectationFailedException
     */
    public function __call(string $name, array $arguments): void
    {
        try {
            Assert::$name(...$arguments);
        } catch (ExpectationFailedException $e) {
            throw $this->injectResponseContext($e);
        }
    }

    /**
     * Pass static method calls to the Assert class.
     *
     * @throws ExpectationFailedException
     */
    public static function __callStatic(string $name, array $arguments): void
    {
        Assert::$name(...$arguments);
    }

    /**
     * Inject additional context from the response into the exception message.
     */
    protected function injectResponseContext(ExpectationFailedException $exception): ExpectationFailedException
    {
        if ($this->response->getHeader('Content-Type') === 'application/json') {
            $testJson = new AssertableJsonString($this->response->getContent());

            if (isset($testJson['errors'])) {
                return $this->appendErrorsToException($testJson->json(), $exception, true);
            }
        }

        return $exception;
    }

    /**
     * Append an exception to the message of another exception.
     */
    protected function appendExceptionToException(Throwable $exceptionToAppend, ExpectationFailedException $exception): ExpectationFailedException
    {
        $exceptionMessage = is_string($exceptionToAppend) ? $exceptionToAppend : $exceptionToAppend->getMessage();

        $exceptionToAppend = (string) $exceptionToAppend;

        $message = <<<"EOF"
            The following exception occurred during the last request:

            {$exceptionToAppend}

            ----------------------------------------------------------------------------------

            {$exceptionMessage}
            EOF;

        return $this->appendMessageToException($message, $exception);
    }

    /**
     * Append errors to an exception message.
     */
    protected function appendErrorsToException(array $errors, ExpectationFailedException $exception, bool $json = false): ExpectationFailedException
    {
        $errors = $json
            ? json_encode($errors, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            : implode(PHP_EOL, Arr::flatten($errors));

        // JSON error messages may already contain the errors, so we shouldn't duplicate them...
        if (str_contains($exception->getMessage(), $errors)) {
            return $exception;
        }

        $message = <<<"EOF"
            The following errors occurred during the last request:

            {$errors}
            EOF;

        return $this->appendMessageToException($message, $exception);
    }

    /**
     * Append a message to an exception.
     */
    protected function appendMessageToException(string $message, ExpectationFailedException $exception): ExpectationFailedException
    {
        $property = new ReflectionProperty($exception, 'message');

        $property->setValue(
            $exception,
            $exception->getMessage() . PHP_EOL . PHP_EOL . $message . PHP_EOL
        );

        return $exception;
    }
}
