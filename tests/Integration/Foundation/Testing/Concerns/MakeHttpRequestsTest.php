<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Foundation\Testing\Concerns;

use Hypervel\Http\Request;
use Hypervel\Support\Uri;
use Hypervel\Testbench\Attributes\WithConfig;
use Hypervel\Testbench\TestCase;

#[WithConfig('app.key', 'base64:IUHRqAQ99pZ0A1MPjbuv1D6ff3jxv0GIvS2qIW4JNU4=')]
class MakeHttpRequestsTest extends TestCase
{
    protected function defineWebRoutes($router): void
    {
        $router->get('decode', fn (Request $request) => [
            'url' => $request->fullUrl(),
            'query' => $request->query(),
        ]);
    }

    public function testItCanUseUriToMakeRequest()
    {
        $this->getJson(Uri::of('decode')->withQuery(['editing' => true, 'editMode' => 'create', 'search' => 'Hypervel']))
            ->assertSuccessful()
            ->assertJson([
                'url' => 'http://localhost/decode?editMode=create&editing=1&search=Hypervel',
                'query' => [
                    'editing' => '1',
                    'editMode' => 'create',
                    'search' => 'Hypervel',
                ],
            ]);
    }
}
