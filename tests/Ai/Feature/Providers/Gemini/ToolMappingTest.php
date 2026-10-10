<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Feature\Providers\Gemini;

use Hypervel\Ai\Contracts\Gateway\Gateway;
use Hypervel\Ai\Providers\GeminiProvider;
use Hypervel\Ai\Providers\Tools\CodeExecution;
use Hypervel\Ai\Providers\Tools\FileSearch;
use Hypervel\Ai\Providers\Tools\FileSearchQuery;
use Hypervel\Ai\Providers\Tools\WebSearch;
use Hypervel\Support\Facades\Http;
use Hypervel\Tests\Ai\Fixtures\Agents\NamedToolAgent;
use Hypervel\Tests\Ai\Fixtures\Agents\NestedObjectToolAgent;
use Hypervel\Tests\Ai\Fixtures\Agents\NullableToolAgent;
use Hypervel\Tests\Ai\Fixtures\Agents\ToolUsingAgent;
use Hypervel\Tests\Ai\Fixtures\GeminiHelpers;
use Hypervel\Tests\Ai\Fixtures\Tools\FixedNumberGenerator;
use Hypervel\Tests\Ai\TestCase;

use function Hypervel\Ai\agent;
use function Hypervel\Tests\Ai\Fixtures\sentRequest;

require_once __DIR__ . '/../../../Fixtures/helpers.php';

class ToolMappingTest extends TestCase
{
    use GeminiHelpers;

