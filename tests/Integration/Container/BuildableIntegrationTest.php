<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Container;

use Hypervel\Container\Attributes\Config;
use Hypervel\Contracts\Container\SelfBuilding;
use Hypervel\Support\Facades\Validator;
use Hypervel\Testbench\TestCase;
use Hypervel\Validation\ValidationException;

/**
 * @internal
 * @coversNothing
 */
class BuildableIntegrationTest extends TestCase
{
    public function testBuildMethodCanResolveItselfViaContainer()
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
        $this->assertEquals('sad emo lyrics', $config->awayMessage);
        $this->assertEquals('api-key', $config->apiKey);
        $this->assertEquals('cosmastech', $config->userName);

        config(['aim.away_message.duration' => 5]);

        try {
            $this->app->make(AolInstantMessengerConfig::class);
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('away_message.duration', $exception->errors());
            $this->assertStringContainsString('60', $exception->errors()['away_message.duration'][0]);
        }
    }
}

class AolInstantMessengerConfig implements SelfBuilding
{
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

    public static function newInstance(): static
    {
        Validator::make(config('aim'), [
            'api-key' => 'string',
            'user_name' => 'string',
            'away_message' => 'array',
            'away_message.duration' => ['integer', 'min:60', 'max:3600'],
            'away_message.body' => ['string', 'min:1'],
        ])->validate();

        return app()->build(static::class);
    }
}
