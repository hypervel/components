<?php

declare(strict_types=1);

namespace Hypervel\Head\Tags;

use Hypervel\Head\Enums\ImageType;
use Hypervel\Head\Enums\OgType;
use Hypervel\Head\Rendering\ResolvedHead;
use Hypervel\Head\Rendering\TagRenderer;

/**
 * @phpstan-consistent-constructor
 *
 * @phpstan-type MediaAttributes array{url: string, alt?: string|null, width?: int|null, height?: int|null, type?: string|null, secureUrl?: string|null}
 */
class OpenGraph extends GroupedTagBuilder
{
    /**
     * Create the Open Graph properties and media.
     *
     * @param array<string, string> $properties
     * @param array<string, MediaAttributes> $images
     * @param array<string, MediaAttributes> $videos
     * @param array<string, MediaAttributes> $audios
     */
    public function __construct(
        protected array $properties = [],
        protected array $images = [],
        protected array $videos = [],
        protected array $audios = [],
    ) {
    }

    protected bool $imagesAreDefaults = false;

    protected bool $videosAreDefaults = false;

    protected bool $audiosAreDefaults = false;

    /**
     * Get the builder key.
     */
    public static function key(): string
    {
        return 'openGraph';
    }

    /**
     * Get the supported route attribute names.
     *
     * @return array<int, string>
     */
    public static function routeAttributeKeys(): array
    {
        return ['og', 'ogImage', 'ogVideo', 'ogAudio'];
    }

    /**
     * Create Open Graph metadata from named values.
     */
    public static function make(
        OgType|string|null $type = null,
        ?string $title = null,
        ?string $description = null,
        ?string $url = null,
        ?string $image = null,
        ?string $video = null,
        ?string $audio = null,
        ?string $siteName = null,
        ?string $locale = null,
        ?string $determiner = null,
    ): self {
        $openGraph = new self;

        $openGraph->set([
            'type' => $type instanceof OgType ? $type->value : $type,
            'title' => $title,
            'description' => $description,
            'url' => $url,
            'site_name' => $siteName,
            'locale' => $locale,
            'determiner' => $determiner,
        ]);

        if (! is_null($image)) {
            $openGraph->image($image);
        }

        if (! is_null($video)) {
            $openGraph->video($video);
        }

        if (! is_null($audio)) {
            $openGraph->audio($audio);
        }

        return $openGraph;
    }

    /**
     * Build Open Graph metadata from a route attribute.
     */
    public static function fromRouteAttribute(string $key, mixed $value): ?self
    {
        return match ($key) {
            'og' => is_array($value) ? self::fromAttributes($value) : null,
            'ogImage' => self::fromImageAttributes($value),
            'ogVideo' => self::fromVideoAttributes($value),
            'ogAudio' => self::fromAudioAttributes($value),
            default => null,
        };
    }

    /**
     * Create Open Graph metadata from an attribute map.
     *
     * @param array<mixed, mixed> $values
     */
    public static function fromAttributes(array $values): self
    {
        return self::make(
            type: self::ogType($values['type'] ?? null),
            title: self::string($values['title'] ?? null),
            description: self::string($values['description'] ?? null),
            url: self::string($values['url'] ?? null),
            image: self::string($values['image'] ?? null),
            video: self::string($values['video'] ?? null),
            audio: self::string($values['audio'] ?? null),
            siteName: self::string($values['siteName'] ?? null),
            locale: self::string($values['locale'] ?? null),
            determiner: self::string($values['determiner'] ?? null),
        );
    }

    /**
     * Build Open Graph images from route values.
     */
    public static function fromImageAttributes(mixed $images): self
    {
        $openGraph = new self;

        foreach (self::items($images) as $image) {
            if (is_string($image)) {
                $openGraph->image($image);
            }

            if (is_array($image) && is_string($image['url'] ?? null)) {
                $openGraph->image(
                    $image['url'],
                    alt: self::string($image['alt'] ?? null),
                    width: self::int($image['width'] ?? null),
                    height: self::int($image['height'] ?? null),
                    type: self::imageType($image['type'] ?? null),
                    secureUrl: self::string($image['secureUrl'] ?? null),
                );
            }
        }

        return $openGraph;
    }

