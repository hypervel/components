<?php

declare(strict_types=1);

namespace Hypervel\Sentry;

use FriendsOfHyperf\Sentry\Command\AboutCommand;
use FriendsOfHyperf\Sentry\Command\TestCommand;
use Hypervel\Sentry\Aspects\CoroutineAspect;
use Hypervel\Sentry\Aspects\GuzzleHttpClientAspect;
use Hypervel\Sentry\Factory\ClientBuilderFactory;
use Hypervel\Sentry\Factory\HubFactory;
use Hypervel\Sentry\HttpClient\HttpClientFactory;
use Hypervel\Sentry\Listeners\DbQueryListener;
use Sentry\ClientBuilder;
use Sentry\HttpClient\HttpClientInterface;
use Sentry\State\HubInterface;

class ConfigProvider
{
    public function __invoke(): array
    {
        return [
            'aspects' => [
                GuzzleHttpClientAspect::class,
                CoroutineAspect::class,
            ],
            'commands' => [
                AboutCommand::class,
                TestCommand::class,
            ],
            'dependencies' => [
                ClientBuilder::class => ClientBuilderFactory::class,
                HubInterface::class => HubFactory::class,
                HttpClientInterface::class => HttpClientFactory::class,
            ],
            'listeners' => [
                DbQueryListener::class,
            ],
            'annotations' => [
                'scan' => [
                    'class_map' => [
                        \Sentry\SentrySdk::class => __DIR__ . '/class-map/SentrySdk.php',
                        \FriendsOfHyperf\Sentry\ConfigProvider::class => __DIR__ . '/ConfigProvider.php',
                    ],
                ],
            ],
            'publish' => [
                [
                    'id' => 'config',
                    'description' => 'The config file for sentry.',
                    'source' => __DIR__ . '/../publish/sentry.php',
                    'destination' => BASE_PATH . '/config/sentry.php',
                ],
            ],
        ];
    }
}
