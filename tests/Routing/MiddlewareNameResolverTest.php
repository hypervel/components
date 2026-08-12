<?php

declare(strict_types=1);

namespace Hypervel\Tests\Routing;

use Hypervel\Routing\MiddlewareNameResolver;
use Hypervel\Tests\TestCase;
use LogicException;

class MiddlewareNameResolverTest extends TestCase
{
    public function testDirectMiddlewareGroupCyclesAreRejected(): void
    {
        $this->expectExceptionObject(new LogicException('[web] middleware group is referencing itself.'));

        MiddlewareNameResolver::resolve('web', [], ['web' => ['web']]);
    }

    public function testIndirectMiddlewareGroupCyclesAreRejectedWithTheCompleteCycle(): void
    {
        $this->expectExceptionObject(new LogicException(
            'Middleware group cycle detected: [first -> second -> third -> first].'
        ));

        MiddlewareNameResolver::resolve('first', [], [
            'first' => ['second'],
            'second' => ['third'],
            'third' => ['first'],
        ]);
    }

    public function testNestedDirectMiddlewareGroupCyclesUseTheDirectCycleError(): void
    {
        $this->expectExceptionObject(new LogicException('[second] middleware group is referencing itself.'));

        MiddlewareNameResolver::resolve('first', [], [
            'first' => ['second'],
            'second' => ['second'],
        ]);
    }

    public function testMiddlewareGroupsMayReuseSiblingGroups(): void
    {
        $this->assertSame(['middleware', 'middleware'], MiddlewareNameResolver::resolve('web', [], [
            'web' => ['shared', 'shared'],
            'shared' => ['middleware'],
        ]));
    }

    public function testMiddlewareGroupPreservesZeroParameter(): void
    {
        $this->assertSame(
            ['ThrottleMiddleware:0'],
            MiddlewareNameResolver::resolve('web', ['throttle' => 'ThrottleMiddleware'], [
                'web' => ['throttle:0'],
            ])
        );
    }

    public function testMiddlewareGroupParsingUsesLateStaticBinding(): void
    {
        TrackingMiddlewareNameResolver::$parsedGroups = [];

        $this->assertSame(['middleware'], TrackingMiddlewareNameResolver::resolve('web', [], [
            'web' => ['shared'],
            'shared' => ['middleware'],
        ]));
        $this->assertSame(['web', 'shared'], TrackingMiddlewareNameResolver::$parsedGroups);
    }
}

class TrackingMiddlewareNameResolver extends MiddlewareNameResolver
{
    public static array $parsedGroups = [];

    protected static function parseMiddlewareGroup(string $name, array $map, array $middlewareGroups): array
    {
        static::$parsedGroups[] = $name;

        return parent::parseMiddlewareGroup($name, $map, $middlewareGroups);
    }
}