    public function testEmptySchemaOmitsParametersKey(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => $this->fakeTextResponse('The number is 42'),
        ]);

        (new ToolUsingAgent(fixed: true))->prompt(
            'Generate a number',
            provider: 'gemini',
        );

        $tool = $this->geminiTool(sentRequest()->data(), 'FixedNumberGenerator');
        $this->assertNotNull($tool);
        $this->assertArrayNotHasKey('parameters', $tool);
    }

    public function testFunctionToolsAreDeclaredFlatWithAFunctionType(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => $this->fakeTextResponse('The number is 42'),
        ]);

        (new ToolUsingAgent(fixed: true))->prompt(
            'Generate a number',
            provider: 'gemini',
        );

        $tool = $this->geminiTool(sentRequest()->data(), 'FixedNumberGenerator');
        $this->assertSame('function', $tool['type']);
        $this->assertIsString($tool['description']);
        $this->assertArrayNotHasKey('function_declarations', sentRequest()->data()['tools'][0]);
    }

    public function testNestedObjectParametersRecursivelyExcludeAdditionalProperties(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => $this->fakeTextResponse('ok'),
        ]);

        (new NestedObjectToolAgent)->prompt('Test nested params', provider: 'gemini');

        $hasAdditionalProperties = function (mixed $node) use (&$hasAdditionalProperties): bool {
            if (! is_array($node)) {
                return false;
            }

            if (array_key_exists('additionalProperties', $node)) {
                return true;
            }

            foreach ($node as $value) {
                if ($hasAdditionalProperties($value)) {
                    return true;
                }
            }

            return false;
        };

        $parameters = sentRequest()->data()['tools'][0]['parameters'];

        // The nested object lives under the array's items and must survive the strip.
        $this->assertArrayHasKey('name', $parameters['properties']['items']['items']['properties']);
        $this->assertArrayHasKey('description', $parameters['properties']['items']['items']['properties']);
        $this->assertFalse($hasAdditionalProperties($parameters));
    }

    public function testNullableToolParametersUseOpenApiStyleNullableFormat(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => $this->fakeTextResponse('ok'),
        ]);

        (new NullableToolAgent)->prompt('Test nullable params', provider: 'gemini');

        $this->assertSame([
            'name' => ['type' => 'string'],
            'email' => ['type' => 'string', 'nullable' => true],
            'age' => ['type' => 'integer', 'nullable' => true],
        ], sentRequest()->data()['tools'][0]['parameters']['properties']);
    }

    public function testToolWithANameMethodEmitsTheDeclaredName(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => $this->fakeTextResponse('ok'),
        ]);

        (new NamedToolAgent('aliased_tool'))->prompt('Search', provider: 'gemini');

        $this->assertNotNull($this->geminiTool(sentRequest()->data(), 'aliased_tool'));
    }

    public function testToolWithoutANameMethodFallsBackToClassBasename(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => $this->fakeTextResponse('ok'),
        ]);

        (new ToolUsingAgent(fixed: true))->prompt('Generate', provider: 'gemini');

        $this->assertNotNull($this->geminiTool(sentRequest()->data(), 'FixedNumberGenerator'));
    }

    public function testProviderToolsAreSentWithoutAToolChoice(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => $this->fakeTextResponse('ok'),
        ]);

        agent(
            'Answer using the uploaded knowledge base.',
            tools: [new FileSearch(['fileSearchStores/store123'])],
        )->prompt('Question?', provider: 'gemini');

        $body = sentRequest()->data();
        $this->assertSame('file_search', $body['tools'][0]['type']);
        $this->assertSame(['fileSearchStores/store123'], $body['tools'][0]['file_search_store_names']);
        $this->assertArrayNotHasKey('tool_choice', $body['generation_config'] ?? []);
    }

    public function testMixedFunctionAndProviderToolsAreSentSideBySide(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => $this->fakeTextResponse('ok'),
        ]);

        agent(
            'Generate a number, optionally searching the web.',
            tools: [new FixedNumberGenerator, new WebSearch],
        )->prompt('Generate', provider: 'gemini');

        $body = sentRequest()->data();
        $this->assertSame(['function', 'google_search'], array_column($body['tools'], 'type'));
        $this->assertArrayNotHasKey('tool_choice', $body['generation_config'] ?? []);
    }

    public function testCodeExecutionToolSendsACodeExecutionDefinition(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => $this->fakeTextResponse('ok'),
        ]);

        agent(tools: [new CodeExecution])->prompt('Run some code', provider: 'gemini');

        $this->assertSame([['type' => 'code_execution']], sentRequest()->data()['tools']);
    }

    public function testMetadataFiltersEscapeStringLiteralsAndPreserveNumericValues(): void
    {
        $provider = new GeminiProvider(self::createStub(Gateway::class), [], $this->app->make('events'));
        $tool = new FileSearch(['store'], fn (FileSearchQuery $query) => $query
            ->where('author', 'A "quoted" author')
            ->whereNot('path', 'C:\notes')
            ->whereIn('category', ['a"b', 'c\d', 42, '2026'])
            ->where('enabled', true));

        $this->assertSame(
            'author="A \"quoted\" author" AND path!="C:\\\notes" AND (category="a\"b" OR category="c\\\d" OR category=42 OR category=2026) AND enabled="1"',
            $provider->fileSearchToolOptions($tool)['metadata_filter'],
        );
    }

    public function testMetadataFiltersSupportExclusionListsAndNumericShorthandKeys(): void
    {
        $provider = new GeminiProvider(self::createStub(Gateway::class), [], $this->app->make('events'));
        $tool = new FileSearch(['store'], fn (FileSearchQuery $query) => $query
            ->whereNotIn('category', ['a"b', 'c\d', 42]));

        $this->assertSame(
            '(category!="a\"b" AND category!="c\\\d" AND category!=42)',
            $provider->fileSearchToolOptions($tool)['metadata_filter'],
        );
        $this->assertSame(
            '2026="x"',
            $provider->fileSearchToolOptions(new FileSearch(['store'], ['2026' => 'x']))['metadata_filter'],
        );
    }

    /**
     * Find a tool in a Gemini request.
     */
    protected function geminiTool(array $body, string $name): ?array
    {
        foreach ($body['tools'] ?? [] as $tool) {
            if (($tool['name'] ?? null) === $name) {
                return $tool;
            }
        }

        return null;
    }
}