    /**
     * Build Open Graph videos from route values.
     */
    public static function fromVideoAttributes(mixed $videos): self
    {
        $openGraph = new self;

        foreach (self::items($videos) as $video) {
            if (is_string($video)) {
                $openGraph->video($video);
            }

            if (is_array($video) && is_string($video['url'] ?? null)) {
                $openGraph->video(
                    $video['url'],
                    alt: self::string($video['alt'] ?? null),
                    width: self::int($video['width'] ?? null),
                    height: self::int($video['height'] ?? null),
                    type: self::string($video['type'] ?? null),
                    secureUrl: self::string($video['secureUrl'] ?? null),
                );
            }
        }

        return $openGraph;
    }

    /**
     * Build Open Graph audio from route values.
     */
    public static function fromAudioAttributes(mixed $audios): self
    {
        $openGraph = new self;

        foreach (self::items($audios) as $audio) {
            if (is_string($audio)) {
                $openGraph->audio($audio);
            }

            if (is_array($audio) && is_string($audio['url'] ?? null)) {
                $openGraph->audio(
                    $audio['url'],
                    type: self::string($audio['type'] ?? null),
                    secureUrl: self::string($audio['secureUrl'] ?? null),
                );
            }
        }

        return $openGraph;
    }

    /**
     * Add or replace an image by URL.
     */
    public function image(
        string $url,
        ?string $alt = null,
        ?int $width = null,
        ?int $height = null,
        ImageType|string|null $type = null,
        ?string $secureUrl = null,
    ): static {
        $this->images[$url] = self::media($url, $alt, $width, $height, self::imageType($type), $secureUrl);

        return $this;
    }

    /**
     * Add or replace a video by URL.
     */
    public function video(
        string $url,
        ?string $alt = null,
        ?int $width = null,
        ?int $height = null,
        ?string $type = null,
        ?string $secureUrl = null,
    ): static {
        $this->videos[$url] = self::media($url, $alt, $width, $height, $type, $secureUrl);

        return $this;
    }

    /**
     * Add or replace audio by URL.
     */
    public function audio(string $url, ?string $type = null, ?string $secureUrl = null): static
    {
        $this->audios[$url] = self::media($url, type: $type, secureUrl: $secureUrl);

        return $this;
    }

    /**
     * Overlay properties and media onto the lower layer.
     */
    public function overlayOn(?TagBuilder $base): static
    {
        if (! $base instanceof self) {
            return $this;
        }

        $overlaid = new static(
            array_replace($base->properties, $this->properties),
            $this->overlayMedia($base->images, $this->images, $base->imagesAreDefaults),
            $this->overlayMedia($base->videos, $this->videos, $base->videosAreDefaults),
            $this->overlayMedia($base->audios, $this->audios, $base->audiosAreDefaults),
        );

        // A page image must not turn inherited default video or audio into page media.
        $overlaid->imagesAreDefaults = $this->images === [] ? $base->imagesAreDefaults : $this->imagesAreDefaults;
        $overlaid->videosAreDefaults = $this->videos === [] ? $base->videosAreDefaults : $this->videosAreDefaults;
        $overlaid->audiosAreDefaults = $this->audios === [] ? $base->audiosAreDefaults : $this->audiosAreDefaults;

        return $overlaid;
    }

    /**
     * Mark all media as fallback values for higher layers.
     */
    public function asDefaults(): static
    {
        $defaults = clone $this;
        $defaults->imagesAreDefaults = true;
        $defaults->videosAreDefaults = true;
        $defaults->audiosAreDefaults = true;

        return $defaults;
    }

    /**
     * Media inherited from the defaults layer is a fallback, so media defined
     * by a higher layer replaces it rather than merging with it.
     *
     * @param array<string, MediaAttributes> $base
     * @param array<string, MediaAttributes> $overlay
     * @return array<string, MediaAttributes>
     */
    protected function overlayMedia(array $base, array $overlay, bool $baseIsDefaults): array
    {
        if ($overlay === []) {
            return $base;
        }

        return $baseIsDefaults ? $overlay : array_replace($base, $overlay);
    }

