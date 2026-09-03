<?php

declare(strict_types=1);

namespace Hypervel\Tests\Data\Inertia\InertiaIntegrationTest;

use Hypervel\Contracts\Foundation\Application;
use Hypervel\Data\Attributes\AutoInertiaDeferred;
use Hypervel\Data\Attributes\AutoInertiaLazy;
use Hypervel\Data\Data;
use Hypervel\Data\DataServiceProvider;
use Hypervel\Data\Lazy;
use Hypervel\Http\JsonResponse;
use Hypervel\Http\Request;
use Hypervel\Inertia\Inertia;
use Hypervel\Inertia\InertiaServiceProvider;
use Hypervel\Testbench\TestCase;

use function Hypervel\Coroutine\parallel;

class InertiaIntegrationTest extends TestCase
{
    /**
     * Get package providers for the Inertia integration test application.
     */
    protected function getPackageProviders(Application $app): array
    {
        return [
            DataServiceProvider::class,
            InertiaServiceProvider::class,
        ];
    }

    public function testAutomaticInertiaPropertiesFollowInitialAndPartialResolution(): void
    {
        $data = InertiaPageData::from([
            'id' => '1',
            'optional' => ['id' => '2'],
            'deferred' => ['id' => '3'],
        ]);

        $initial = $this->makePage($this->makeInertiaRequest(), $data);

        $this->assertSame(['id' => 1], $initial['props']);
        $this->assertSame(['analytics' => ['deferred']], $initial['deferredProps']);

        $partial = $this->makePage(
            $this->makeInertiaRequest('optional,deferred'),
            $data,
        );

        $this->assertSame([
            'optional' => ['id' => 2],
            'deferred' => ['id' => 3],
        ], $partial['props']);
        $this->assertArrayNotHasKey('deferredProps', $partial);
    }

    public function testAutomaticInertiaPropertiesAreIsolatedBetweenCoroutines(): void
    {
        [$first, $second] = parallel([
            function (): int {
                $data = InertiaPageData::from([
                    'id' => '1',
                    'optional' => ['id' => '11'],
                    'deferred' => ['id' => '12'],
                ]);

                usleep(5000);

                return $this->makePage(
                    $this->makeInertiaRequest('optional'),
                    $data,
                )['props']['optional']['id'];
            },
            function (): int {
                $data = InertiaPageData::from([
                    'id' => '2',
                    'optional' => ['id' => '21'],
                    'deferred' => ['id' => '22'],
                ]);

                usleep(1000);

                return $this->makePage(
                    $this->makeInertiaRequest('optional'),
                    $data,
                )['props']['optional']['id'];
            },
        ]);

        $this->assertSame(11, $first);
        $this->assertSame(21, $second);
    }

    /**
     * Resolve Data through an Inertia response.
     *
     * @return array<string, mixed>
     */
    protected function makePage(Request $request, InertiaPageData $data): array
    {
        $response = Inertia::render('TestComponent', $data)->toResponse($request);

        $this->assertInstanceOf(JsonResponse::class, $response);

        return $response->getData(true);
    }

    /**
     * Create an Inertia request, optionally selecting partial props.
     */
    protected function makeInertiaRequest(?string $only = null): Request
    {
        $request = Request::create('/');
        $request->headers->add(['X-Inertia' => 'true']);

        if ($only !== null) {
            $request->headers->add(['X-Inertia-Partial-Component' => 'TestComponent']);
            $request->headers->add(['X-Inertia-Partial-Data' => $only]);
        }

        return $request;
    }
}

class InertiaPageData extends Data
{
    public function __construct(
        public int $id,
        #[AutoInertiaLazy]
        public Lazy|InertiaChildData $optional,
        #[AutoInertiaDeferred('analytics', rescue: true)]
        public Lazy|InertiaChildData $deferred,
    ) {
    }
}

class InertiaChildData extends Data
{
    public function __construct(
        public int $id,
    ) {
    }
}
