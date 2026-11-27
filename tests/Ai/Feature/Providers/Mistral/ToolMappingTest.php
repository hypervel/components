<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Feature\Providers\Mistral;

use Hypervel\Contracts\Foundation\Application;
use Hypervel\Http\Client\Request;
use Hypervel\Support\Facades\Http;
use Hypervel\Tests\Ai\Fixtures\MistralHelpers;
use Hypervel\Tests\Ai\Fixtures\Tools\FixedNumberGenerator;
use Hypervel\Tests\Ai\Fixtures\Tools\NamedTool;
use Hypervel\Tests\Ai\Fixtures\Tools\NonStrictTool;
use Hypervel\Tests\Ai\Fixtures\Tools\RandomNumberGenerator;
use Hypervel\Tests\Ai\TestCase;

use function Hypervel\Ai\agent;

class ToolMappingTest extends TestCase
{
    use MistralHelpers;

    /**
     * Configure provider credentials.
     */
    protected function defineEnvironment(Application $app): void
    {
        $app->make('config')->set('ai.providers.mistral.key', 'test-key');
    }

    public function testToolWithParametersIncludesCorrectSchema(): void
    {
        Http::fake(['*' => $this->fakeTextResponse('42')]);

        agent(tools: [new RandomNumberGenerator])->prompt('Give me a random number', provider: 'mistral');

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
        Http::fake(['*' => $this->fakeTextResponse('72019')]);

        agent(tools: [new FixedNumberGenerator])->prompt('Give me a random number', provider: 'mistral');

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
        Http::fake(['*' => $this->fakeTextResponse('ok')]);

        agent(tools: [new NamedTool('my_custom_tool')])->prompt('Hi', provider: 'mistral');

        Http::assertSent(function (Request $request): bool {
            $body = json_decode($request->body(), true);
            $names = collect(data_get($body, 'tools'))->pluck('function.name')->all();

            return in_array('my_custom_tool', $names, true);
        });
    }

    public function testToolParametersAreNotWrappedInSchemaDefinition(): void
    {
        Http::fake(['*' => $this->fakeTextResponse('done')]);

        agent(tools: [new RandomNumberGenerator])->prompt('Give me a random number', provider: 'mistral');

        Http::assertSent(function (Request $request): bool {
            $body = json_decode($request->body(), true);
            $tool = collect(data_get($body, 'tools'))->firstWhere('type', 'function');
            $function = $tool['function'] ?? [];

            return ! array_key_exists('schema_definition', $function['parameters']['properties'] ?? [])
                && ! in_array('schema_definition', $function['parameters']['required'] ?? [], true);
        });
    }

    public function testToolWithStrictAttributeSendsStrictTrue(): void
    {
        Http::fake(['*' => $this->fakeTextResponse('42')]);

        agent(tools: [new RandomNumberGenerator])->prompt('Give me a random number', provider: 'mistral');

        Http::assertSent(function (Request $request): bool {
            $function = collect(data_get(json_decode($request->body(), true), 'tools'))
                ->firstWhere('function.name', 'RandomNumberGenerator')['function'];

            return $function === [
                'name' => 'RandomNumberGenerator',
                'description' => 'This tool can be used to generate cryptographically secure random numbers.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'min' => ['description' => 'The minimum value of the random number', 'default' => 1, 'type' => 'integer'],
                        'max' => ['description' => 'The maximum value of the random number', 'default' => 100, 'type' => 'integer'],
                    ],
                    'required' => ['min', 'max'],
                    'additionalProperties' => false,
                ],
                'strict' => true,
            ];
        });
    }

    public function testToolWithoutStrictAttributeOmitsStrict(): void
    {
        Http::fake(['*' => $this->fakeTextResponse('ok')]);

        agent(tools: [new NonStrictTool])->prompt('Hi', provider: 'mistral');

        Http::assertSent(function (Request $request): bool {
            $function = collect(data_get(json_decode($request->body(), true), 'tools'))
                ->firstWhere('function.name', 'NonStrictTool')['function'];

            return ! array_key_exists('strict', $function);
        });
    }
}
