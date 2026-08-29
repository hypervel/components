<?php

declare(strict_types=1);

namespace Hypervel\Sentry\Features;

use Hypervel\Console\Command;
use Hypervel\Console\Events\AfterExecute;
use Hypervel\Console\Events\BeforeHandle;
use Hypervel\Context\CoroutineContext;
use Hypervel\Sentry\Features\Concerns\TracksPushedScopesAndSpans;
use Hypervel\Sentry\Integration;
use Sentry\Breadcrumb;
use Sentry\State\Scope;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Input\InputInterface;
use WeakMap;

class ConsoleIntegration extends Feature
{
    use TracksPushedScopesAndSpans;

    private const string FEATURE_KEY = 'command_info';

    private const string COMMAND_SCOPE_OWNERS_CONTEXT_KEY = '__sentry.console.command_scope_owners';

    public function isApplicable(): bool
    {
        return true;
    }

    public function onBoot(): void
    {
        $dispatcher = $this->container->make('events');

        $dispatcher->listen(BeforeHandle::class, [$this, 'beforeHandle']);
        $dispatcher->listen(AfterExecute::class, [$this, 'afterExecute']);
    }

    /**
     * Handle a command before execution.
     */
    public function beforeHandle(BeforeHandle $event): void
    {
        if (! $command = $event->command->getName()) {
            return;
        }

        $this->pushScope();

        /** @var WeakMap<Command, bool> $commandScopeOwners */
        $commandScopeOwners = CoroutineContext::getOrSet(
            self::COMMAND_SCOPE_OWNERS_CONTEXT_KEY,
            fn () => new WeakMap,
        );

        $commandScopeOwners[$event->command] = true;

        Integration::configureScope(static function (Scope $scope) use ($command): void {
            $scope->setTag('command', $command);
        });

        if ($this->isBreadcrumbFeatureEnabled(self::FEATURE_KEY)) {
            Integration::addBreadcrumb(new Breadcrumb(
                Breadcrumb::LEVEL_INFO,
                Breadcrumb::TYPE_DEFAULT,
                'artisan.command',
                'Starting Artisan command: ' . $command,
                [
                    'input' => $this->extractConsoleCommandInput($event->input),
                ]
            ));
        }
    }

    /**
     * Handle a command after execution.
     */
    public function afterExecute(AfterExecute $event): void
    {
        $command = $event->command->getName();

        if ($command && $this->isBreadcrumbFeatureEnabled(self::FEATURE_KEY)) {
            Integration::addBreadcrumb(new Breadcrumb(
                Breadcrumb::LEVEL_INFO,
                Breadcrumb::TYPE_DEFAULT,
                'artisan.command',
                'Finished Artisan command: ' . $command,
                [
                    'exit' => $this->resolveExitCode($event),
                    'input' => $this->extractConsoleCommandInput($event->input),
                ]
            ));
        }

        /** @var null|WeakMap<Command, bool> $commandScopeOwners */
        $commandScopeOwners = CoroutineContext::get(self::COMMAND_SCOPE_OWNERS_CONTEXT_KEY);

        // An unmatched terminal event must not pop a scope another command owns:
        // an earlier BeforeHandle listener can stop propagation before this one runs.
        if (isset($commandScopeOwners[$event->command])) {
            unset($commandScopeOwners[$event->command]);

            $this->maybePopScope();
        }
    }

    /**
     * Resolve the command exit code represented by the terminal event.
     */
    private function resolveExitCode(AfterExecute $event): ?int
    {
        if ($event->throwable === null) {
            return $event->exitCode;
        }

        $exitCode = $event->throwable->getCode();

        return is_int($exitCode) && $exitCode !== 0 ? $exitCode : Command::FAILURE;
    }

    /**
     * Extract the command input arguments if possible.
     */
    private function extractConsoleCommandInput(?InputInterface $input): ?string
    {
        if ($input instanceof ArgvInput) {
            return (string) $input;
        }

        return null;
    }
}
