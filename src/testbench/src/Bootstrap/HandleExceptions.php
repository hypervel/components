<?php

declare(strict_types=1);

namespace Hypervel\Testbench\Bootstrap;

use Hypervel\Log\LogManager;
use Hypervel\Testbench\Exceptions\DeprecatedException;
use Hypervel\Testbench\Foundation\Env;
use Override;

use function Hypervel\Testbench\join_paths;

/**
 * @internal
 */
final class HandleExceptions extends \Hypervel\Foundation\Bootstrap\HandleExceptions
{
    /**
     * @throws DeprecatedException
     */
    #[Override]
    public function handleDeprecationError(string $message, string $file, int $line, int $level = E_DEPRECATED): void
    {
        rescue(function () use ($message, $file, $line, $level): void {
            parent::handleDeprecationError($message, $file, $line, $level);
        }, report: false);

        $testbenchConvertDeprecationsToExceptions = (bool) Env::get(
            'TESTBENCH_CONVERT_DEPRECATIONS_TO_EXCEPTIONS',
            false
        );

        if ($testbenchConvertDeprecationsToExceptions === true) {
            throw new DeprecatedException($message, $level, $file, $line);
        }
    }

    #[Override]
    protected function ensureDeprecationLoggerIsConfigured(): void
    {
        $config = self::$app->make('config');

        if ($config->get('logging.channels.deprecations')) {
            return;
        }

        $options = $config->array('logging.deprecations');
        $this->ensureDeprecationChannelIsDefined($options);

        $driver = $options['channel'] ?? 'null';
        $trace = $config->boolean('logging.deprecations.trace');

        if ($driver === 'single') {
            $config->set('logging.channels.deprecations', array_merge($config->array('logging.channels.single'), [
                'path' => self::$app->storagePath(join_paths('logs', 'deprecations.log')),
            ]));
        } else {
            $config->set('logging.channels.deprecations', $config->array("logging.channels.{$driver}"));
        }

        $config->set('logging.deprecations', [
            'channel' => 'deprecations',
            'trace' => $trace,
        ]);
    }

    #[Override]
    protected function shouldIgnoreDeprecationErrors(): bool
    {
        return ! class_exists(LogManager::class)
            || self::$app === null
            || ! self::$app->hasBeenBootstrapped()
            || ! (bool) Env::get('LOG_DEPRECATIONS_WHILE_TESTING', true);
    }
}
