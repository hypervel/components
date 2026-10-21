<?php

declare(strict_types=1);

namespace Hypervel\Tests\Data\RequestTest;

use Hypervel\Contracts\Foundation\Application;
use Hypervel\Data\Attributes\WithoutValidation;
use Hypervel\Data\Data;
use Hypervel\Data\DataServiceProvider;
use Hypervel\Http\JsonResponse;
use Hypervel\Http\Request;
use Hypervel\Support\Facades\Route;
use Hypervel\Testbench\TestCase;
use Hypervel\Tests\Data\Fixtures\SimpleData;
use Hypervel\Tests\Data\Fixtures\SimpleDataWithExplicitValidationRuleAttributeData;
use Hypervel\Validation\ValidationException;

class RequestTest extends TestCase
{
    /**
     * Get package providers for the request test application.
     */
    protected function getPackageProviders(Application $app): array
    {
        return [DataServiceProvider::class];
    }

    /**
     * Register the route shared by the request tests.
     */
    protected function setUp(): void
    {
        parent::setUp();

        Route::post('/example-route', function (SimpleData $data): array {
            return ['given' => $data->string];
        });
    }

    public function testCanPassValidation(): void
    {
        Route::post('/example-route', function (SimpleData $data): array {
            return ['given' => $data->string];
        });

        $this->postJson('/example-route', [
            'string' => 'Hello',
        ])
            ->assertOk()
            ->assertJson(['given' => 'Hello']);
    }

    public function testCanReturnsA201ResponseCodeForPostRequests(): void
    {
        Route::post('/example-route', function (): SimpleData {
            return new SimpleData(request()->input('string'));
        });

        // Responses use 200 for every method; Spatie returns 201 for every POST (README).
        $this->postJson('/example-route', [
            'string' => 'Hello',
        ])
            ->assertOk()
            ->assertJson(['string' => 'Hello']);
    }

    public function testIsPossibleToOverwriteTheStatusResponseCode(): void
    {
        Route::post('/example-route', function (): SimpleData {
            // withResponse() replaces Spatie's calculateResponseStatus() override (README).
            return new class(request()->input('string')) extends SimpleData {
                /**
                 * Customize the outgoing response status.
                 */
                public function withResponse(Request $request, JsonResponse $response): void
                {
                    $response->setStatusCode(301);
                }
            };
        });

        $this->postJson('/example-route', [
            'string' => 'Hello',
        ])
            ->assertStatus(301)
            ->assertJson(['string' => 'Hello']);
    }

    public function testCanFailValidation(): void
    {
        Route::post('/example-route', function (SimpleDataWithExplicitValidationRuleAttributeData $data): array {
            return ['email' => $data->email];
        });

        $this->postJson('/example-route', [
            'email' => 'Hello',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors([
                'email' => __('validation.email', ['attribute' => 'email']),
            ]);
    }

    public function testAlwaysValidatesRequestsWhenPassedToTheFromMethod(): void
    {
        try {
            SimpleData::from(new Request);
        } catch (ValidationException $exception) {
            $this->assertSame([
                'string' => [__('validation.required', ['attribute' => 'string'])],
            ], $exception->errors());

            return;
        }

        $this->fail('We should not end up here');
    }

    public function testCanCheckForAuthorization(): void
    {
        Route::post('/example-route', function (TestDataWithAuthorizationFailure $data): void {
        });

        $this->postJson('/example-route', [
            'string' => 'test',
        ])->assertStatus(403);
    }

    public function testCanManuallyOverrideHowTheDataObjectWillBeConstructed(): void
    {
        Route::post('/other-route', function (TestOverrideableDataFromRequest $data): array {
            return ['name' => $data->name];
        });

        $this->postJson('/other-route', [
            'first_name' => 'Rick',
            'last_name' => 'Astley',
        ])
            ->assertOk()
            ->assertJson(['name' => 'Rick Astley']);
    }

    public function testCanBuildAuthorizeParametersFromTheContainer(): void
    {
        $this->app->bind(SomeDependency::class, fn (): SomeDependency => new SomeDependency('Sesame'));

        Route::post('/route-with-authorization-dependencies', function (AuthorizeFromContainerRequest $data): array {
            return ['name' => $data->name, 'street' => AuthorizeFromContainerRequest::$street];
        });

        $this->postJson('/route-with-authorization-dependencies', [
            'name' => 'Rick Astley',
        ])
            ->assertOk()
            ->assertJson(['name' => 'Rick Astley', 'street' => 'Sesame']);
    }
}

class TestDataWithAuthorizationFailure extends Data
{
    public string $string;

    /**
     * Deny every request.
     */
    public static function authorize(): bool
    {
        return false;
    }
}

class TestOverrideableDataFromRequest extends Data
{
    /**
     * Create the data object from a full name.
     */
    public function __construct(
        #[WithoutValidation]
        public string $name
    ) {
    }

    /**
     * Create the data object from separate name fields.
     */
    public static function fromRequest(Request $request): static
    {
        return new static("{$request->input('first_name')} {$request->input('last_name')}");
    }
}

class SomeDependency
{
    /**
     * Create the dependency.
     */
    public function __construct(public string $street)
    {
    }
}

class AuthorizeFromContainerRequest extends Data
{
    public static string $street;

    /**
     * Create the data object.
     */
    public function __construct(public string $name)
    {
    }

    /**
     * Authorize the request with a container-resolved dependency.
     */
    public static function authorize(SomeDependency $dependency): bool
    {
        self::$street = $dependency->street;

        return $dependency->street === 'Sesame';
    }
}
