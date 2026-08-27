<?php

declare(strict_types=1);

namespace Hypervel\Prompts\Support;

use InvalidArgumentException;
use RuntimeException;

/**
 * Encode and incrementally decode Task transport frames.
 *
 * @internal
 */
class TaskFrame
{
    private const int HEADER_LENGTH = 5;

    private const int COMPACTION_THRESHOLD = 8192;

    /**
     * @var array<string, int>
     */
    private const array TYPES = [
        'line' => 1,
        'success' => 2,
        'warning' => 3,
        'error' => 4,
        'label' => 5,
        'sublabel' => 6,
        'reset' => 7,
        'partial' => 8,
        'commitpartial' => 9,
    ];

    private string $buffer = '';

    private int $cursor = 0;

    /**
     * Encode a Task message.
     */
    public static function encode(?string $type, string $payload): string
    {
        $name = $type ?? 'line';
        $identifier = self::TYPES[$name] ?? null;

        if ($identifier === null) {
            throw new InvalidArgumentException("Unknown task message type [{$name}].");
        }

        $length = strlen($payload);

        if ($length > 0xFFFFFFFF) {
            throw new InvalidArgumentException('Task message payload exceeds the protocol limit.');
        }

        return chr($identifier) . pack('N', $length) . $payload;
    }

    /**
     * Append bytes received from the Task transport.
     */
    public function append(string $bytes): void
    {
        $this->buffer .= $bytes;
    }

    /**
     * Decode the next complete Task message.
     *
     * @return null|array{type: ?string, payload: string}
     */
    public function next(): ?array
    {
        $remaining = strlen($this->buffer) - $this->cursor;

        if ($remaining < self::HEADER_LENGTH) {
            return null;
        }

        $identifier = ord($this->buffer[$this->cursor]);
        $type = array_search($identifier, self::TYPES, true);

        if ($type === false) {
            throw new RuntimeException("Unknown task message type [{$identifier}].");
        }

        $length = unpack('Nlength', $this->buffer, $this->cursor + 1)['length'];

        if ($remaining < self::HEADER_LENGTH + $length) {
            return null;
        }

        $payload = substr($this->buffer, $this->cursor + self::HEADER_LENGTH, $length);
        $this->cursor += self::HEADER_LENGTH + $length;
        $this->compact();

        return [
            'type' => $type === 'line' ? null : $type,
            'payload' => $payload,
        ];
    }

    /**
     * Finish decoding after the transport reaches EOF.
     */
    public function finish(): void
    {
        if ($this->cursor !== strlen($this->buffer)) {
            throw new RuntimeException('The prompt renderer received an incomplete task message.');
        }

        $this->reset();
    }

    /**
     * Reset all decoder state.
     */
    public function reset(): void
    {
        $this->buffer = '';
        $this->cursor = 0;
    }

    /**
     * Discard a substantial consumed prefix without copying on every frame.
     */
    private function compact(): void
    {
        $length = strlen($this->buffer);

        if ($this->cursor === $length) {
            $this->reset();

            return;
        }

        if ($this->cursor >= self::COMPACTION_THRESHOLD && $this->cursor >= intdiv($length, 2)) {
            $this->buffer = substr($this->buffer, $this->cursor);
            $this->cursor = 0;
        }
    }
}
