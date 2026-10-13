<?php

declare(strict_types=1);

namespace Hypervel\Tests\Sentry\Features;

use Hypervel\Config\Repository;
use Hypervel\Console\Command;
use Hypervel\Contracts\Console\Kernel as ConsoleKernelContract;
use Hypervel\Contracts\Debug\ExceptionHandler;
use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Foundation\Application;
use Hypervel\Foundation\Configuration\ApplicationBuilder;
use Hypervel\Foundation\Configuration\Exceptions;
use Hypervel\Foundation\Configuration\Middleware;
use Hypervel\Sentry\Integration;
use Hypervel\Tests\Sentry\TestCase;
use RuntimeException;
use Sentry\Event;
use Sentry\EventHint;
use Sentry\SentrySdk;
use Sentry\State\Scope;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Throwable;

use function Sentry\configureScope;

class ConsoleExceptionScopePropagationTest extends TestCase
{
    /**
     * Resolve the application instance.
     */
    protected function resolveApplication(): ApplicationContract
    {
        return (new ApplicationBuilder(new Application($this->getApplicationBasePath())))
            ->withProviders()
            ->withMiddleware(static function (Middleware $middleware): void {
            })
            ->withCommands([
                ThrowConsoleExceptionCommand::class,
            ])
            ->withExceptions(function (Exceptions $exceptions): void {
                configureScope(static function (Scope $scope): void {
                    $scope->setContext('Context name', [
                        'key' => 'value',
                    ]);

                    $scope->setTag('tag_key', 'tag_value');
                });

                Integration::handles($exceptions);
            })
            ->create();
    }

    /**
     * Define the test environment.
     */
    protected function defineEnvironment(ApplicationContract $app): void
    {
        self::$lastSentryEvents = [];
        SentrySdk::init();

        tap($app->make('config'), function (Repository $config): void {
            $config->set('sentry.before_send', static function (Event $event, ?EventHint $hint): null {
                self::$lastSentryEvents[] = [$event, $hint];

                return null;
            });
        });

        // This simulates the scenario where Hypervel first resolves the exception handler, which will execute the
        // withExceptions(..) callback and creates the real Sentry hub later.
        $app->make(ExceptionHandler::class);
    }

    public function testUnhandledConsoleExceptionKeepsScopeConfiguredThroughWithExceptions(): void
    {
        $exitCode = $this->runConsoleCommand('sentry:test-cli-scope-regression');

        $this->assertSame(1, $exitCode);
        $this->assertSentryEventCount(1);

        $event = $this->getLastSentryEvent();

        $this->assertNotNull($event);
        $this->assertSame(RuntimeException::class, $event->getExceptions()[0]->getType());
        $this->assertSame('Unhandled console exception for scope propagation regression test.', $event->getExceptions()[0]->getValue());
        $this->assertSame('tag_value', $event->getTags()['tag_key'] ?? null);
        $this->assertSame('value', $event->getContexts()['Context name']['key'] ?? null);
    }

    public function testUnhandledConsoleExceptionIsReportedAfterCommandScopeCleanup(): void
    {
        $exitCode = $this->runConsoleCommand('sentry:test-cli-scope-regression');

        $this->assertSame(1, $exitCode);

        $event = $this->getLastSentryEvent();

        $this->assertNotNull($event);
        $this->assertSame(RuntimeException::class, $event->getExceptions()[0]->getType());
        $this->assertSame('Unhandled console exception for scope propagation regression test.', $event->getExceptions()[0]->getValue());
        $this->assertArrayNotHasKey('command', $event->getTags());
    }

    /**
     * Run the console command through the kernel and return its exit code.
     */
    private function runConsoleCommand(string $command): int
    {
        $input = new ArgvInput(['artisan', $command]);
        $output = new BufferedOutput;
        $kernel = $this->app->make(ConsoleKernelContract::class);

        try {
            $exitCode = $kernel->handle($input, $output);
        } catch (Throwable $exception) {
            $this->app->make(ExceptionHandler::class)->report($exception);

            $exitCode = 1;
        }

        $kernel->terminate($input, $exitCode);

        return $exitCode;
    }
}

class ThrowConsoleExceptionCommand extends Command
{
    protected ?string $signature = 'sentry:test-cli-scope-regression';

    protected string $description = 'Throw an exception to test console scope propagation';

    /**
     * Execute the console command.
     */
    public function handle(): never
    {
        throw new RuntimeException('Unhandled console exception for scope propagation regression test.');
    }
}
