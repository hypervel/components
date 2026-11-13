<?php

declare(strict_types=1);

namespace Hypervel\Tests\PHPStan\Rules\Coroutine\WorkerStateGlobals;

use Hypervel\Http\Request;
use Hypervel\Support\ServiceProvider;

class GlobalsController
{
    public function index(Request $request): void
    {
        $GLOBALS['current_request'] = $request;
        $GLOBALS['requests']++;
        unset($GLOBALS['tenant']);
        $count = 1;
        $this->remember();
        $this->update($request);
    }

    private function remember(): void
    {
        global $tenant;
        $tenant = 'acme';
    }

    private function update(Request $request): void
    {
        global $settings, $profile, $visits, $previous;
        unset($settings['theme']);
        unset($profile->locale);
        $settings['request'] = &$request;
        $visits += 1;
        $GLOBALS['visits'] .= '!';
        [$previous] = [$request];
        [$GLOBALS['first']] = [$request];

        if (! function_exists(__NAMESPACE__ . '\\countLocally')) {
            function countLocally(): void
            {
                $visits = 1;
            }
        }

        unset($settings);
        $settings = $request;
    }
}

class GlobalsService
{
    public function keep(Request $request): void
    {
        global $held;
        $held['request'] = $request;
        $GLOBALS['request'] = $request;
        $local = $request;
        [$held] = [$request];
        $nested = function () use ($request): void {
            $held = $request;
        };
    }

    public function detach(Request $request, bool $shared): void
    {
        global $held, $other;
        unset($held);
        $held = $request;
        $other = &$request;
        $other = $request;

        if ($shared) {
            global $conditional;
        }

        $conditional = $request;
    }

    public function beforeDeclaration(Request $request): void
    {
        $held = $request;
        global $held;
    }
}

class GlobalsTraits
{
    use KeepsRequest;
    use KeepsAccount;
}

class GlobalsServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $GLOBALS['booted'] = true;
    }
}
