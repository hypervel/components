<?php

declare(strict_types=1);

namespace Hypervel\Mcp\Server;

use Hypervel\Container\Container;
use Hypervel\Contracts\JsonSchema\JsonSchema;
use Hypervel\JsonSchema\JsonSchema as JsonSchemaFactory;
use Hypervel\Mcp\Server\Attributes\RendersApp;
use Hypervel\Mcp\Server\Concerns\HasAnnotations;
use Hypervel\Mcp\Server\Tools\Annotations\ToolAnnotation;
use Hypervel\Mcp\Server\Ui\Enums\Visibility;

abstract class Tool extends Primitive
{
    use HasAnnotations;

    /**
     * Define the input schema for the tool.
     *
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    /**
     * Define the output schema for this tool's results.
     *
     * @return array<string, mixed>
     */
    public function outputSchema(JsonSchema $schema): array
    {
        return [];
    }

    /**
     * Convert the tool to a method call.
     *
     * @return array<string, mixed>
     */
    public function toMethodCall(): array
    {
        return ['name' => $this->name()];
    }

    /**
     * Get the tool's array representation.
     *
     * @return array{
     *     name: string,
     *     title?: null|string,
     *     description?: null|string,
     *     inputSchema?: array<string, mixed>,
     *     outputSchema?: array<string, mixed>,
     *     annotations?: array<string, mixed>,
     *     icons?: list<array<string, mixed>>,
     *     _meta?: array<string, mixed>
     * }
     */
    public function toArray(): array
    {
        $annotations = [
            'title' => $this->title(),
            ...$this->annotations(),
        ];

        $schema = JsonSchemaFactory::object(
            $this->schema(...),
        )->toArray();

        $outputSchema = JsonSchemaFactory::object(
            $this->outputSchema(...),
        )->toArray();

        $schema['properties'] ??= (object) [];

        $result = [
            'name' => $this->name(),
            'title' => $this->title(),
            'description' => $this->description(),
            'inputSchema' => $schema,
            'annotations' => $annotations,
        ];

        if (isset($outputSchema['properties'])) {
            $result['outputSchema'] = $outputSchema;
        }

        $result = $this->mergeMeta($this->mergeIcons($result));
        $rendersApp = $this->resolveAttribute(RendersApp::class);

        if ($rendersApp !== null) {
            /** @var AppResource $appResource */
            $appResource = Container::getInstance()->make($rendersApp->resource);

            // Computed metadata belongs to this response, not the shared tool instance.
            $result['_meta']['ui'] = [
                'resourceUri' => $appResource->uri(),
                'visibility' => array_map(fn (Visibility $visibility): string => $visibility->value, $rendersApp->visibility),
            ];
        }

        // @phpstan-ignore return.type
        return $result;
    }

    /**
     * Get the annotation types allowed on a tool.
     *
     * @return array<int, class-string>
     */
    protected function allowedAnnotations(): array
    {
        return [
            ToolAnnotation::class,
        ];
    }
}
