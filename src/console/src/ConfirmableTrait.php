<?php

declare(strict_types=1);

namespace Hypervel\Console;

use Closure;

use function Hypervel\Prompts\confirm;
use function value;

trait ConfirmableTrait
{
    /**
     * Confirm before proceeding with the action.
     *
     * This method only asks for confirmation in production.
     */
    public function confirmToProceed(string $warning = 'Application In Production', bool|Closure|null $callback = null): bool
    {
        $callback ??= $this->getDefaultConfirmCallback();

        $shouldConfirm = value($callback);

        if ($shouldConfirm) {
            if ($this->hasOption('force') && $this->option('force')) {
                return true;
            }

            $this->components->alert($warning);

            $confirmed = confirm('Are you sure you want to run this command?', default: false);

            if (! $confirmed) {
                $this->components->warn('Command cancelled.');

                return false;
            }
        }

        return true;
    }

    /**
     * Get the default confirmation callback.
     */
    protected function getDefaultConfirmCallback(): Closure
    {
        return function () {
            return $this->app->isProduction();
        };
    }
}
