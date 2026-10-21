<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Feature;

use Hypervel\Ai\Image;
use Hypervel\Ai\Jobs\GenerateImage;
use Hypervel\Ai\Prompts\QueuedImagePrompt;
use Hypervel\Ai\Providers\Provider;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Http\Client\Request;
use Hypervel\Support\Facades\Http;
use Hypervel\Tests\Ai\TestCase;

class ImageProviderOptionsTest extends TestCase
{
    /**
     * Define the application's environment.
     */
    protected function defineEnvironment(Application $app): void
    {
        parent::defineEnvironment($app);
        $app->make('config')->set('ai.providers.openai.key', 'test-key');
    }

    public function testFlatProviderOptionsAreSentOnTheImageRequest(): void
    {
        Http::fake(['*' => Http::response(['data' => [['b64_json' => base64_encode('fake-image')]]])]);

        Image::of('A red apple')
            ->withProviderOptions(['background' => 'transparent'])
            ->generate(provider: 'openai', model: 'gpt-image-1');

        Http::assertSent(fn (Request $request): bool => json_decode($request->body(), true)['background'] === 'transparent');
    }

    public function testProviderOptionsMayNotOverrideTheCoreImageRequestPayload(): void
    {
        Http::fake(['*' => Http::response(['data' => [['b64_json' => base64_encode('fake-image')]]])]);

        Image::of('A red apple')
            ->withProviderOptions(['model' => 'hijacked', 'prompt' => 'hijacked'])
            ->generate(provider: 'openai', model: 'gpt-image-1');

        Http::assertSent(function (Request $request): bool {
            $body = json_decode($request->body(), true);

            return $body['model'] === 'gpt-image-1' && $body['prompt'] === 'A red apple';
        });
    }

    public function testClosureProviderOptionsReceiveTheResolvedImageProvider(): void
    {
        Http::fake(['*' => Http::response(['data' => [['b64_json' => base64_encode('fake-image')]]])]);

        $seen = [];

        Image::of('A red apple')
            ->withProviderOptions(function (Provider $provider) use (&$seen): array {
                $seen[] = $provider->driver();

                return ['background' => 'transparent'];
            })
            ->generate(provider: 'openai', model: 'gpt-image-1');

        $this->assertSame(['openai'], $seen);

        Http::assertSent(fn (Request $request): bool => json_decode($request->body(), true)['background'] === 'transparent');
    }

    public function testFlatProviderOptionsAreRecordedOnTheQueuedImagePromptFake(): void
    {
        Image::fake();

        Image::of('A red apple')
            ->withProviderOptions(['background' => 'transparent'])
            ->queue(provider: 'openai');

        Image::assertQueued(
            fn (QueuedImagePrompt $prompt): bool => $prompt->providerOptions === ['background' => 'transparent'],
        );
    }

    public function testClosureProviderOptionsSurviveQueueSerializationRoundTrip(): void
    {
        Http::fake(['*' => Http::response(['data' => [['b64_json' => base64_encode('fake-image')]]])]);

        $job = new GenerateImage(
            Image::of('A red apple')->withProviderOptions(fn (Provider $provider): array => ['background' => 'transparent']),
            'openai',
            'gpt-image-1',
        );

        unserialize(serialize($job))->handle();

        Http::assertSent(fn (Request $request): bool => json_decode($request->body(), true)['background'] === 'transparent');
    }
}
