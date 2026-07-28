<?php

declare(strict_types=1);

namespace Hypervel\Queue\Jobs;

use Hypervel\Queue\InvalidPayloadException;
use Hypervel\Support\CarbonImmutable;
use JsonException;

class InspectedJob
{
    /**
     * Create a new inspected job instance.
     */
    public function __construct(
        public readonly ?string $uuid,
        public readonly ?string $queue,
        public readonly ?string $name,
        public readonly int $attempts,
        public readonly array $payload = [],
        public readonly ?CarbonImmutable $createdAt = null,
        public readonly int|string|null $id = null,
    ) {
    }

    /**
     * Create a new instance from a raw job payload.
     */
    public static function fromPayload(
        string $payload,
        ?int $attempts = null,
        ?string $queue = null,
        int|string|null $id = null,
    ): static {
        $context = ' on queue [' . ($queue ?? 'unknown') . ']'
            . ($id === null ? '' : " with record ID [{$id}]");

        try {
            $decoded = json_decode($payload, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidPayloadException(
                "Unable to inspect the queue job payload{$context}: {$exception->getMessage()}",
                $payload,
            );
        }

        if (! is_array($decoded)
            || ! isset($decoded['job'])
            || ! is_string($decoded['job'])
            || $decoded['job'] === ''
            || ! array_key_exists('data', $decoded)) {
            throw new InvalidPayloadException(
                "The queue job payload{$context} does not contain a valid job and data.",
                $payload,
            );
        }

        $uuid = $decoded['uuid'] ?? null;
        $name = $decoded['displayName'] ?? null;
        $createdAt = $decoded['createdAt'] ?? null;

        return new static(
            uuid: is_string($uuid) ? $uuid : null,
            queue: $queue,
            name: is_string($name) ? $name : null,
            attempts: $attempts ?? (int) ($decoded['attempts'] ?? 0),
            payload: $decoded,
            createdAt: is_numeric($createdAt)
                ? CarbonImmutable::createFromTimestamp((int) $createdAt)
                : null,
            id: $id,
        );
    }
}
