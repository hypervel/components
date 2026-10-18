<?php

declare(strict_types=1);

namespace Hypervel\Tests\Http\Middleware;

use Hypervel\Http\Middleware\FrameGuard;
use Hypervel\Http\Request;
use Hypervel\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Response;

class FrameGuardTest extends TestCase
{
    #[DataProvider('frameOptions')]
    public function testSetsTheDefaultWithoutDuplicatingOrReplacingAnExistingPolicy(?string $existing, string $expected): void
    {
        $request = Request::create('/');
        $response = new Response;

        if ($existing !== null) {
            $response->headers->set('X-Frame-Options', $existing);
        }

        $middleware = new FrameGuard;
        $result = $middleware->handle($request, fn (Request $request): Response => $middleware->handle(
            $request,
            fn (): Response => $response,
        ));

        $this->assertSame($response, $result);
        $this->assertSame([$expected], $result->headers->all('X-Frame-Options'));
    }

    /**
     * Provide responses with and without an application-defined policy.
     */
    public static function frameOptions(): iterable
    {
        yield 'default' => [null, 'SAMEORIGIN'];
        yield 'application policy' => ['DENY', 'DENY'];
    }
}
