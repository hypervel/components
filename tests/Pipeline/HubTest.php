<?php

declare(strict_types=1);

namespace Hypervel\Tests\Pipeline;

use Hypervel\Container\Container;
use Hypervel\Pipeline\Hub;
use Hypervel\Pipeline\Pipeline;
use Hypervel\Tests\TestCase;
use InvalidArgumentException;

class HubTest extends TestCase
{
    private Hub $hub;

    protected function setUp(): void
    {
        parent::setUp();

        $this->hub = new Hub(new Container);
    }

    public function testPipeSendsObjectThroughDefaultPipeline(): void
    {
        $this->hub->defaults(function (Pipeline $pipeline, mixed $object): mixed {
            return $pipeline->send($object)->through([])->thenReturn();
        });

        $this->assertSame('foo', $this->hub->pipe('foo'));
    }

    public function testPipeSendsObjectThroughNamedPipeline(): void
    {
        $this->hub->pipeline('named', function (Pipeline $pipeline, mixed $object): mixed {
            return $pipeline->send($object)->through([])->thenReturn();
        });

        $this->assertSame('foo', $this->hub->pipe('foo', 'named'));
    }

    public function testPipeThrowsExceptionForUndefinedPipeline(): void
    {
        $this->expectExceptionObject(new InvalidArgumentException('Pipeline [missing] is not defined.'));

        $this->hub->pipe('foo', 'missing');
    }

    public function testHubPreservesZeroNamedPipelines(): void
    {
        $this->hub->defaults(fn (Pipeline $pipeline, string $value): string => 'default-' . $value);
        $this->hub->pipeline('0', fn (Pipeline $pipeline, string $value): string => 'zero-' . $value);

        $this->assertSame('default-value', $this->hub->pipe('value'));
        $this->assertSame('default-value', $this->hub->pipe('value', ''));
        $this->assertSame('zero-value', $this->hub->pipe('value', '0'));
    }
}
