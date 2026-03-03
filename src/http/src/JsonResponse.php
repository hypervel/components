<?php

declare(strict_types=1);

namespace Hypervel\Http;

use Hypervel\Contracts\Support\Arrayable;
use Hypervel\Contracts\Support\Jsonable;
use Hypervel\Support\Traits\Macroable;
use InvalidArgumentException;
use JsonSerializable;
use Override;
use Symfony\Component\HttpFoundation\JsonResponse as BaseJsonResponse;

class JsonResponse extends BaseJsonResponse
{
    use ResponseTrait, Macroable {
        Macroable::__call as macroCall;
    }

    /**
     * Create a new JSON response instance.
     */
    public function __construct(mixed $data = null, int $status = 200, array $headers = [], int $options = 0, bool $json = false)
    {
        $this->encodingOptions = $options;

        parent::__construct($data, $status, $headers, $json);
    }

    /**
     * Create an instance from a JSON string.
     */
    #[Override]
    public static function fromJsonString(?string $data = null, int $status = 200, array $headers = []): static
    {
        return new static($data, $status, $headers, 0, true);
    }

    /**
     * Set the JSONP callback.
     */
    public function withCallback(?string $callback = null): static
    {
        return $this->setCallback($callback);
    }

    /**
     * Get the decoded JSON data from the response.
     */
    public function getData(bool $assoc = false, int $depth = 512): mixed
    {
        return json_decode($this->data, $assoc, $depth);
    }

    /**
     * Set the data to be sent as JSON.
     *
     * @throws InvalidArgumentException
     */
    #[Override]
    public function setData($data = []): static
    {
        $this->original = $data;

        // Ensure json_last_error() is cleared...
        json_decode('[]');

        $this->data = match (true) {
            $data instanceof Jsonable => $data->toJson($this->encodingOptions),
            $data instanceof JsonSerializable => json_encode($data->jsonSerialize(), $this->encodingOptions),
            $data instanceof Arrayable => json_encode($data->toArray(), $this->encodingOptions),
            default => json_encode($data, $this->encodingOptions),
        };

        if (! $this->hasValidJson(json_last_error())) {
            throw new InvalidArgumentException(json_last_error_msg());
        }

        return $this->update();
    }

    /**
     * Determine if an error occurred during JSON encoding.
     */
    protected function hasValidJson(int $jsonError): bool
    {
        if ($jsonError === JSON_ERROR_NONE) {
            return true;
        }

        return $this->hasEncodingOption(JSON_PARTIAL_OUTPUT_ON_ERROR)
                    && in_array($jsonError, [
                        JSON_ERROR_RECURSION,
                        JSON_ERROR_INF_OR_NAN,
                        JSON_ERROR_UNSUPPORTED_TYPE,
                    ]);
    }

    /**
     * Set the JSON encoding options.
     */
    #[Override]
    public function setEncodingOptions($options): static
    {
        $this->encodingOptions = (int) $options;

        return $this->setData($this->getData());
    }

    /**
     * Determine if a JSON encoding option is set.
     */
    public function hasEncodingOption(int $option): bool
    {
        return (bool) ($this->encodingOptions & $option);
    }
}
