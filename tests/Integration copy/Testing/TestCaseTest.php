<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Testing;

use Hypervel\Support\Facades\Vite;
use Hypervel\Testbench\TestCase;

/**
 * @internal
 * @coversNothing
 */
class TestCaseTest extends TestCase
{
    public function testWithoutViteClearFacadeResolvedInstance()
    {
        Vite::useScriptTagAttributes([
            'crossorigin' => 'anonymous',
        ]);

        $this->withoutVite();

        Vite::asset('foo.png');
    }
}
