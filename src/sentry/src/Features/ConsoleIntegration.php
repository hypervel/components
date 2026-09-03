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
use Sentry\SentrySdk;
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

        /** @var WeakMap<Command, Scope> $commandScopeOwners */
        $commandScopeOwners = CoroutineContext::getOrSet(
            self::COMMAND_SCOPE_OWNERS_CONTEXT_KEY,
            fn () => new WeakMap,
        );

        $commandScopeOwners[$event->command] = $this->pushScope();

        Integration::configureScope(static function (Scope $scope) use ($command): void {
            $scope->setTag('command', $command);
        });

        if ($this->isBreadcrumbFeatureEnabled(self::FEATURE_KEY)) {
            Integration::addBreadcrumb(new Breadcrumb(
                Breadcrumb::LEVEL_INFO,
                Breadcrumb::TYPE_DEFAULT,
                'artisan.command',
                'Starting Artisan command: ' . $command,
                $this->commandInputMetadata($event->input),
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
                array_merge([
                    'exit' => $this->resolveExitCode($event),
                ], $this->commandInputMetadata($event->input)),
            ));
        }

        /** @var null|WeakMap<Command, Scope> $commandScopeOwners */
        $commandScopeOwners = CoroutineContext::get(self::COMMAND_SCOPE_OWNERS_CONTEXT_KEY);
        $ownedScope = $commandScopeOwners[$event->command] ?? null;

        // An unmatched terminal must not pop a scope another command owns: an earlier
        // BeforeHandle listener can stop propagation before this one runs. Membership
        // alone is not enough either, because every Sentry feature shares the Hub stack
        // and another feature may have pushed a newer frame that is not ours to pop.
        if ($ownedScope !== null && $this->isCurrentScope($ownedScope)) {
            unset($commandScopeOwners[$event->command]);

            $this->maybePopScope();
        }
    }

    /**
     * Determine whether the owned scope remains the current Hub frame.
     *
     * Hub layers retain the Scope returned by pushScope(), making object identity
     * a stable ownership key. Commands run after application boot, when the Hub's
     * configureScope() callback receives the current execution frame; before boot
     * it observes the bootstrap baseline and safely declines to pop. Call the Hub
     * directly because Integration::configureScope() may skip its callback.
     */
    private function isCurrentScope(Scope $scope): bool
    {
        $currentScope = null;

        SentrySdk::getCurrentHub()->configureScope(
            static function (Scope $candidate) use (&$currentScope): void {
                $currentScope = $candidate;
            }
        );

        return $currentScope === $scope;
    }

    /**
     * Resolve the command exit code represented by the terminal event.
     */
    private function resolveExitCode(AfterExecute $event): int
    {
        if ($event->throwable === null) {
            return $event->exitCode;
        }

        $exitCode = $event->throwable->getCode();

        return is_int($exitCode) && $exitCode !== 0 ? $exitCode : Command::FAILURE;
    }

    /**
     * Get command input metadata allowed by the PII configuration.
     *
     * @return array{input?: string}
     */
    private function commandInputMetadata(InputInterface $input): array
    {
        if (! $this->shouldSendDefaultPii() || ! $input instanceof ArgvInput) {
            return [];
        }

        return ['input' => (string) $input];
    }
}
