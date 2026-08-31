<?php

declare(strict_types=1);

namespace Hypervel\Tests\Sentry\Features;

use Hypervel\Console\Command;
use Hypervel\Console\Events\AfterExecute;
use Hypervel\Console\Events\BeforeHandle;
use Hypervel\Database\QueryException;
use Hypervel\Events\Dispatcher;
use Hypervel\Sentry\Features\ConsoleIntegration;
use Hypervel\Tests\Sentry\SentryTestCase;
use RuntimeException;
use Sentry\SentrySdk;
use Symfony\Component\Console\Input\ArgvInput;

class ConsoleIntegrationTest extends SentryTestCase
{
    public function testCommandBreadcrumbIncludesInputWhenPiiIsEnabled(): void
    {
        $this->resetApplicationWithConfig([
            'sentry' => $this->sentryConfigWith([
                'breadcrumbs.command_info' => true,
                'send_default_pii' => true,
            ]),
        ]);

        $this->assertTrue($this->app->make('config')->boolean('sentry.breadcrumbs.command_info'));

        $baselineScope = $this->getCurrentSentryScope();
        $this->dispatchCommandStartEvent($command = new ConsoleIntegrationCommand, true);

        $lastBreadcrumb = $this->getLastSentryBreadcrumb();

        $this->assertEquals('Starting Artisan command: test:command', $lastBreadcrumb->getMessage());
        $this->assertEquals('--foo=bar', $lastBreadcrumb->getMetadata()['input']);

        $unmatchedCommand = new ConsoleIntegrationCommand;
        $this->app->make(ConsoleIntegration::class)->afterExecute(new AfterExecute(
            $unmatchedCommand,
            input: $this->commandInput($unmatchedCommand, true),
            exitCode: 12,
        ));
        $lastBreadcrumb = $this->getLastSentryBreadcrumb();

        $this->assertSame(12, $lastBreadcrumb->getMetadata()['exit']);
        $this->assertSame('--foo=bar', $lastBreadcrumb->getMetadata()['input']);

        $this->dispatchCommandFinishEvent($command, exitCode: 12, withValue: true);

        $this->assertSame($baselineScope, $this->getCurrentSentryScope());
        $this->assertSame([], $this->getCurrentSentryBreadcrumbs());
    }

    public function testCommandBreadcrumbOmitsInputWhenPiiIsDisabled(): void
    {
        $this->resetApplicationWithConfig([
            'sentry' => $this->sentryConfigWith([
                'breadcrumbs.command_info' => true,
                'send_default_pii' => false,
            ]),
        ]);

        $baselineScope = $this->getCurrentSentryScope();
        $this->dispatchCommandStartEvent($command = new ConsoleIntegrationCommand, true);

        $this->assertArrayNotHasKey('input', $this->getLastSentryBreadcrumb()->getMetadata());

        $unmatchedCommand = new ConsoleIntegrationCommand;
        $this->app->make(ConsoleIntegration::class)->afterExecute(new AfterExecute(
            $unmatchedCommand,
            input: $this->commandInput($unmatchedCommand, true),
            exitCode: 12,
        ));
        $metadata = $this->getLastSentryBreadcrumb()->getMetadata();

        $this->assertSame(12, $metadata['exit']);
        $this->assertArrayNotHasKey('input', $metadata);

        $this->dispatchCommandFinishEvent($command, exitCode: 12, withValue: true);

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

    public function testDuplicateCompletionDoesNotPopTheParentCommandScope(): void
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

        $this->dispatchCommandFinishEvent($inner);
        $this->dispatchCommandFinishEvent($inner);

        $this->assertSame($outerScope, $this->getCurrentSentryScope());

        $this->dispatchCommandFinishEvent($outer);
        $this->assertSame($baselineScope, $this->getCurrentSentryScope());
    }

    public function testCommandCompletionOnlyPopsItsOwnedCurrentScope(): void
    {
        $this->resetApplicationWithConfig([
            'sentry' => $this->sentryConfigWith(['breadcrumbs.command_info' => true]),
        ]);
        $baselineScope = $this->getCurrentSentryScope();
        $command = new ConsoleIntegrationCommand;

        $this->dispatchCommandStartEvent($command);
        $commandScope = $this->getCurrentSentryScope();
        $foreignScope = SentrySdk::getCurrentHub()->pushScope();

        $this->dispatchCommandFinishEvent($command);

        $this->assertSame($foreignScope, $this->getCurrentSentryScope());
        $this->assertSame(
            'Finished Artisan command: test:command',
            $this->getLastSentryBreadcrumb()?->getMessage(),
        );

        SentrySdk::getCurrentHub()->popScope();
        $this->assertSame($commandScope, $this->getCurrentSentryScope());

        $this->dispatchCommandFinishEvent($command);
        $this->assertSame($baselineScope, $this->getCurrentSentryScope());
    }

