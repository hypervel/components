<?php

declare(strict_types=1);

namespace Hypervel\Tests\Support;

use Hypervel\Container\Container;
use Hypervel\Support\Facades\Facade;
use Hypervel\Tests\TestCase;
use stdClass;

use function Hypervel\Coroutine\parallel;
use function Hypervel\Coroutine\run;

class SupportFacadeNonCoroutineTest extends TestCase
{
    protected bool $runTestsInCoroutine = false;

    public function testBoundFacadeRootIsKeptPerCoroutine(): void
    {
        $app = new Container;
        $app->bind('foo', static fn (): stdClass => new stdClass);
        NonCoroutineFacadeStub::setFacadeApplication($app);

        // Boot code resolves outside any coroutine; requests must not inherit that root.
        $bootRoot = NonCoroutineFacadeStub::getFacadeRoot();
        $this->assertSame($bootRoot, NonCoroutineFacadeStub::getFacadeRoot());

        $resolve = static function (): array {
            $root = NonCoroutineFacadeStub::getFacadeRoot();
            usleep(1000);

            return [$root, NonCoroutineFacadeStub::getFacadeRoot()];
        };

        run(static function () use (&$roots, $resolve): void {
            $roots = parallel([$resolve, $resolve]);
        });

        [[$first, $firstAgain], [$second, $secondAgain]] = $roots;

        $this->assertSame($first, $firstAgain);
        $this->assertSame($second, $secondAgain);
        $this->assertNotSame($first, $second);
        $this->assertNotSame($bootRoot, $first);
        $this->assertNotSame($bootRoot, $second);
    }
}

class NonCoroutineFacadeStub extends Facade
{
    /**
     * Get the registered name of the component.
     */
    protected static function getFacadeAccessor(): string
    {
        return 'foo';
    }
}
