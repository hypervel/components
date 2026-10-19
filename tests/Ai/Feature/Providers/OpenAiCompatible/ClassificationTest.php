<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Feature\Providers\OpenAiCompatible;

use Hypervel\Ai\Classification;
use Hypervel\Ai\Classification\Boolean;
use Hypervel\Ai\Enums\Lab;
use Hypervel\Ai\Files\Image;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Http\Client\Request;
use Hypervel\Support\Facades\Http;
use Hypervel\Tests\Ai\TestCase;
use InvalidArgumentException;

class ClassificationTest extends TestCase
{
    /**
     * Configure the OpenAI-compatible provider.
     */
    protected function defineEnvironment(Application $app): void
    {
        $app->make('config')->set('ai.providers.openai-compatible', [
            'driver' => 'openai-compatible',
            'url' => 'http://localhost:1234/v1/',
            'key' => 'test-key',
            'models' => [
                'classification' => ['default' => 'local-classifier'],
            ],
        ]);
    }

    public function testClassificationPostsToTheDecisionsEndpointOfTheConfiguredUrl(): void
    {
        Http::fake(['*' => Http::response([
            'answers' => [['type' => 'predicate', 'name' => 'is_urgent', 'probability' => 0.92]],
            'usage' => ['input_tokens' => 12, 'output_tokens' => 0],
        ])]);

        $response = Classification::of('I was charged twice.')
            ->question('is_urgent', new Boolean('Is this urgent?'))
            ->classify(provider: 'openai-compatible');

        Http::assertSent(fn (Request $request): bool => $request->url() === 'http://localhost:1234/v1/decisions'
            && $request->hasHeader('Authorization', 'Bearer test-key')
            && json_decode($request->body(), true) === [
                'model' => 'local-classifier',
                'input' => 'I was charged twice.',
                'questions' => [
                    ['type' => 'predicate', 'name' => 'is_urgent', 'instructions' => 'Is this urgent?'],
                ],
            ]);

        $this->assertSame(0.92, $response['is_urgent']->probability);
        $this->assertSame(12, $response->usage->inputTokens);
        $this->assertSame('local-classifier', $response->meta->model);
    }

    public function testClassificationOmitsTheAuthorizationHeaderWhenNoKeyIsConfigured(): void
    {
        config(['ai.providers.openai-compatible.key' => null]);

        Http::fake(['*' => Http::response(['answers' => []])]);

        Classification::of('text')
            ->question('is_urgent', new Boolean('Urgent?'))
            ->classify(provider: 'openai-compatible');

        Http::assertSent(fn (Request $request): bool => ! $request->hasHeader('Authorization'));
    }

    public function testClassificationSendsImageAttachmentsInline(): void
    {
        Http::fake(['*' => Http::response(['answers' => []])]);

        Classification::of('Inspect this.', [Image::fromBase64(base64_encode('bytes'), 'image/png')])
            ->question('damaged', new Boolean('Damaged?'))
            ->classify(provider: 'openai-compatible');

        Http::assertSent(fn (Request $request): bool => json_decode($request->body(), true)['input'][0]['content'][1] === [
            'type' => 'input_image',
            'image_url' => 'data:image/png;base64,' . base64_encode('bytes'),
        ]);
    }

    public function testClassificationResolvesImageProviderOptionsForTheOpenAiCompatibleProvider(): void
    {
        Http::fake(['*' => Http::response(['answers' => []])]);

        $image = Image::fromBase64(base64_encode('bytes'), 'image/png')->withProviderOptions(
            fn (Lab|string $provider): array => $provider === Lab::OpenAICompatible ? ['detail' => 'low'] : [],
        );

        Classification::of('Inspect this.', [$image])
            ->question('damaged', new Boolean('Damaged?'))
            ->classify(provider: 'openai-compatible');

        Http::assertSent(fn (Request $request): bool => json_decode($request->body(), true)['input'][0]['content'][1] === [
            'detail' => 'low',
            'type' => 'input_image',
            'image_url' => 'data:image/png;base64,' . base64_encode('bytes'),
        ]);
    }

    public function testClassificationThrowsWhenNoDefaultModelIsConfiguredAndNoneIsPassed(): void
    {
        config(['ai.providers.openai-compatible.models' => []]);

        Http::fake();

        try {
            Classification::of('text')
                ->question('is_urgent', new Boolean('Urgent?'))
                ->classify(provider: 'openai-compatible');

            $this->fail('Expected a missing classification model exception.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('requires a default classification model', $exception->getMessage());
        }

        Http::assertNothingSent();
    }
}
