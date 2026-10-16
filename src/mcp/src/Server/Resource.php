<?php

declare(strict_types=1);

namespace Hypervel\Mcp\Server;

use Hypervel\Mcp\Server\Annotations\Annotation;
use Hypervel\Mcp\Server\Attributes\Cacheable;
use Hypervel\Mcp\Server\Attributes\MimeType;
use Hypervel\Mcp\Server\Attributes\Uri;
use Hypervel\Mcp\Server\Concerns\HasAnnotations;
use Hypervel\Mcp\Server\Contracts\HasUriTemplate;
use Hypervel\Support\Str;

abstract class Resource extends Primitive
{
    use HasAnnotations;

    protected string $uri = '';

    protected string $mimeType = '';

    protected string $defaultUriScheme = 'file';

    /**
     * Get the resource URI or URI template.
     */
    public function uri(): string
    {
        if ($this instanceof HasUriTemplate) {
            return (string) $this->uriTemplate();
        }

        $attribute = $this->resolveAttribute(Uri::class);

        return $attribute->value ?? ($this->uri !== '' ? $this->uri : $this->defaultUriScheme . '://resources/' . Str::kebab(class_basename($this)));
    }

    /**
     * Get the resource's cache settings.
     */
    public function cacheable(): ?Cacheable
    {
        return $this->resolveAttribute(Cacheable::class);
    }

    /**
     * Get the resource MIME type.
     */
    public function mimeType(): string
    {
        $attribute = $this->resolveAttribute(MimeType::class);

        return $attribute->value ?? ($this->mimeType !== '' ? $this->mimeType : 'text/plain');
    }

    /**
     * Convert the resource to a method call.
     *
     * @return array<string, mixed>
     */
    public function toMethodCall(): array
    {
        return ['uri' => $this->uri()];
    }

    /**
     * Convert the resource to an array.
     *
     * @return array{
     *     name: string,
     *     title: string,
     *     description: string,
     *     uri?: string,
     *     uriTemplate?: string,
     *     mimeType: string,
     *     _meta?: array<string, mixed>
     * }
     */
    public function toArray(): array
    {
        $annotations = $this->annotations();

        $data = [
            'name' => $this->name(),
            'title' => $this->title(),
            'description' => $this->description(),
            'mimeType' => $this->mimeType(),
        ];

        if ($annotations !== []) {
            $data['annotations'] = $annotations;
        }

        if ($this instanceof HasUriTemplate) {
            $data['uriTemplate'] = (string) $this->uriTemplate();
        } else {
            $data['uri'] = $this->uri();
        }

        // @phpstan-ignore return.type
        return $this->mergeMeta($this->mergeIcons($data));
    }

    /**
     * Get the annotation types allowed on a resource.
     *
     * @return array<int, class-string>
     */
    protected function allowedAnnotations(): array
    {
        return [
            Annotation::class,
        ];
    }
}
