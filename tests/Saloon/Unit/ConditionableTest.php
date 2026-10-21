<?php

declare(strict_types=1);

namespace Hypervel\Tests\Saloon\Unit;

use Hypervel\Tests\Saloon\Fixtures\Requests\UserRequest;
use Hypervel\Tests\TestCase;

class ConditionableTest extends TestCase
{
    public function testYouCanUseTheWhenMethodToInvokeACallbackWhenAGivenConditionIsTruthy(): void
    {
        $request = new UserRequest;

        $request->when(true, function (UserRequest $request): void {
            $request->withHeader('X-Name', 'Sam');
        });

        $request->when(false, function (UserRequest $request): void {
            $request->withHeader('X-Name', 'Alex');
        });

        $this->assertSame(['X-Name' => 'Sam'], $request->headers());
    }

    public function testYouCanUseTheUnlessMethodToInvokeACallbackWhenAGivenConditionIsFalsy(): void
    {
        $request = new UserRequest;

        $request->unless(true, function (UserRequest $request): void {
            $request->withHeader('X-Name', 'Sam');
        });

        $request->unless(false, function (UserRequest $request): void {
            $request->withHeader('X-Name', 'Alex');
        });

        $this->assertSame(['X-Name' => 'Alex'], $request->headers());
    }

    public function testYouCanProvideACallbackAsTheValueOfTheWhenCondition(): void
    {
        $request = new UserRequest;

        $request->when(
            fn (): bool => true,
            function (UserRequest $request): void {
                $request->withHeader('X-Name', 'Sam');
            }
        );

        $this->assertSame(['X-Name' => 'Sam'], $request->headers());
    }

    public function testYouCanProvideACallbackAsTheValueOfTheUnlessCondition(): void
    {
        $request = new UserRequest;

        $request->unless(
            fn (): bool => false,
            function (UserRequest $request): void {
                $request->withHeader('X-Name', 'Alex');
            }
        );

        $this->assertSame(['X-Name' => 'Alex'], $request->headers());
    }

    public function testYouCanProvideACallbackAsTheDefaultValueOfTheWhenCondition(): void
    {
        $request = new UserRequest;

        $request->when(
            false,
            function (UserRequest $request): void {
                $request->withHeader('X-Name', 'Sam');
            },
            function (UserRequest $request): void {
                $request->withHeader('X-Name', 'Alex');
            }
        );

        $this->assertSame(['X-Name' => 'Alex'], $request->headers());
    }

    public function testYouCanProvideACallbackAsTheDefaultValueOfTheUnlessCondition(): void
    {
        $request = new UserRequest;

        $request->unless(
            true,
            function (UserRequest $request): void {
                $request->withHeader('X-Name', 'Sam');
            },
            function (UserRequest $request): void {
                $request->withHeader('X-Name', 'Alex');
            }
        );

        $this->assertSame(['X-Name' => 'Alex'], $request->headers());
    }

    public function testItWillPassTheConditionValueAsTheSecondArgumentOfTheCallable(): void
    {
        $request = new UserRequest;
        $values = [];

        // Upstream asserts inside the callbacks, which would also pass if they never ran.
        $request->when(true, function (UserRequest $request, mixed $value) use (&$values): void {
            $values[] = $value;
        });

        $request->unless(false, function (UserRequest $request, mixed $value) use (&$values): void {
            $values[] = $value;
        });

        $this->assertSame([true, false], $values);
    }

    public function testItWillPassTheConditionValueAsTheSecondArgumentOfTheDefaultCallable(): void
    {
        $request = new UserRequest;
        $values = [];

        $request->when(
            false,
            function (UserRequest $request, mixed $value): void {
            },
            function (UserRequest $request, mixed $value) use (&$values): void {
                $values[] = $value;
            }
        );

        $request->unless(
            true,
            function (UserRequest $request, mixed $value): void {
            },
            function (UserRequest $request, mixed $value) use (&$values): void {
                $values[] = $value;
            }
        );

        $this->assertSame([false, true], $values);
    }
}
