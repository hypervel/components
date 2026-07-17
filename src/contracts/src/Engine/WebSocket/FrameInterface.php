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
     * Determine whether the frame is masked.
     */
    public function getMask(): bool;

    /**
     * Set whether the frame is masked.
     */
    public function setMask(bool $mask): static;

    /**
     * Return a copy with the given mask state.
     */
    public function withMask(bool $mask): static;

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
    public function toString(): string;

    /**
     * Create a frame from the given value.
     */
    public static function from(mixed $frame): static;
}
