<?php

declare(strict_types=1);

namespace Hypervel\Mcp\Schema;

use Hypervel\Mcp\Enums\IconTheme;
use Hypervel\Support\Arr;
use InvalidArgumentException;

class Implementation
{
    /**
     * Create implementation metadata.
     *
     * @param array<Icon> $icons
     */
    public function __construct(
        public string $name,
        public string $version,
        public ?string $title = null,
        public ?string $description = null,
        public array $icons = [],
        public ?string $websiteUrl = null,
    ) {
    }

    /**
     * Convert the implementation metadata to an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return Arr::whereNotNull([
            'name' => $this->name,
            'version' => $this->version,
            'title' => $this->title,
            'description' => $this->description,
            'icons' => $this->icons === [] ? null : array_map(fn (Icon $icon): array => $icon->toArray(), $this->icons),
            'websiteUrl' => $this->websiteUrl,
        ]);
    }

    /**
     * Parse implementation metadata from a protocol message.
     */
    public static function from(mixed $data): ?self
    {
        if (! is_array($data)) {
            return null;
        }

        try {
            $title = self::stringAt($data, 'title', '');
            $description = self::stringAt($data, 'description', '');

            return new self(
                name: self::stringAt($data, 'name'),
                version: self::stringAt($data, 'version'),
                title: $title === '' ? null : $title,
                description: $description === '' ? null : $description,
                icons: Arr::map(self::arrayAt($data, 'icons', []), function (mixed $icon): Icon {
                    $icon = Arr::wrap($icon);

                    return new Icon(
                        src: self::stringAt($icon, 'src'),
                        mimeType: self::stringAt($icon, 'mimeType', '') ?: null,
                        sizes: array_values(array_filter(self::arrayAt($icon, 'sizes', []), is_string(...))),
                        theme: IconTheme::tryFrom(self::stringAt($icon, 'theme', '')),
                    );
                }),
                websiteUrl: self::stringAt($data, 'websiteUrl', '') ?: null,
            );
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    /**
     * Get a string value from the metadata.
     *
     * @param array<array-key, mixed> $array
     */
    protected static function stringAt(array $array, string $key, ?string $default = null): string
    {
        $value = Arr::get($array, $key, $default);

        if (! is_string($value)) {
            throw new InvalidArgumentException("The [{$key}] value must be a string.");
        }

        return $value;
    }

    /**
     * Get an array value from the metadata.
     *
     * @param array<array-key, mixed> $array
     * @param null|array<array-key, mixed> $default
     * @return array<array-key, mixed>
     */
    protected static function arrayAt(array $array, string $key, ?array $default = null): array
    {
        $value = Arr::get($array, $key, $default);

        if (! is_array($value)) {
            throw new InvalidArgumentException("The [{$key}] value must be an array.");
        }

        return $value;
    }
}
