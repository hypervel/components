<?php

declare(strict_types=1);

namespace Hypervel\Grpc\Protocol;

/**
 * @internal
 */
final readonly class MediaType
{
    public const PROTOBUF = 'application/grpc+proto';

    private function __construct(private ?string $subtype)
    {
    }

    /**
     * Parse a gRPC media type.
     */
    public static function parse(string $value): ?self
    {
        [$mediaType] = explode(';', $value, 2);

        if (! preg_match(
            "/^application\\/grpc(?:\\+([!#$%&'*+.^_`|~0-9a-z-]+))?$/i",
            trim($mediaType),
            $matches,
        )) {
            return null;
        }

        return new self(isset($matches[1]) ? strtolower($matches[1]) : null);
    }

    /**
     * Determine whether the media type represents Protocol Buffers.
     */
    public function isProtobuf(): bool
    {
        return $this->subtype === null || $this->subtype === 'proto';
    }

    /**
     * Return the explicit gRPC representation subtype.
     */
    public function subtype(): ?string
    {
        return $this->subtype;
    }
}