    /**
     * Determine whether any media is defined.
     */
    protected function hasMedia(): bool
    {
        return $this->images !== [] || $this->videos !== [] || $this->audios !== [];
    }

    /**
     * Determine whether no properties or media are defined.
     */
    public function isEmpty(): bool
    {
        return $this->properties === [] && ! $this->hasMedia();
    }

    /**
     * Get the Open Graph properties, falling back to the page title and description.
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
     * Get the image definitions keyed by URL.
     *
     * @return array<string, MediaAttributes>
     */
    public function images(): array
    {
        return $this->images;
    }

    /**
     * Get the resolved Open Graph metadata for the head array.
     *
     * @return array<string, mixed>
     */
    public function toHeadArray(ResolvedHead $head): array
    {
        return $this->headArray($head->title(), $head->description());
    }

    /**
     * Render Open Graph properties and grouped media tags.
     *
     * @return array<int, string>
     */
    public function toTags(ResolvedHead $head, TagRenderer $tags): array
    {
        $properties = $this->render($head->title(), $head->description());
        $propertyTags = array_map(
            fn (string $property, string $content): string => $tags->meta('property', 'og:' . $property, $content, 'og:' . $property),
            array_keys($properties),
            $properties,
        );

        return [
            ...$propertyTags,
            ...$this->mediaTags($tags, 'image', $this->images),
            ...$this->mediaTags($tags, 'video', $this->videos),
            ...$this->mediaTags($tags, 'audio', $this->audios),
        ];
    }

    /**
     * Get nonempty Open Graph properties and media lists.
     *
     * @return array<string, mixed>
     */
    protected function headArray(?string $title = null, ?string $description = null): array
    {
        return array_filter([
            ...$this->render($title, $description),
            'images' => array_values($this->images),
            'videos' => array_values($this->videos),
            'audios' => array_values($this->audios),
        ], fn (string|array $value): bool => $value !== '' && $value !== []);
    }

    /**
     * Set each specified Open Graph property.
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
     * Normalize a media definition, omitting unspecified attributes.
     *
     * @return MediaAttributes
     */
    protected static function media(
        string $url,
        ?string $alt = null,
        ?int $width = null,
        ?int $height = null,
        ?string $type = null,
        ?string $secureUrl = null,
    ): array {
        return array_filter([
            'url' => $url,
            'alt' => $alt,
            'width' => $width,
            'height' => $height,
            'type' => $type,
            'secureUrl' => $secureUrl,
        ], fn (int|string|null $value): bool => ! is_null($value));
    }

    /**
     * Resolve an Open Graph type value.
     */
    protected static function ogType(mixed $value): OgType|string|null
    {
        return $value instanceof OgType || is_string($value) ? $value : null;
    }

    /**
     * Resolve an image MIME type.
     */
    protected static function imageType(mixed $value): ?string
    {
        return $value instanceof ImageType ? $value->value : self::string($value);
    }

    /**
     * Render each media item with its associated attributes.
     *
     * @param array<string, MediaAttributes> $media
     * @return array<int, string>
     */
    protected function mediaTags(TagRenderer $tags, string $property, array $media): array
    {
        $attributes = [
            'secureUrl' => 'secure_url',
            'type' => 'type',
            'width' => 'width',
            'height' => 'height',
            'alt' => 'alt',
        ];

        $rendered = [];

        foreach ($media as $item) {
            $mediaKey = 'og:' . $property . ':' . $tags->stableKey('item', $item['url']);

            $rendered[] = $tags->meta('property', 'og:' . $property, $item['url'], $mediaKey);

            foreach ($attributes as $attribute => $name) {
                if (isset($item[$attribute])) {
                    $rendered[] = $tags->meta('property', 'og:' . $property . ':' . $name, $item[$attribute], $mediaKey . ':' . $name);
                }
            }
        }

        return $rendered;
    }
}
