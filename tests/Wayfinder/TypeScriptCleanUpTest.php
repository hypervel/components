<?php

declare(strict_types=1);

namespace Hypervel\Tests\Wayfinder;

use Hypervel\Tests\TestCase;
use Hypervel\Wayfinder\TypeScript;

class TypeScriptCleanUpTest extends TestCase
{
    public function testMultiLineArgumentsWithParenthesesAreCollapsed(): void
    {
        $view = implode(PHP_EOL, [
            'export const show = (',
            "    args: { project: import('@/types').Key<'Project', string> } | [project: string],",
            '    options?: RouteQueryOptions,',
            "): RouteDefinition<'get'> => ({",
            '    url: show.url(args, options),',
            "    method: 'get',",
            '})',
        ]);

        $this->assertSame(implode(PHP_EOL, [
            "export const show = (args: { project: import('@/types').Key<'Project', string> } | [project: string], options?: RouteQueryOptions,): RouteDefinition<'get'> => ({",
            '    url: show.url(args, options),',
            "    method: 'get',",
            '})',
        ]), TypeScript::cleanUp($view));
    }
}
