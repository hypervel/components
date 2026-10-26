<?php

declare(strict_types=1);

namespace Hypervel\Head\Tags;

use Hypervel\Head\Enums\TwitterCard;
use Hypervel\Head\Rendering\ResolvedHead;
use Hypervel\Head\Rendering\TagRenderer;
use Hypervel\Support\Arr;

/**
 * @phpstan-consistent-constructor
 *
 * @phpstan-type ImageAttributes array<string, string>
 */
class Twitter extends GroupedTagBuilder
{
    /**
     * Create the Twitter card properties and image.
     *
     * @param array<string, string> $properties
     * @param null|ImageAttributes $image
     */
    public function __construct(
        protected array $properties = [],
        protected ?array $image = null,
    ) {
    }

    /**
     * Get the builder key.
     */
    public static function key(): string
    {
        return 'twitter';
    }

    /**
     * Get the supported route attribute names.
     *
     * @return array<int, string>
     */
    public static function routeAttributeKeys(): array
    {
        return ['twitter', 'twitterImage'];
    }

    /**
     * Create Twitter card metadata from named values.
     */
    public static function make(
        TwitterCard|string|null $card = null,
        ?string $site = null,
        ?string $creator = null,
        ?string $title = null,
        ?string $description = null,
        ?string $image = null,
    ): self {
        $twitter = new self;

        $twitter->set([
            'card' => $card instanceof TwitterCard ? $card->value : $card,
            'site' => $site,
            'creator' => $creator,
            'title' => $title,
            'description' => $description,
        ]);

        if (! is_null($image)) {
            $twitter->image($image);
        }

        return $twitter;
    }

    /**
     * Build Twitter card metadata from a route attribute.
     */
    public static function fromRouteAttribute(string $key, mixed $value): ?self
    {
        return match ($key) {
            'twitter' => is_array($value) ? self::fromAttributes($value) : null,
            'twitterImage' => self::fromImageAttributes($value),
            default => null,
        };
    }

    /**
     * Create Twitter card metadata from an attribute map.
     *
     * @param array<mixed, mixed> $values
     */
    public static function fromAttributes(array $values): self
    {
        return self::make(
            card: self::twitterCard($values['card'] ?? null),
            site: self::string($values['site'] ?? null),
            creator: self::string($values['creator'] ?? null),
            title: self::string($values['title'] ?? null),
            description: self::string($values['description'] ?? null),
            image: self::string($values['image'] ?? null),
        );
    }

    /**
     * Build a Twitter image from route values.
     */
    public static function fromImageAttributes(mixed $image): self
    {
        $twitter = new self;

        if (is_string($image)) {
            return $twitter->image($image);
        }

        if (is_array($image) && is_string($image['url'] ?? null)) {
            return $twitter->image($image['url'], alt: self::string($image['alt'] ?? null));
        }

        return $twitter;
    }

    /**
     * Set the Twitter card image.
     */
    public function image(string $url, ?string $alt = null): static
    {
        $this->image = Arr::whereNotNull([
            'url' => $url,
            'alt' => $alt,
        ]);

        return $this;
    }

    /**
     * Overlay these card properties and image onto the lower layer.
     */
    public function overlayOn(?TagBuilder $base): static
    {
        if (! $base instanceof self) {
            return $this;
        }

        return new static(
            array_replace($base->properties, $this->properties),
            $this->image ?? $base->image,
        );
    }

    /**
     * Determine whether no card properties or image are defined.
     */
    public function isEmpty(): bool
    {
        return $this->properties === [] && is_null($this->image);
    }

    /**
     * Get the Twitter card properties, falling back to the page title and description.
     *
     * @return array<string, string>
     */
    public function render(?string $title = null, ?string $description = null): array
    {
        $properties = $this->properties;

        if ($title !== null && $title !== '' && ! isset($properties['title'])) {
            $properties['title'] = $title;
        }

        if ($description !== null && $description !== '' && ! isset($properties['description'])) {
            $properties['description'] = $description;
        }

        return $properties;
    }

    /**
     * Get the resolved Twitter card metadata for the head array.
     *
     * @return array<string, mixed>
     */
    public function toHeadArray(ResolvedHead $head): array
    {
        return $this->headArray($head->title(), $head->description(), $head->openGraphImage());
    }

    /**
     * Render the Twitter card meta tags.
     *
     * @return array<int, string>
     */
    public function toTags(ResolvedHead $head, TagRenderer $tags): array
    {
        $properties = $this->render($head->title(), $head->description());
        $rendered = array_map(
            fn (string $name, string $content): string => $tags->meta('name', 'twitter:' . $name, $content, 'twitter:' . $name),
            array_keys($properties),
            $properties,
        );

        if (is_null($image = $this->headArrayImage($head->openGraphImage()))) {
            return $rendered;
        }

        $rendered[] = $tags->meta('name', 'twitter:image', $image['url'], 'twitter:image');

        if (isset($image['alt'])) {
            $rendered[] = $tags->meta('name', 'twitter:image:alt', $image['alt'], 'twitter:image:alt');
        }

        return $rendered;
    }

    /**
     * Get the card image or its Open Graph fallback.
     *
     * @param null|ImageAttributes $fallback
     * @return null|ImageAttributes
     */
    protected function headArrayImage(?array $fallback = null): ?array
    {
        return $this->image ?? $fallback;
    }

    /**
     * Get nonempty Twitter card properties and the resolved image.
     *
     * @param null|ImageAttributes $fallbackImage
     * @return array<string, mixed>
     */
    protected function headArray(?string $title = null, ?string $description = null, ?array $fallbackImage = null): array
    {
        return array_filter([
            ...$this->render($title, $description),
            'image' => $this->headArrayImage($fallbackImage),
        ], fn (string|array|null $value): bool => $value !== null && $value !== '' && $value !== []);
    }

    /**
     * Set each specified Twitter card property.
     *
     * @param array<string, null|string> $properties
     */
    protected function set(array $properties): void
    {
        foreach ($properties as $property => $value) {
            if (! is_null($value)) {
                $this->properties[$property] = $value;
            }
        }
    }

    /**
     * Resolve a Twitter card type value.
     */
    protected static function twitterCard(mixed $value): TwitterCard|string|null
    {
        return $value instanceof TwitterCard || is_string($value) ? $value : null;
    }
}
