<?php

declare(strict_types=1);

namespace Hypervel\Tests\Sentry\Features;

use Hypervel\Console\Command;
use Hypervel\Console\Events\AfterExecute;
use Hypervel\Console\Events\BeforeHandle;
use Hypervel\Tests\Sentry\SentryTestCase;
use Symfony\Component\Console\Input\ArgvInput;

class ConsoleIntegrationNonCoroutineTest extends SentryTestCase
{
    protected bool $runTestsInCoroutine = false;

    public function testCommandScopeIsBalancedOutsideACoroutine(): void
    {
        $this->resetApplicationWithConfig([
            'sentry' => $this->sentryConfigWith(['breadcrumbs.command_info' => false]),
        ]);
        $scope = $this->getCurrentSentryScope();
        $command = new NonCoroutineConsoleIntegrationCommand;
        $input = new ArgvInput(['artisan']);

        $this->dispatchHypervelEvent(new BeforeHandle($command, $input));
        $this->assertNotSame($scope, $this->getCurrentSentryScope());

        $this->dispatchHypervelEvent(new AfterExecute($command, input: $input, exitCode: 0));
        $this->assertSame($scope, $this->getCurrentSentryScope());
    }
}

class NonCoroutineConsoleIntegrationCommand extends Command
{
    protected ?string $signature = 'test:non-coroutine';

    protected bool $coroutine = false;

    public function handle(): void
    {
    }
}
