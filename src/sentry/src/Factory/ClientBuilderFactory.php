<?php

declare(strict_types=1);

namespace Hypervel\Sentry\Factory;

use Hyperf\Contract\ConfigInterface;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Sentry\Integrations\ExceptionContextIntegration;
use Hypervel\Sentry\Integrations\Integration;
use Hypervel\Sentry\Integrations\RequestFetcher;
use Hypervel\Sentry\Integrations\RequestIntegration;
use Hypervel\Sentry\Version;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Sentry\ClientBuilder;
use Sentry\HttpClient\HttpClientInterface;
use Sentry\Integration as SdkIntegration;

use function Hyperf\Support\make;

class ClientBuilderFactory
{
    public const SPECIFIC_OPTIONS = [
        'breadcrumbs',
        'crons',
        'enable',
        'ignore_commands',
        'integrations',
        'tracing',
        'features',
        'pool',
    ];

    public function __invoke(Application $container)
    {
        $userConfig = $container->get(ConfigInterface::class)->get('sentry', []);
        $userConfig['enable_tracing'] ??= true;

        foreach (static::SPECIFIC_OPTIONS as $specificOptionName) {
            if (isset($userConfig[$specificOptionName])) {
                unset($userConfig[$specificOptionName]);
            }
        }

        if (isset($userConfig['logger'])) {
            if (is_string($userConfig['logger']) && $container->has($userConfig['logger'])) {
                $userConfig['logger'] = $container->get($userConfig['logger']);
            }
            if (! $userConfig['logger'] instanceof LoggerInterface) {
                unset($userConfig['logger']);
            }
        }

        $options = array_merge(
            [
                'prefixes' => [BASE_PATH],
                'in_app_exclude' => [BASE_PATH . '/vendor'],
            ],
            $userConfig
        );

        // When we get no environment from the (user) configuration we default to the environment
        if (empty($options['environment'])) {
            $options['environment'] = env('APP_ENV', 'production');
        }

        if (
            ! ($options['http_client'] ?? null) instanceof HttpClientInterface
            && $container->has(HttpClientInterface::class)
        ) {
            $options['http_client'] = $container->get(HttpClientInterface::class);
        }

        return tap(
            ClientBuilder::create($options),
            function (ClientBuilder $clientBuilder) use ($container) {
                $clientBuilder->setSdkIdentifier(Version::getSdkIdentifier())
                    ->setSdkVersion(Version::getSdkVersion());
                $this->resolveIntegrations($container, $clientBuilder);
            }
        );
    }

    protected function resolveIntegrations(Application $container, ClientBuilder $clientBuilder): void
    {
        $options = $clientBuilder->getOptions();
        $userConfig = (array) $container->get(ConfigInterface::class)->get('sentry', []);

        /** @var array<array-key, class-string>|callable $userIntegrationOption */
        $userIntegrationOption = $userConfig['integrations'] ?? [];

        $userIntegrations = $this->resolveIntegrationsFromUserConfig(
            \is_array($userIntegrationOption) ? $userIntegrationOption : []
        );

        $options->setIntegrations(
            static function (array $integrations) use (
                $options,
                $userIntegrations,
                $userIntegrationOption,
                $container
            ) {
                if ($options->hasDefaultIntegrations()) {
                    // Remove the default error and fatal exception listeners to let handle those
                    // itself. These event are still bubbling up through the documented changes in the users
                    // `ExceptionHandler` of their application or through the log channel integration to Sentry
                    $integrations = array_filter(
                        $integrations,
                        static function (SdkIntegration\IntegrationInterface $integration): bool {
                            if ($integration instanceof SdkIntegration\ErrorListenerIntegration) {
                                return false;
                            }

                            if ($integration instanceof SdkIntegration\ExceptionListenerIntegration) {
                                return false;
                            }

                            if ($integration instanceof SdkIntegration\FatalErrorListenerIntegration) {
                                return false;
                            }

                            // We also remove the default request integration so it can be readded
                            // after with a specific request fetcher. This way we can resolve
                            // the request from instead of constructing it from the global state
                            if ($integration instanceof SdkIntegration\RequestIntegration) {
                                return false;
                            }

                            return true;
                        }
                    );

                    $requestFetcher = $container->get(RequestFetcher::class);
                    $integrations[] = new SdkIntegration\RequestIntegration($requestFetcher);
                }

                $integrations = array_merge(
                    $integrations,
                    [
                        new Integration(),
                        new ExceptionContextIntegration(),
                        new RequestIntegration(),
                    ],
                    $userIntegrations
                );

                if (\is_callable($userIntegrationOption)) {
                    return $userIntegrationOption($integrations);
                }

                return $integrations;
            }
        );
    }

    /**
     * @return SdkIntegration\IntegrationInterface[]
     */
    protected function resolveIntegrationsFromUserConfig(array $userIntegrations): array
    {
        $integrations = [];

        foreach ($userIntegrations as $userIntegration) {
            if ($userIntegration instanceof SdkIntegration\IntegrationInterface) {
                $integrations[] = $userIntegration;
            } elseif (\is_string($userIntegration)) {
                $resolvedIntegration = make($userIntegration);

                if (! $resolvedIntegration instanceof SdkIntegration\IntegrationInterface) {
                    throw new RuntimeException(
                        'Sentry integrations should a instance of `\Sentry\Integration\IntegrationInterface`.'
                    );
                }

                $integrations[] = $resolvedIntegration;
            } else {
                throw new RuntimeException(
                    'Sentry integrations should either be a container reference or a instance of `\Sentry\Integration\IntegrationInterface`.'
                );
            }
        }

        return $integrations;
    }
}
