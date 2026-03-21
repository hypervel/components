<?php

declare(strict_types=1);

namespace Hypervel\Tests\Testbench\Attributes;

use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Foundation\Bootstrap\LoadConfiguration;
use Hypervel\Testbench\Attributes\ResolvesHypervel;
use Hypervel\Testbench\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * @internal
 * @coversNothing
 */
class ResolvesHypervelTest extends TestCase
{
    #[Test]
    #[ResolvesHypervel('hypervelDefaultConfiguration')]
    public function itCanResolveDefinedConfiguration(): void
    {
        $this->assertSame(LoadConfiguration::class, $this->app[LoadConfiguration::class]::class);
    }

    /**
     * Resolve Hypervel.
     */
    public function hypervelDefaultConfiguration(ApplicationContract $app): void
    {
        $app->bind(LoadConfiguration::class, LoadConfiguration::class);
    }
}