    public function testOuterCompletionDoesNotPopNestedScopeWhenInnerCompletionWasStopped(): void
    {
        $this->resetApplicationWithConfig([
            'sentry' => $this->sentryConfigWith(['breadcrumbs.command_info' => false]),
        ]);
        $baselineScope = $this->getCurrentSentryScope();
        $integration = $this->app->make(ConsoleIntegration::class);
        $dispatcher = new Dispatcher($this->app);
        $outer = new ConsoleIntegrationCommand;
        $inner = new NestedConsoleIntegrationCommand;
        $input = new ArgvInput(['artisan']);

        $dispatcher->listen(BeforeHandle::class, [$integration, 'beforeHandle']);
        $dispatcher->listen(AfterExecute::class, static function (AfterExecute $event) use ($inner): ?bool {
            return $event->command === $inner ? false : null;
        });
        $dispatcher->listen(AfterExecute::class, [$integration, 'afterExecute']);

        $dispatcher->dispatch(new BeforeHandle($outer, $input));
        $dispatcher->dispatch(new BeforeHandle($inner, $input));
        $innerScope = $this->getCurrentSentryScope();

        $dispatcher->dispatch(new AfterExecute($inner, input: $input, exitCode: 0));
        $dispatcher->dispatch(new AfterExecute($outer, input: $input, exitCode: 0));

        $this->assertSame($innerScope, $this->getCurrentSentryScope());

        $integration->afterExecute(new AfterExecute($inner, input: $input, exitCode: 0));
        $integration->afterExecute(new AfterExecute($outer, input: $input, exitCode: 0));
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

    public function testUnnamedCommandCompletionDoesNotPopItsParentCommandScope(): void
    {
        $this->resetApplicationWithConfig([
            'sentry' => $this->sentryConfigWith(['breadcrumbs.command_info' => false]),
        ]);
        $baselineScope = $this->getCurrentSentryScope();
        $outer = new ConsoleIntegrationCommand;
        $inner = new UnnamedConsoleIntegrationCommand;

        $this->dispatchCommandStartEvent($outer);
        $outerScope = $this->getCurrentSentryScope();

        $this->dispatchCommandStartEvent($inner);
        $this->dispatchCommandFinishEvent($inner);

        $this->assertSame($outerScope, $this->getCurrentSentryScope());

        $this->dispatchCommandFinishEvent($outer);
        $this->assertSame($baselineScope, $this->getCurrentSentryScope());
    }

    public function testStoppedStartEventDoesNotLetCompletionPopItsParentCommandScope(): void
    {
        $this->resetApplicationWithConfig([
            'sentry' => $this->sentryConfigWith(['breadcrumbs.command_info' => false]),
        ]);
        $baselineScope = $this->getCurrentSentryScope();
        $integration = $this->app->make(ConsoleIntegration::class);
        $outer = new ConsoleIntegrationCommand;
        $inner = new NestedConsoleIntegrationCommand;
        $input = new ArgvInput(['artisan', '--foo=bar']);

        $integration->beforeHandle(new BeforeHandle($outer, $input));
        $outerScope = $this->getCurrentSentryScope();

        $dispatcher = new Dispatcher($this->app);
        $dispatcher->listen(BeforeHandle::class, static fn (): bool => false);
        $dispatcher->listen(BeforeHandle::class, [$integration, 'beforeHandle']);
        $dispatcher->dispatch(new BeforeHandle($inner, $input));

        $integration->afterExecute(new AfterExecute($inner, input: $input, exitCode: 0));

        $this->assertSame($outerScope, $this->getCurrentSentryScope());

        $integration->afterExecute(new AfterExecute($outer, input: $input, exitCode: 0));
        $this->assertSame($baselineScope, $this->getCurrentSentryScope());
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

    private function dispatchCommandStartEvent(Command $command, bool $withValue = false): void
    {
        $this->dispatchHypervelEvent(new BeforeHandle(
            $command,
            $this->commandInput($command, $withValue),
        ));
    }

    private function dispatchCommandFinishEvent(
        Command $command,
        ?RuntimeException $throwable = null,
        ?int $exitCode = 0,
        bool $withValue = false,
    ): void {
        $this->dispatchHypervelEvent(new AfterExecute(
            $command,
            $throwable,
            $this->commandInput($command, $withValue),
            $exitCode,
        ));
    }

    private function commandInput(Command $command, bool $withValue = false): ArgvInput
    {
        $input = new ArgvInput($withValue ? ['artisan', '--foo=bar'] : ['artisan']);
        $input->bind($command->getDefinition());

        return $input;
    }
}

class ConsoleIntegrationCommand extends Command
{
    protected ?string $signature = 'test:command {--foo=}';

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

class UnnamedConsoleIntegrationCommand extends Command
{
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
