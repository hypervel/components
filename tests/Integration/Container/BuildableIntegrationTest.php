<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Container;

use Hypervel\Container\Attributes\Config;
use Hypervel\Contracts\Container\SelfBuilding;
use Hypervel\Support\Facades\Validator;
use Hypervel\Testbench\TestCase;
use Hypervel\Validation\ValidationException;

class BuildableIntegrationTest extends TestCase
{
    public function testBuildMethodCanResolveItselfViaContainer(): void
    {
        config([
            'aim' => [
                'api_key' => 'api-key',
                'user_name' => 'cosmastech',
                'away_message' => [
                    'duration' => 500,
                    'body' => 'sad emo lyrics',
                ],
            ],
        ]);

        $config = $this->app->make(AolInstantMessengerConfig::class);

        $this->assertEquals(500, $config->awayMessageDuration);
        $this->assertSame('sad emo lyrics', $config->awayMessage);
        $this->assertSame('api-key', $config->apiKey);
        $this->assertSame('cosmastech', $config->userName);

        config(['aim.away_message.duration' => 5]);

        for ($attempt = 1; $attempt <= 2; ++$attempt) {
            try {
                $this->app->make(AolInstantMessengerConfig::class);

                $this->fail("Expected a validation exception on attempt {$attempt}.");
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('away_message.duration', $exception->errors());
                $this->assertStringContainsString('60', $exception->errors()['away_message.duration'][0]);
            }
        }

        config([
            'aim.away_message.duration' => 60,
            'aim.away_message.body' => 'back online',
        ]);

        $config = $this->app->make(AolInstantMessengerConfig::class);

        $this->assertEquals(60, $config->awayMessageDuration);
        $this->assertSame('back online', $config->awayMessage);
        $this->assertSame('api-key', $config->apiKey);
        $this->assertSame('cosmastech', $config->userName);
    }
}

class AolInstantMessengerConfig implements SelfBuilding
{
    /**
     * Create a new configuration instance.
     */
    public function __construct(
        #[Config('aim.api_key')]
        public string $apiKey,
        #[Config('aim.user_name')]
        public string $userName,
        #[Config('aim.away_message.duration')]
        public int $awayMessageDuration,
        #[Config('aim.away_message.body')]
        public string $awayMessage
    ) {
    }

    /**
     * Validate the configuration and build an instance.
     */
    public static function newInstance(): static
    {
        Validator::make(config('aim'), [
            'api_key' => 'string',
            'user_name' => 'string',
            'away_message' => 'array',
            'away_message.duration' => ['integer', 'min:60', 'max:3600'],
            'away_message.body' => ['string', 'min:1'],
        ])->validate();

        return app()->build(static::class);
    }
}
