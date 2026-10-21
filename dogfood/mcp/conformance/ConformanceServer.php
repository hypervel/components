<?php

declare(strict_types=1);

namespace Hypervel\Dogfood\Mcp\Conformance;

use Hypervel\Dogfood\Mcp\Conformance\Prompts\PromptWithArguments;
use Hypervel\Dogfood\Mcp\Conformance\Prompts\PromptWithEmbeddedResource;
use Hypervel\Dogfood\Mcp\Conformance\Prompts\PromptWithImage;
use Hypervel\Dogfood\Mcp\Conformance\Prompts\SimplePrompt;
use Hypervel\Dogfood\Mcp\Conformance\Resources\StaticBinaryResource;
use Hypervel\Dogfood\Mcp\Conformance\Resources\StaticTextResource;
use Hypervel\Dogfood\Mcp\Conformance\Resources\TemplateResource;
use Hypervel\Dogfood\Mcp\Conformance\Resources\WatchedResource;
use Hypervel\Dogfood\Mcp\Conformance\Tools\AudioContentTool;
use Hypervel\Dogfood\Mcp\Conformance\Tools\EmbeddedResourceTool;
use Hypervel\Dogfood\Mcp\Conformance\Tools\ErrorHandlingTool;
use Hypervel\Dogfood\Mcp\Conformance\Tools\ImageContentTool;
use Hypervel\Dogfood\Mcp\Conformance\Tools\MultipleContentTypesTool;
use Hypervel\Dogfood\Mcp\Conformance\Tools\ProgressTool;
use Hypervel\Dogfood\Mcp\Conformance\Tools\SimpleTextTool;
use Hypervel\Mcp\Server;

class ConformanceServer extends Server
{
    protected string $name = 'mcp-conformance-test-server';

    protected string $version = '1.0.0';

    protected array $supportedProtocolVersion = [
        '2026-07-28',
        '2025-11-25',
        '2025-06-18',
    ];

    public array $tools = [
        SimpleTextTool::class,
        ImageContentTool::class,
        AudioContentTool::class,
        EmbeddedResourceTool::class,
        MultipleContentTypesTool::class,
        ErrorHandlingTool::class,
        ProgressTool::class,
    ];

    public array $resources = [
        StaticTextResource::class,
        StaticBinaryResource::class,
        TemplateResource::class,
        WatchedResource::class,
    ];

    public array $prompts = [
        SimplePrompt::class,
        PromptWithArguments::class,
        PromptWithEmbeddedResource::class,
        PromptWithImage::class,
    ];

    /**
     * Enable completion requests for the prompt fixtures.
     */
    protected function boot(): void
    {
        $this->addCapability(self::CAPABILITY_COMPLETIONS);
    }
}
