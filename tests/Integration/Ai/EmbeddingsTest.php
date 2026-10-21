<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Ai;

use Hypervel\Ai\Embeddings;
use Hypervel\Ai\Events\EmbeddingsGenerated;
use Hypervel\Ai\Events\GeneratingEmbeddings;
use Hypervel\Ai\Providers\Provider;
use Hypervel\Ai\Responses\EmbeddingsResponse;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Database\Schema\Blueprint;
use Hypervel\Support\Facades\Artisan;
use Hypervel\Support\Facades\DB;
use Hypervel\Support\Facades\Event;
use Hypervel\Support\Facades\Schema;
use Hypervel\Testbench\Attributes\DefineEnvironment;
use Hypervel\Tests\Ai\TestCase;
use Hypervel\Tests\Integration\Ai\Fixtures\Providers;
use PHPUnit\Framework\Attributes\DataProviderExternal;

use function Hypervel\Tests\Ai\Fixtures\requiresApiKey;

require_once __DIR__ . '/../../Ai/Fixtures/helpers.php';

class EmbeddingsTest extends TestCase
{
    #[DataProviderExternal(Providers::class, 'embeddings')]
    public function testEmbeddingsCanBeGenerated(string $provider, string $apiKey, int $dimensions): void
    {
        requiresApiKey($apiKey);

        Event::fake();

        $response = Embeddings::for(['I love to watch Star Trek.'])->generate(provider: $provider);

        $this->assertInstanceOf(EmbeddingsResponse::class, $response);
        $this->assertCount($dimensions, $response->embeddings[0]);
        $this->assertSame($provider, $response->meta->provider);

        Event::assertDispatched(GeneratingEmbeddings::class, fn (GeneratingEmbeddings $event): bool => $event->prompt->timeout === 30);
        Event::assertDispatched(EmbeddingsGenerated::class, fn (EmbeddingsGenerated $event): bool => $event->prompt->timeout === 30);
    }

    #[DataProviderExternal(Providers::class, 'embeddings')]
    public function testEmbeddingsCanBeGeneratedWithCustomDimensions(string $provider, string $apiKey, int $dimensions): void
    {
        requiresApiKey($apiKey);

        $response = Embeddings::for(['test text'])
            ->dimensions(256)
            ->generate(provider: $provider);

        $this->assertInstanceOf(EmbeddingsResponse::class, $response);
        $this->assertCount(256, $response->embeddings[0]);
    }

    #[DefineEnvironment('defineQueueEnvironment')]
    #[DataProviderExternal(Providers::class, 'embeddings')]
    public function testQueuedEmbeddingsWithClosureProviderOptionsRunEndToEndThroughARealQueueDriver(string $provider, string $apiKey, int $dimensions): void
    {
        requiresApiKey($apiKey);

        Schema::create('jobs', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('queue')->index();
            $table->longText('payload');
            $table->unsignedTinyInteger('attempts');
            $table->unsignedInteger('reserved_at')->nullable();
            $table->unsignedInteger('available_at');
            $table->unsignedInteger('created_at');
        });

        Event::fake([EmbeddingsGenerated::class]);

        Embeddings::for(['I love to watch Star Trek.'])
            ->withProviderOptions(fn (Provider $resolved): array => match ($resolved->driver()) {
                'voyageai' => ['input_type' => 'query'],
                default => [],
            })
            ->queue(provider: $provider);

        $this->assertSame(1, DB::table('jobs')->count());

        Artisan::call('queue:work', ['--once' => true, '--stop-when-empty' => true]);

        $this->assertSame(0, DB::table('jobs')->count());

        Event::assertDispatched(
            EmbeddingsGenerated::class,
            fn (EmbeddingsGenerated $event): bool => $event->response->meta->provider === $provider
                && count($event->response->embeddings[0]) === $dimensions,
        );
    }

    /**
     * Use an isolated in-memory database for the queue round trip.
     */
    protected function defineQueueEnvironment(Application $app): void
    {
        $app->make('config')->set([
            'database.default' => 'testing',
            'queue.default' => 'database',
            'queue.connections.database' => [
                'driver' => 'database',
                'connection' => null,
                'table' => 'jobs',
                'queue' => 'default',
                'retry_after' => 90,
                'after_commit' => false,
            ],
        ]);
    }
}
