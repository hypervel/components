<?php

declare(strict_types=1);

namespace Hypervel\Contracts\Engine\WebSocket;

use Psr\Http\Message\StreamInterface;
use Stringable;

interface FrameInterface extends Stringable
{
    /**
     * Get the opcode.
     */
    public function getOpcode(): int;

    /**
     * Set the opcode.
     */
    public function setOpcode(int $opcode): static;

    /**
     * Return a copy with the given opcode.
     */
    public function withOpcode(int $opcode): static;

    /**
     * Determine whether this is the final frame.
     */
    public function getFin(): bool;

    /**
     * Set whether this is the final frame.
     */
    public function setFin(bool $fin): static;

    /**
     * Return a copy with the given final-frame state.
     */
    public function withFin(bool $fin): static;

    /**
     * Get the first reserved bit.
     */
    public function getRSV1(): bool;

    /**
     * Set the first reserved bit.
     */
    public function setRSV1(bool $rsv1): static;

    /**
     * Return a copy with the given first reserved bit.
     */
    public function withRSV1(bool $rsv1): static;

    /**
     * Get the second reserved bit.
     */
    public function getRSV2(): bool;

    /**
     * Set the second reserved bit.
     */
    public function setRSV2(bool $rsv2): static;

    /**
     * Return a copy with the given second reserved bit.
     */
    public function withRSV2(bool $rsv2): static;

    /**
     * Get the third reserved bit.
     */
    public function getRSV3(): bool;

    /**
     * Set the third reserved bit.
     */
    public function setRSV3(bool $rsv3): static;

    /**
     * Return a copy with the given third reserved bit.
     */
    public function withRSV3(bool $rsv3): static;

    /**
     * Get the payload length.
     */
    public function getPayloadLength(): int;

    /**
     * Set the payload length.
     */
    public function setPayloadLength(int $payloadLength): static;

    /**
     * Return a copy with the given payload length.
     */
    public function withPayloadLength(int $payloadLength): static;

    /**
     * Determine whether the frame is masked.
     */
    public function getMask(): bool;

    /**
     * Get the masking key.
     */
    public function getMaskingKey(): string;

    /**
     * Set the masking key.
     */
    public function setMaskingKey(string $maskingKey): static;

    /**
     * Return a copy with the given masking key.
     */
    public function withMaskingKey(string $maskingKey): static;

    /**
     * Get the payload data.
     */
    public function getPayloadData(): StreamInterface;

    /**
     * Set the payload data.
     */
    public function setPayloadData(mixed $payloadData): static;

    /**
     * Return a copy with the given payload data.
     */
    public function withPayloadData(mixed $payloadData): static;

    /**
     * Convert the frame to a string.
     */
    public function toString(bool $withoutPayloadData = false): string;

    /**
     * Create a frame from the given value.
     */
    public static function from(mixed $frame): static;
}
