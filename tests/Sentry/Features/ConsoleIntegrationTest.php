<?php

declare(strict_types=1);

namespace Hypervel\Tests\Sentry\Features;

use Hypervel\Console\Command;
use Hypervel\Console\Events\AfterExecute;
use Hypervel\Console\Events\BeforeHandle;
use Hypervel\Database\QueryException;
use Hypervel\Sentry\Features\ConsoleIntegration;
use Hypervel\Tests\Sentry\SentryTestCase;
use RuntimeException;
use Symfony\Component\Console\Input\ArgvInput;

class ConsoleIntegrationTest extends SentryTestCase
{
    public function testCommandBreadcrumbIsRecordedWhenEnabled(): void
    {
        $this->resetApplicationWithConfig([
            'sentry' => $this->sentryConfigWith(['breadcrumbs.command_info' => true]),
        ]);

        $this->assertTrue($this->app->make('config')->boolean('sentry.breadcrumbs.command_info'));

        $baselineScope = $this->getCurrentSentryScope();
        $this->dispatchCommandStartEvent($command = new ConsoleIntegrationCommand);

        $lastBreadcrumb = $this->getLastSentryBreadcrumb();

        $this->assertEquals('Starting Artisan command: test:command', $lastBreadcrumb->getMessage());
        $this->assertEquals('--foo=bar', $lastBreadcrumb->getMetadata()['input']);

        $this->dispatchCommandFinishEvent($command, exitCode: 12);

        $this->assertSame($baselineScope, $this->getCurrentSentryScope());
        $this->assertSame([], $this->getCurrentSentryBreadcrumbs());
    }

    public function testCommandBreadcrumbIsNotRecordedWhenDisabled(): void
    {
        $this->resetApplicationWithConfig([
            'sentry' => $this->sentryConfigWith(['breadcrumbs.command_info' => false]),
        ]);

        $this->assertFalse($this->app->make('config')->boolean('sentry.breadcrumbs.command_info'));

        $command = new ConsoleIntegrationCommand;
        $this->dispatchCommandStartEvent($command);

        $this->assertEmpty($this->getCurrentSentryBreadcrumbs());

        $this->dispatchCommandFinishEvent($command);
    }

    public function testCommandScopesRemainBalancedWhenCommandsAreNested(): void
    {
        $this->resetApplicationWithConfig([
            'sentry' => $this->sentryConfigWith(['breadcrumbs.command_info' => false]),
        ]);
        $baselineScope = $this->getCurrentSentryScope();
        $outer = new ConsoleIntegrationCommand;
        $inner = new NestedConsoleIntegrationCommand;

        $this->dispatchCommandStartEvent($outer);
        $outerScope = $this->getCurrentSentryScope();
        $this->dispatchCommandStartEvent($inner);
        $innerScope = $this->getCurrentSentryScope();

        $this->assertNotSame($baselineScope, $outerScope);
        $this->assertNotSame($outerScope, $innerScope);

        $this->dispatchCommandFinishEvent($inner);
        $this->assertSame($outerScope, $this->getCurrentSentryScope());

        $this->dispatchCommandFinishEvent($outer);
        $this->assertSame($baselineScope, $this->getCurrentSentryScope());
    }

    public function testCompletionWithoutAStartedCommandDoesNotPopTheCurrentScope(): void
    {
        $this->resetApplicationWithConfig([
            'sentry' => $this->sentryConfigWith(['breadcrumbs.command_info' => false]),
        ]);
        $scope = $this->getCurrentSentryScope();

        $this->dispatchCommandFinishEvent(new ConsoleIntegrationCommand);

        $this->assertSame($scope, $this->getCurrentSentryScope());
    }

    public function testThrowableExitCodeUsesSymfonyConsoleErrorRules(): void
    {
        $this->resetApplicationWithConfig([
            'sentry' => $this->sentryConfigWith(['breadcrumbs.command_info' => true]),
        ]);
        $command = new ConsoleIntegrationCommand;
        $integration = $this->app->make(ConsoleIntegration::class);
        $input = new ArgvInput(['artisan', '--foo=bar']);

        $integration->afterExecute(new AfterExecute($command, input: $input, exitCode: 12));
        $this->assertSame(12, $this->getLastSentryBreadcrumb()?->getMetadata()['exit']);

        $integration->afterExecute(new AfterExecute($command, new RuntimeException('failed', 17), $input, 0));
        $this->assertSame(17, $this->getLastSentryBreadcrumb()?->getMetadata()['exit']);

        $queryException = new QueryException(null, 'select * from missing', [], new StringCodeConsoleException);
        $integration->afterExecute(new AfterExecute($command, $queryException, $input, 0));
        $this->assertSame(Command::FAILURE, $this->getLastSentryBreadcrumb()?->getMetadata()['exit']);
    }

    private function dispatchCommandStartEvent(Command $command): void
    {
        $this->dispatchHypervelEvent(new BeforeHandle(
            $command,
            new ArgvInput(['artisan', '--foo=bar']),
        ));
    }

    private function dispatchCommandFinishEvent(
        Command $command,
        ?RuntimeException $throwable = null,
        ?int $exitCode = 0,
    ): void {
        $this->dispatchHypervelEvent(new AfterExecute(
            $command,
            $throwable,
            new ArgvInput(['artisan', '--foo=bar']),
            $exitCode,
        ));
    }
}

class ConsoleIntegrationCommand extends Command
{
    protected ?string $signature = 'test:command';

    public function handle(): void
    {
    }
}

class NestedConsoleIntegrationCommand extends Command
{
    protected ?string $signature = 'test:nested';

    public function handle(): void
    {
    }
}

class StringCodeConsoleException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('failed');

        $this->code = '42S02';
    }
}
