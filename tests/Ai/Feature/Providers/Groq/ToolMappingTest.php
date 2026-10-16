<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Feature\Providers\Groq;

use Hypervel\Ai\Providers\Tools\CodeExecution;
use Hypervel\Ai\Providers\Tools\WebSearch;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Http\Client\Request;
use Hypervel\Support\Facades\Http;
use Hypervel\Tests\Ai\Fixtures\Tools\FixedNumberGenerator;
use Hypervel\Tests\Ai\Fixtures\Tools\NamedTool;
use Hypervel\Tests\Ai\Fixtures\Tools\RandomNumberGenerator;
use Hypervel\Tests\Ai\TestCase;

use function Hypervel\Ai\agent;
use function Hypervel\Tests\Ai\Fixtures\fakeGroqResponse;

require_once __DIR__ . '/../../../Fixtures/helpers.php';

class ToolMappingTest extends TestCase
{
    /**
     * Configure provider credentials.
     */
    protected function defineEnvironment(Application $app): void
    {
        $app->make('config')->set('ai.providers.groq.key', 'test-key');
    }

    public function testToolWithParametersIncludesCorrectSchema(): void
    {
        Http::fake([
            '*' => fakeGroqResponse('42'),
        ]);

        agent(tools: [new RandomNumberGenerator])->prompt('Give me a random number', provider: 'groq');

        Http::assertSent(function (Request $request): bool {
            $body = json_decode($request->body(), true);
            $tool = collect(data_get($body, 'tools'))->firstWhere('type', 'function');
            $function = $tool['function'] ?? [];

            return $function['parameters']['type'] === 'object'
                && array_key_exists('min', $function['parameters']['properties'])
                && array_key_exists('max', $function['parameters']['properties'])
                && in_array('min', $function['parameters']['required'], true)
                && in_array('max', $function['parameters']['required'], true)
                && $function['parameters']['additionalProperties'] === false;
        });
    }

    public function testToolWithEmptySchemaIncludesParameters(): void
    {
        Http::fake([
            '*' => fakeGroqResponse('72019'),
        ]);

        agent(tools: [new FixedNumberGenerator])->prompt('Give me a random number', provider: 'groq');

        Http::assertSent(function (Request $request): bool {
            $body = json_decode($request->body(), true);
            $tool = collect(data_get($body, 'tools'))->firstWhere('type', 'function');
            $function = $tool['function'] ?? [];

            return array_key_exists('parameters', $function)
                && $function['parameters']['type'] === 'object'
                && $function['parameters']['properties'] === []
                && $function['parameters']['required'] === []
                && $function['parameters']['additionalProperties'] === false;
        });
    }

    public function testToolWithANameMethodEmitsTheDeclaredName(): void
    {
        Http::fake([
            '*' => fakeGroqResponse('ok'),
        ]);

        agent(tools: [new NamedTool('my_custom_tool')])->prompt('Hi', provider: 'groq');

        Http::assertSent(function (Request $request): bool {
            $body = json_decode($request->body(), true);
            $names = collect(data_get($body, 'tools'))->pluck('function.name')->all();

            return in_array('my_custom_tool', $names, true);
        });
    }

    public function testToolParametersAreNotWrappedInSchemaDefinition(): void
    {
        Http::fake([
            '*' => fakeGroqResponse('done'),
        ]);

        agent(tools: [new RandomNumberGenerator])->prompt('Give me a random number', provider: 'groq');

        Http::assertSent(function (Request $request): bool {
            $body = json_decode($request->body(), true);
            $tool = collect(data_get($body, 'tools'))->firstWhere('type', 'function');
            $function = $tool['function'] ?? [];

            return ! array_key_exists('schema_definition', $function['parameters']['properties'] ?? [])
                && ! in_array('schema_definition', $function['parameters']['required'] ?? [], true);
        });
    }

    public function testWebSearchToolIsSentAsBrowserSearchType(): void
    {
        Http::fake(['*' => fakeGroqResponse('done')]);

        agent(tools: [new WebSearch])->prompt('Search the web', provider: 'groq');

        Http::assertSent(function (Request $request): bool {
            return data_get(json_decode($request->body(), true), 'tools') === [['type' => 'browser_search']];
        });
    }

    public function testCodeExecutionToolIsSentAsCodeInterpreterType(): void
    {
        Http::fake(['*' => fakeGroqResponse('done')]);

        agent(tools: [new CodeExecution])->prompt('Run some code', provider: 'groq');

        Http::assertSent(function (Request $request): bool {
            return data_get(json_decode($request->body(), true), 'tools') === [['type' => 'code_interpreter']];
        });
    }
}
