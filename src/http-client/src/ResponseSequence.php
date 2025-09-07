<?php

declare(strict_types=1);

namespace Hypervel\HttpClient;

use Closure;
use GuzzleHttp\Promise\PromiseInterface;
use Hyperf\Macroable\Macroable;
use OutOfBoundsException;

class ResponseSequence
{
    use Macroable;

    /**
     * Indicates that invoking this sequence when it is empty should throw an exception.
     */
    protected bool $failWhenEmpty = true;

    /**
     * The response that should be returned when the sequence is empty.
     */
    protected PromiseInterface $emptyResponse;

    /**
     * Create a new response sequence.
     */
    public function __construct(
        protected array $responses
    ) {
    }

    /**
     * Push a response to the sequence.
     */
    public function push(array|string|null $body = null, int $status = 200, array $headers = []): static
    {
        return $this->pushResponse(
            Factory::response($body, $status, $headers)
        );
    }

    /**
     * Push a response with the given status code to the sequence.
     */
    public function pushStatus(int $status, array $headers = []): static
    {
        return $this->pushResponse(
            Factory::response('', $status, $headers)
        );
    }

    /**
     * Push a response with the contents of a file as the body to the sequence.
     */
    public function pushFile(string $filePath, int $status = 200, array $headers = []): static
    {
        $string = file_get_contents($filePath);

        return $this->pushResponse(
            Factory::response($string, $status, $headers)
        );
    }

    /**
     * Push a connection exception to the sequence.
     */
    public function pushFailedConnection(?string $message = null): static
    {
        return $this->pushResponse(
            Factory::failedConnection($message)
        );
    }

    /**
     * Push a response to the sequence.
     */
    public function pushResponse(mixed $response): static
    {
        $this->responses[] = $response;

        return $this;
    }

    /**
     * Make the sequence return a default response when it is empty.
     */
    public function whenEmpty(Closure|PromiseInterface $response): static
    {
        $this->failWhenEmpty = false;
        $this->emptyResponse = $response;

        return $this;
    }

    /**
     * Make the sequence return a default response when it is empty.
     */
    public function dontFailWhenEmpty(): static
    {
        return $this->whenEmpty(Factory::response());
    }

    /**
     * Indicate that this sequence has depleted all of its responses.
     */
    public function isEmpty(): bool
    {
        return count($this->responses) === 0;
    }

    /**
     * Get the next response in the sequence.
     *
     * @throws OutOfBoundsException
     */
    public function __invoke(Request $request): mixed
    {
        if ($this->failWhenEmpty && $this->isEmpty()) {
            throw new OutOfBoundsException('A request was made, but the response sequence is empty.');
        }

        if (! $this->failWhenEmpty && $this->isEmpty()) {
            return value($this->emptyResponse ?? Factory::response());
        }

        $response = array_shift($this->responses);

        return $response instanceof Closure ? $response($request) : $response;
    }
}
