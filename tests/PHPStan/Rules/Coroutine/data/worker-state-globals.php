<?php

declare(strict_types=1);

namespace Hypervel\Tests\PHPStan\Rules\Coroutine\WorkerStateGlobals;

use Hypervel\Http\Request;
use Hypervel\Support\ServiceProvider;
use RuntimeException;

class Matcher
{
    public function fill(?array &$matches): void
    {
        $matches = [];
    }
}

class GlobalsController
{
    private ?Matcher $matcher = null;

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
        global $settings, $profile, $visits, $previous, $position, $current, $listed, $alias, $rebound, $failure;
        unset($settings['theme']);
        unset($profile->locale);
        $settings['request'] = &$request;
        $visits += 1;
        $GLOBALS['visits'] .= '!';
        [$previous] = [$request];
        [$GLOBALS['first']] = [$request];
        $requests = [$request];

        foreach ($requests as $position => $current) {
        }

        foreach ([[$request]] as [$listed]) {
        }

        foreach ($requests as &$GLOBALS['loop']) {
        }

        foreach ($requests as &$alias) {
        }

        $alias = $request;
        [&$rebound] = $requests;
        $rebound = $request;
        [&$settings['reference']] = $requests;

        try {
            $this->remember();
        } catch (RuntimeException $failure) {
        }

        global $matches;
        preg_match('/\d+/', $request->path(), $matches);
        sort($GLOBALS['list']);
        count($GLOBALS['list']);
        count($matches);
        $match = preg_match(...);
        $this->matcher?->fill($matches);
        $this->matcher?->fill($GLOBALS['filled']);
        sort($profile->tags);

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

    public function aliases(Request $request): void
    {
        $forward = &$GLOBALS['forward'];
        $forward = $request;
        $GLOBALS['reverse'] = &$reverse;
        $reverse = $request;
        global $registry;
        $registry['item'] = &$item;
        $item = $request;
        $first = &$GLOBALS['chained'];
        $second = &$first;
        $second = $request;
        global $pointed;
        $pointed = &$local;
        $local = $request;
        $gone = &$GLOBALS['gone'];
        unset($gone);
        $gone = $request;
        $moved = &$GLOBALS['moved'];
        $moved = &$elsewhere;
        $moved = $request;
        global $memo;
        $memo ??= $request;
        $GLOBALS['memo'] ??= $request;
        global $holder;
        $property = &$holder->value;
        $property = $request;
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
