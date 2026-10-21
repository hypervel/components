<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Feature\Providers\Ollama;

use Hypervel\Contracts\Foundation\Application;
use Hypervel\Http\Client\Request;
use Hypervel\Support\Facades\Http;
use Hypervel\Tests\Ai\Fixtures\OllamaHelpers;
use Hypervel\Tests\Ai\Fixtures\Tools\FixedNumberGenerator;
use Hypervel\Tests\Ai\Fixtures\Tools\NamedTool;
use Hypervel\Tests\Ai\Fixtures\Tools\RandomNumberGenerator;
use Hypervel\Tests\Ai\TestCase;

use function Hypervel\Ai\agent;

class ToolMappingTest extends TestCase
{
    use OllamaHelpers;

    /**
     * Configure provider credentials.
     */
    protected function defineEnvironment(Application $app): void
    {
        $app->make('config')->set('ai.providers.ollama.key', '');
    }

    public function testToolWithParametersIncludesCorrectSchema(): void
    {
        Http::fake([
            '*' => $this->fakeTextResponse('42'),
        ]);

        agent(tools: [new RandomNumberGenerator])->prompt('Give me a random number', provider: 'ollama');

        Http::assertSent(function (Request $request): bool {
            $body = json_decode($request->body(), true);
            $tool = collect(data_get($body, 'tools'))->firstWhere('type', 'function');
            $function = $tool['function'] ?? [];

            return $function['parameters']['type'] === 'object'
                && array_key_exists('min', $function['parameters']['properties'])
                && array_key_exists('max', $function['parameters']['properties'])
                && in_array('min', $function['parameters']['required'], true)
                && in_array('max', $function['parameters']['required'], true);
        });
    }

    public function testToolWithEmptySchemaIncludesParameters(): void
    {
        Http::fake([
            '*' => $this->fakeTextResponse('72019'),
        ]);

        agent(tools: [new FixedNumberGenerator])->prompt('Give me a number', provider: 'ollama');

        Http::assertSent(function (Request $request): bool {
            $body = json_decode($request->body(), true);
            $tool = collect(data_get($body, 'tools'))->firstWhere('type', 'function');
            $function = $tool['function'] ?? [];

            return array_key_exists('parameters', $function)
                && $function['parameters']['type'] === 'object';
        });
    }

    public function testToolWithANameMethodEmitsTheDeclaredName(): void
    {
        Http::fake(['*' => $this->fakeTextResponse('ok')]);

        agent(tools: [new NamedTool('my_custom_tool')])->prompt('Hi', provider: 'ollama');

        Http::assertSent(function (Request $request): bool {
            $body = json_decode($request->body(), true);
            $names = collect(data_get($body, 'tools'))->pluck('function.name')->all();

            return in_array('my_custom_tool', $names, true);
        });
    }

    public function testToolDefinitionIncludesNameAndDescription(): void
    {
        Http::fake([
            '*' => $this->fakeTextResponse('done'),
        ]);

        agent(tools: [new RandomNumberGenerator])->prompt('Give me a number', provider: 'ollama');

        Http::assertSent(function (Request $request): bool {
            $body = json_decode($request->body(), true);
            $tool = collect(data_get($body, 'tools'))->firstWhere('type', 'function');
            $function = $tool['function'] ?? [];

            return ! empty($function['name'])
                && ! empty($function['description']);
        });
    }
}
