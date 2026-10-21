<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Feature\Providers\Gemini;

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
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;

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

    #[DataProvider('escapedMetadataFilters')]
    public function testFileSearchMetadataFilterEscapesQuotesAndTrailingBackslashes(string $method, string $expected): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => $this->fakeTextResponse('ok'),
        ]);

        agent(tools: [new FileSearch(['fileSearchStores/store123'], where: function (FileSearchQuery $query) use ($method): void {
            if (in_array($method, ['whereIn', 'whereNotIn'], true)) {
                $query->{$method}('category', ['x" OR tenant="globex', 'x\\']);
            } else {
                $query->{$method}('category', 'x" OR tenant="globex')->{$method}('path', 'x\\');
            }
        })])->prompt('Question?', provider: 'gemini');

        $this->assertSame($expected, sentRequest()->data()['tools'][0]['metadata_filter']);
    }

    /**
     * Provide each filter method with its escaped Gemini filter expression.
     */
    public static function escapedMetadataFilters(): array
    {
        return [
            'where' => ['where', '(category="x\" OR tenant=\"globex") AND (path="x\\\")'],
            'whereNot' => ['whereNot', '(category!="x\" OR tenant=\"globex") AND (path!="x\\\")'],
            'whereIn' => ['whereIn', '(category="x\" OR tenant=\"globex" OR category="x\\\")'],
            'whereNotIn' => ['whereNotIn', '(category!="x\" OR tenant=\"globex" AND category!="x\\\")'],
        ];
    }

    #[DataProvider('invalidMetadataFilterKeys')]
    public function testFileSearchMetadataFilterRejectsInvalidKeysBeforeSendingARequest(string $key): void
    {
        Http::fake();

        try {
            agent(tools: [new FileSearch(['fileSearchStores/store123'], where: [$key => 'value'])])
                ->prompt('Question?', provider: 'gemini');
            $this->fail('The key must be rejected.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame("Invalid metadata filter key [{$key}].", $exception->getMessage());
        }

        Http::assertNothingSent();
    }

    /**
     * Provide metadata filter keys that Gemini's filter syntax cannot name.
     */
    public static function invalidMetadataFilterKeys(): array
    {
        return [
            ['tenant="acme" OR category'],
            ['tenant name'],
            ['tenant-name'],
            ['1tenant'],
            [''],
            ["tenant\n"],
            // A numeric array key reaches the provider as a string.
            ['2026'],
        ];
    }

    public function testFileSearchMetadataFilterGroupsOrdinaryConditionsAndPreservesNumericValues(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => $this->fakeTextResponse('ok'),
        ]);

        agent(tools: [new FileSearch(['fileSearchStores/store123'], where: fn (FileSearchQuery $query) => $query
            ->where('tenant', 'acme')
            ->whereIn('category', ['guide', 'reference'])
            ->whereNot('_status', 'draft')
            ->where('version.number', 2.5)
            ->whereIn('year', [2024, '2025'])
            ->whereNot('revision', 0))])->prompt('Question?', provider: 'gemini');

        $this->assertSame(
            '(tenant="acme") AND (category="guide" OR category="reference") AND (_status!="draft") AND (version.number=2.5) AND (year=2024 OR year=2025) AND (revision!=0)',
            sentRequest()->data()['tools'][0]['metadata_filter'],
        );
    }

    public function testFileSearchMetadataFilterSupportsWhereNotIn(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => $this->fakeTextResponse('ok'),
        ]);

        agent(tools: [new FileSearch(['fileSearchStores/store123'], where: fn (FileSearchQuery $query) => $query
            ->where('tenant', 'acme')
            ->whereNotIn('category', ['draft', 'internal'])
            ->whereNotIn('year', [2023, '2024']))])->prompt('Question?', provider: 'gemini');

        $this->assertSame(
            '(tenant="acme") AND (category!="draft" AND category!="internal") AND (year!=2023 AND year!=2024)',
            sentRequest()->data()['tools'][0]['metadata_filter'],
        );
    }

    public function testFileSearchMetadataFilterOmitsAnEmptyWhereNotIn(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => $this->fakeTextResponse('ok'),
        ]);

        agent(tools: [new FileSearch(['fileSearchStores/store123'], where: fn (FileSearchQuery $query) => $query
            ->whereNotIn('category', []))])->prompt('Question?', provider: 'gemini');

        $this->assertArrayNotHasKey('metadata_filter', sentRequest()->data()['tools'][0]);

        agent(tools: [new FileSearch(['fileSearchStores/store123'], where: fn (FileSearchQuery $query) => $query
            ->where('tenant', 'acme')
            ->whereNotIn('category', []))])->prompt('Question?', provider: 'gemini');

        $this->assertSame('(tenant="acme")', Http::recorded()[1][0]->data()['tools'][0]['metadata_filter']);
    }

    public function testFileSearchMetadataFilterRejectsAnEmptyWhereInBeforeSendingARequest(): void
    {
        Http::fake();

        try {
            agent(tools: [new FileSearch(['fileSearchStores/store123'], where: fn (FileSearchQuery $query) => $query
                ->where('tenant', 'acme')
                ->whereIn('category', []))])->prompt('Question?', provider: 'gemini');
            $this->fail('The empty list must be rejected.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('Gemini metadata filters cannot match [category] against an empty list of values.', $exception->getMessage());
        }

        Http::assertNothingSent();
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
