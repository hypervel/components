<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Console;

use Closure;
use Hypervel\Context\ApplicationContext;
use Hypervel\Foundation\Contracts\Application as ApplicationContract;

use function Hyperf\Support\value;

trait ConfirmableTrait
{
    /**
     * Confirm before proceeding with the action.
     *
     * This method only asks for confirmation in production.
     */
    public function confirmToProceed(string $warning = 'Application In Production!', null|bool|Closure $callback = null): bool
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
        return ApplicationContext::getContainer()
            ->get(ApplicationContract::class)
            ->isProduction();
    }
}
