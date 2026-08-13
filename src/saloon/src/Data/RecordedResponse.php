<?php

declare(strict_types=1);

namespace Hypervel\Saloon\Data;

use Hypervel\Saloon\Exceptions\FixtureException;
use Hypervel\Saloon\Http\Faking\MockResponse;
use Hypervel\Saloon\Http\Response;
use JsonSerializable;

class RecordedResponse implements JsonSerializable
{
    /**
     * Create a recorded response.
     *
     * @param array<string, list<string>> $headers
     * @param array<string, mixed> $context
     */
    public function __construct(
        public int $statusCode,
        public array $headers = [],
        public string $data = '',
        public array $context = [],
    ) {
    }

    /**
     * Create a recorded response from fixture contents.
     */
    public static function fromFile(string $contents): static
    {
        /** @var array{statusCode: int, headers: array<string, list<string>>, data: string, encoding?: string, context?: array<string, mixed>} $fileData */
        $fileData = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        $data = $fileData['data'];

        if (($fileData['encoding'] ?? null) === 'base64') {
            $decoded = base64_decode($data, true);

            if ($decoded === false) {
                throw new FixtureException('The fixture contains invalid base64 response data.');
            }

            $data = $decoded;
        }

        return new static(
            statusCode: $fileData['statusCode'],
            headers: $fileData['headers'],
            data: $data,
            context: $fileData['context'] ?? [],
        );
    }

    /**
     * Create a recorded response from a Saloon response.
     */
    public static function fromResponse(Response $response): static
    {
        return new static(
            statusCode: $response->status(),
            headers: $response->headers(),
            data: $response->body(),
        );
    }

    /**
     * Encode the response for fixture storage.
     */
    public function toFile(): string
    {
        return json_encode($this, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
    }

    /**
     * Create a mock response from the recorded value.
     */
    public function toMockResponse(): MockResponse
    {
        return new MockResponse($this->data, $this->statusCode, $this->headers);
    }

    /**
     * Convert the recorded response to its fixture representation.
     *
     * @return array{statusCode: int, headers: array<string, list<string>>, data: string, context: array<string, mixed>, encoding?: 'base64'}
     */
    public function jsonSerialize(): array
    {
        $response = [
            'statusCode' => $this->statusCode,
            'headers' => $this->headers,
            'data' => $this->data,
            'context' => $this->context,
        ];

        if (! mb_check_encoding($this->data, 'UTF-8')) {
            $response['data'] = base64_encode($this->data);
            $response['encoding'] = 'base64';
        }

        return $response;
    }
}
