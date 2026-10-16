<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Feature\Providers\AzureOpenAi;

use Hypervel\Ai\Enums\Lab;
use Hypervel\Ai\Files\Document;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Http\Client\Request;
use Hypervel\Support\Facades\Http;
use Hypervel\Tests\Ai\TestCase;

use function Hypervel\Tests\Ai\Fixtures\multipartField;
use function Hypervel\Tests\Ai\Fixtures\sentRequest;

require_once __DIR__ . '/../../../Fixtures/helpers.php';

class FileGatewayTest extends TestCase
{
    /**
     * Configure the Azure OpenAI endpoint.
     */
    protected function defineEnvironment(Application $app): void
    {
        $config = $app->make('config');
        $config->set('ai.providers.azure', [
            ...$config->get('ai.providers.azure'),
            'key' => 'test-key',
            'url' => 'https://test-resource.openai.azure.com',
        ]);
    }

    public function testPutFileUploadsToTheV1EndpointWithTheAssistantsPurpose(): void
    {
        Http::fake([
            'test-resource.openai.azure.com/*' => Http::response(['id' => 'file-uploaded123']),
        ]);

        $response = Document::fromString('Hello, World!', 'text/plain')->as('hello.txt')->put(
            provider: 'azure',
        );

        $this->assertSame('file-uploaded123', $response->id);

        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && $request->url() === 'https://test-resource.openai.azure.com/openai/v1/files'
            && str_contains($request->header('Content-Type')[0] ?? '', 'multipart/form-data')
            && collect($request->data())->contains(fn (array $field): bool => ($field['name'] ?? null) === 'purpose' && ($field['contents'] ?? null) === 'assistants')
            && $request->hasHeader('api-key', 'test-key'));
    }

    public function testProviderOptionsAreResolvedWithTheAzureKeyNotOpenAi(): void
    {
        Http::fake([
            'test-resource.openai.azure.com/*' => Http::response(['id' => 'file-uploaded123']),
        ]);

        Document::fromString('Hello, World!', 'text/plain')->as('hello.txt')
            ->withProviderOptions(fn (Lab $provider): array => match ($provider) {
                Lab::Azure => ['purpose' => 'batch'],
                Lab::OpenAI => ['purpose' => 'vision'],
                default => [],
            })
            ->put(provider: 'azure');

        $this->assertSame('batch', multipartField(sentRequest(), 'purpose'));
    }
}
