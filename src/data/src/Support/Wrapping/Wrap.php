<?php

declare(strict_types=1);

namespace Hypervel\Data\Support\Wrapping;

readonly class Wrap
{
    /**
     * Create a wrapping definition.
     */
    public function __construct(
        public WrapType $type,
        public ?string $key = null,
    ) {
    }

    /**
     * Wrap transformed data with the effective key.
     */
    public function wrap(array $data, ?string $globalKey): array
    {
        $wrapKey = $this->getKey($globalKey);

        return $wrapKey === null
            ? $data
            : [$wrapKey => $data];
    }

    /**
     * Get the effective wrapping key.
     */
    public function getKey(?string $globalKey): ?string
    {
        return match ($this->type) {
            WrapType::Disabled => null,
            WrapType::Defined => $this->key,
            WrapType::UseGlobal => $globalKey,
        };
    }

    /**
     * Get the serializable wrapping definition.
     */
    public function toSerializedArray(): array
    {
        return [
            'type' => $this->type->value,
            'key' => $this->key,
        ];
    }

    /**
     * Restore a serialized wrapping definition.
     */
    public static function fromSerializedArray(array $wrap): self
    {
        return new self(
            type: WrapType::from($wrap['type']),
            key: $wrap['key'] ?? null,
        );
    }
}
