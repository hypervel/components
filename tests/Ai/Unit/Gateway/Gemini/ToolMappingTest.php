<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Unit\Gateway\Gemini;

use Hypervel\Ai\Gateway\Gemini\Concerns\MapsTools;
use Hypervel\Ai\Providers\Provider;
use Hypervel\Ai\Providers\Tools\ToolSearch;
use Hypervel\Tests\Ai\Fixtures\Tools\NonStrictTool;
use Hypervel\Tests\TestCase;
use RuntimeException;

/**
 * Create a Gemini tool mapper.
 */
function geminiToolMapper(): object
{
    return new class {
        use MapsTools;

        /**
         * Map tools for the provider.
         */
        public function map(array $tools, Provider $provider): array
        {
            return $this->mapTools($tools, $provider);
        }
    };
}

/**
 * Create the mapping provider.
 */
function geminiToolMappingProvider(): Provider
{
    return new class extends Provider {
        /**
         * Create a provider without dependencies.
         */
        public function __construct()
        {
        }

        /**
         * Get the provider name.
         */
        public function name(): string
        {
            return 'gemini';
        }
    };
}

class ToolMappingTest extends TestCase
{
    public function testThrowsForAProviderToolGeminiCannotMapInsteadOfEmittingAnEmptyEntry(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('does not support the [ToolSearch] tool');

        geminiToolMapper()->map(
            [new ToolSearch(tools: [new NonStrictTool])],
            geminiToolMappingProvider(),
        );
    }
}
