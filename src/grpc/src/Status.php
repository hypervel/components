<?php

declare(strict_types=1);

namespace Hypervel\Grpc;

use Google\Rpc\Status as RichStatus;
use InvalidArgumentException;

final readonly class Status
{
    private ?RichStatus $details;

    public function __construct(
        private StatusCode $code,
        private string $message = '',
        ?RichStatus $details = null,
    ) {
        if ($details !== null && $code === StatusCode::Ok) {
            throw new InvalidArgumentException('An OK gRPC status cannot contain rich error details.');
        }

        if ($details !== null && $details->getCode() !== $code->value) {
            throw new InvalidArgumentException('The rich status code must match the gRPC status code.');
        }

        if ($details !== null && $details->getMessage() !== $message) {
            throw new InvalidArgumentException('The rich status message must match the gRPC status message.');
        }

        $this->details = $details === null ? null : self::copyDetails($details);
    }

    /**
     * Return the status code.
     */
    public function code(): StatusCode
    {
        return $this->code;
    }

    /**
     * Return the status message.
     */
    public function message(): string
    {
        return $this->message;
    }

    /**
     * Return the rich error details.
     */
    public function details(): ?RichStatus
    {
        return $this->details === null ? null : self::copyDetails($this->details);
    }

    /**
     * Determine whether the status is successful.
     */
    public function isOk(): bool
    {
        return $this->code === StatusCode::Ok;
    }

    /**
     * Copy rich details without retaining nested mutable protobuf values.
     */
    private static function copyDetails(RichStatus $details): RichStatus
    {
        $copy = new RichStatus;
        $copy->mergeFromString($details->serializeToString());

        return $copy;
    }
}
