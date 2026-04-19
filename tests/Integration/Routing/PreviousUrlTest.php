<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Routing\PreviousUrlTest;

use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Foundation\Http\FormRequest;
use Hypervel\Session\SessionServiceProvider;
use Hypervel\Support\Facades\Route;
use Hypervel\Tests\Integration\Routing\RoutingTestCase;

class PreviousUrlTest extends RoutingTestCase
{
    public function testPreviousUrlWithoutSession()
    {
        Route::post('/previous-url', function (DummyFormRequest $request) {
            return 'OK';
        });

        $response = $this->postJson('/previous-url');

        $this->assertEquals(422, $response->status());
    }

    protected function getApplicationProviders(ApplicationContract $app): array
    {
        $providers = parent::getApplicationProviders($app);

        return array_filter($providers, function ($provider) {
            return $provider !== SessionServiceProvider::class;
        });
    }
}

class DummyFormRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'foo' => [
                'required',
                'string',
            ],
        ];
    }
}
