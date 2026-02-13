<?php

declare(strict_types=1);

namespace Hyperf\Command\Concerns;

use Closure;
use Hypervel\Container\Container;
use Hypervel\Contracts\Foundation\Application as ApplicationContract;

use function Hyperf\Support\value;

trait Confirmable
{
    /**
     * Confirm before proceeding with the action.
     *
     * This method only asks for confirmation in production.
     */
    public function confirmToProceed(string $warning = 'Application In Production!', bool|Closure|null $callback = null): bool
    {
        $callback ??= $this->isShouldConfirm();

        $shouldConfirm = value($callback);

        if ($shouldConfirm) {
            if ($this->input->getOption('force')) {
                return true;
            }

            $this->alert($warning);

            $confirmed = $this->confirm('Are you sure you want to run this command?');

            if (! $confirmed) {
                $this->comment('Command Cancelled!');

                return false;
            }
        }

        return true;
    }

    protected function isShouldConfirm(): bool
    {
        return Container::getInstance()
            ->make(ApplicationContract::class)
            ->isProduction();
    }
}
