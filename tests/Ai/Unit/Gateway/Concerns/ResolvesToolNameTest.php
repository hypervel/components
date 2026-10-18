<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Unit\Gateway\Concerns;

use Hypervel\Ai\Contracts\Tool;
use Hypervel\Ai\Gateway\Concerns\InvokesTools;
use Hypervel\Ai\Tools\Request;
use Hypervel\Ai\Tools\ToolNameResolver;
use Hypervel\Contracts\JsonSchema\JsonSchema;
use Hypervel\Tests\Ai\Fixtures\Tools\FixedNumberGenerator;
use Hypervel\Tests\Ai\Fixtures\Tools\NamedTool;
use Hypervel\Tests\TestCase;

class ProtectedNameTool implements Tool
{
    /**
     * Get the inaccessible tool name.
     */
    protected function name(): string
    {
        return 'should_not_be_used';
    }

    /**
     * Get the tool description.
     */
    public function description(): string
    {
        return 'A tool with an inaccessible name method.';
    }

    /**
     * Execute the tool.
     */
    public function handle(Request $request): string
    {
        return 'ok';
    }

    /**
     * Get the tool schema.
     */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}

function resolverHost(): object
{
    return new class {
        use InvokesTools;

        /**
         * Resolve the tool name.
         */
        public function callResolve(Tool $tool): string
        {
            return ToolNameResolver::resolve($tool);
        }

        /**
         * Find a tool by name.
         */
        public function callFind(string $name, array $tools): ?Tool
        {
            return $this->findTool($name, $tools);
        }
    };
}

class ResolvesToolNameTest extends TestCase
{
    public function testResolveToolNameFallsBackToClassBasenameWhenToolHasNoNameMethod(): void
    {
        $host = resolverHost();

        $this->assertSame('FixedNumberGenerator', $host->callResolve(new FixedNumberGenerator));
    }

    public function testResolveToolNamePrefersTheDeclaredNameMethodWhenPresent(): void
    {
        $host = resolverHost();

        $this->assertSame('aliased_tool', $host->callResolve(new NamedTool('aliased_tool')));
    }

    public function testResolveToolNameFallsBackWhenNameIsNotCallable(): void
    {
        $this->assertSame('ProtectedNameTool', ToolNameResolver::resolve(new ProtectedNameTool));
    }

    public function testFindToolMatchesAToolByItsDeclaredNameWhenMultipleShareAClass(): void
    {
        $host = resolverHost();

        $tools = [
            new NamedTool('aliased_tool'),
            new NamedTool('other_aliased_tool'),
            new FixedNumberGenerator,
        ];

        $this->assertSame($tools[1], $host->callFind('other_aliased_tool', $tools));
        $this->assertSame($tools[2], $host->callFind('FixedNumberGenerator', $tools));
        $this->assertNull($host->callFind('unknown', $tools));
    }

    public function testFindToolReturnsNullWhenNoToolMatches(): void
    {
        $host = resolverHost();

        $this->assertNull($host->callFind('missing', [new FixedNumberGenerator]));
    }
}
