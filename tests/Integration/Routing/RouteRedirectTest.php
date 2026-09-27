<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Routing;

use Hypervel\Foundation\Auth\User;
use Hypervel\Http\RedirectResponse;
use Hypervel\Routing\Middleware\SubstituteBindings;
use Hypervel\Support\Facades\Route;
use Hypervel\Tests\Routing\Fixtures\ApiResourceTestController;
use PHPUnit\Framework\Attributes\DataProvider;

class RouteRedirectTest extends RoutingTestCase
{
    #[DataProvider('routeRedirectDataSets')]
    public function testRouteRedirect(string $redirectFrom, string $redirectTo, string $requestUri, string $redirectUri): void
    {
        $this->withoutExceptionHandling();
        Route::redirect($redirectFrom, $redirectTo, 301);

        $response = $this->get($requestUri);
        $response->assertRedirect($redirectUri);
        $response->assertStatus(301);
    }

    /**
     * Provide redirect route parameters and expected URLs.
     */
    public static function routeRedirectDataSets(): array
    {
        return [
            'route redirect with no parameters' => ['from', 'to', '/from', '/to'],
            'route redirect with one parameter' => ['from/{param}/{param2?}', 'to', '/from/value1', '/to'],
            'route redirect with two parameters' => ['from/{param}/{param2?}', 'to', '/from/value1/value2', '/to'],
            'route redirect with one parameter replacement' => ['users/{user}/repos', 'members/{user}/repos', '/users/22/repos', '/members/22/repos'],
            'route redirect with two parameter replacements' => ['users/{user}/repos/{repo}', 'members/{user}/projects/{repo}', '/users/22/repos/hypervel-framework', '/members/22/projects/hypervel-framework'],
            'route redirect with non existent optional parameter replacements' => ['users/{user?}', 'members/{user?}', '/users', '/members'],
            'route redirect with existing parameter replacements' => ['users/{user?}', 'members/{user?}', '/users/22', '/members/22'],
            'route redirect with two optional replacements' => ['users/{user?}/{repo?}', 'members/{user?}', '/users/22', '/members/22'],
            'route redirect with two optional replacements that switch position' => ['users/{user?}/{switch?}', 'members/{switch?}/{user?}', '/users/11/22', '/members/22/11'],
        ];
    }

    public function testRouteRedirectWithExplicitRouteModelBinding(): void
    {
        $this->withoutExceptionHandling();
        Route::middleware([SubstituteBindings::class])->group(function (): void {
            Route::redirect('users/{user}', 'users/{user}/overview');
        });
        Route::bind('user', fn (string $id): User => (new User)->setAttribute('id', '999'));

        $response = $this->get('users/1');

        $response->assertRedirect('users/999/overview');
    }

    public function testToActionHelper(): void
    {
        Route::get('to', [ApiResourceTestController::class, 'index']);

        Route::get('from-301', function (): RedirectResponse {
            return to_action([ApiResourceTestController::class, 'index'], [], 301);
        });

        Route::get('from-302', function (): RedirectResponse {
            return to_action([ApiResourceTestController::class, 'index']);
        });

        $this->get('from-301')
            ->assertRedirect('to')
            ->assertStatus(301)
            ->assertSee('Redirecting to');

        $this->get('from-302')
            ->assertRedirect('to')
            ->assertStatus(302)
            ->assertSee('Redirecting to');
    }

    public function testToRouteHelper(): void
    {
        Route::get('to', function (): void {
            // ..
        })->name('to');

        Route::get('from-301', function (): RedirectResponse {
            return to_route('to', [], 301);
        });

        Route::get('from-302', function (): RedirectResponse {
            return to_route('to');
        });

        $this->get('from-301')
            ->assertRedirect('to')
            ->assertStatus(301)
            ->assertSee('Redirecting to');

        $this->get('from-302')
            ->assertRedirect('to')
            ->assertStatus(302)
            ->assertSee('Redirecting to');
    }
}
