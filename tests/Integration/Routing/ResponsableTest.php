<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Routing\ResponsableTest;

use Hypervel\Contracts\Support\Responsable;
use Hypervel\Http\Request;
use Hypervel\Support\Facades\Route;
use Hypervel\Tests\Integration\Routing\RoutingTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * @internal
 * @coversNothing
 */
class ResponsableTest extends RoutingTestCase
{
    public function testResponsableObjectsAreRendered()
    {
        Route::get('/responsable', function () {
            return new TestResponsableResponse();
        });

        $response = $this->get('/responsable');

        $this->assertEquals(201, $response->status());
        $this->assertSame('Taylor', $response->headers->get('X-Test-Header'));
        $this->assertSame('hello world', $response->getContent());
    }
}

class TestResponsableResponse implements Responsable
{
    public function toResponse(Request $request): Response
    {
        return response('hello world', 201, ['X-Test-Header' => 'Taylor']);
    }
}
