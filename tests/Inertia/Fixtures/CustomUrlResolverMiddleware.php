<?php

declare(strict_types=1);

namespace Hypervel\Tests\Inertia\Fixtures;

use Closure;
use Hypervel\Http\Request;
use Hypervel\Inertia\Middleware;
use Hypervel\Routing\ResponseFactory;
use PHPUnit\Framework\Assert;

class CustomUrlResolverMiddleware extends Middleware
{
    public function urlResolver(): ?Closure
    {
        return function ($request, ResponseFactory $otherDependency) {
            Assert::assertInstanceOf(Request::class, $request);
            Assert::assertInstanceOf(ResponseFactory::class, $otherDependency);

            return '/my-custom-url';
        };
    }
}
