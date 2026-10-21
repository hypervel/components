<?php

declare(strict_types=1);

namespace Hypervel\Tests\Context;

use Hypervel\Context\RequestContext;
use Hypervel\Coroutine\Coroutine;
use Hypervel\Http\Request;
use Hypervel\Tests\TestCase;

use function Hypervel\Coroutine\parallel;

class RequestContextTest extends TestCase
{
    public function testValuesFollowRequestReplacementAndForgetting(): void
    {
        RequestContext::setValue('value', 'without-request');
        $request = Request::create('/first');
        RequestContext::set($request);
        $this->assertNull(RequestContext::getValue('value'));

        $calls = 0;
        $resolve = static function () use (&$calls): int {
            return ++$calls;
        };

        $this->assertSame(1, RequestContext::getOrSetValue(RequestMemoKey::Value, $resolve));
        RequestContext::set($request);
        $this->assertSame(1, RequestContext::getOrSetValue('value', $resolve));
        RequestContext::forgetValue(RequestMemoKey::Value);
        $this->assertSame(2, RequestContext::getOrSetValue('value', $resolve));

        RequestContext::set(Request::create('/second'));
        $this->assertSame('missing', RequestContext::getValue('value', 'missing'));
        RequestContext::setValue('value', false);
        $this->assertFalse(RequestContext::getOrSetValue('value', $resolve));
        RequestContext::forget();
        $this->assertNull(RequestContext::getValue('value'));
        $this->assertFalse(RequestContext::has());
    }

    public function testCopiedContextsKeepWritesAndRequestResetsIsolated(): void
    {
        $request = Request::create('/parent');
        RequestContext::set($request);
        RequestContext::setValue('value', 'parent');

        $results = parallel([
            static function (): array {
                $inherited = RequestContext::getValue('value');
                RequestContext::setValue('value', 'first');
                usleep(5000);

                return [$inherited, RequestContext::getValue('value')];
            },
            static function (): array {
                $inherited = RequestContext::getValue('value');
                RequestContext::set(Request::create('/child'));
                usleep(5000);

                return [$inherited, RequestContext::getValue('value')];
            },
        ], copyContext: true);

        $this->assertSame([['parent', 'first'], ['parent', null]], $results);
        $this->assertSame($request, RequestContext::get());
        $this->assertSame('parent', RequestContext::getValue('value'));
    }

    public function testExplicitCoroutineTargetsApplyToValuesAndRequestResets(): void
    {
        $parentId = Coroutine::id();
        RequestContext::set(Request::create('/parent'));
        RequestContext::setValue('value', 'parent');

        parallel([function () use ($parentId): void {
            RequestContext::set(Request::create('/child'));
            RequestContext::setValue('value', 'child');
            $this->assertSame('parent', RequestContext::getValue('value', coroutineId: $parentId));

            RequestContext::set(Request::create('/replacement'), $parentId);
            $this->assertNull(RequestContext::getValue('value', coroutineId: $parentId));
            RequestContext::setValue('value', 'replacement', $parentId);
            RequestContext::forgetValue('value', $parentId);
            $this->assertSame('resolved', RequestContext::getOrSetValue('value', 'resolved', $parentId));
            RequestContext::forget($parentId);
            $this->assertSame('child', RequestContext::getValue('value'));
        }]);

        $this->assertFalse(RequestContext::has());
        $this->assertNull(RequestContext::getValue('value'));
    }
}

enum RequestMemoKey: string
{
    case Value = 'value';
}
