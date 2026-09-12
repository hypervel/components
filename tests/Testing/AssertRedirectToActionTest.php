<?php

declare(strict_types=1);

namespace Hypervel\Tests\Testing;

use Hypervel\Contracts\Routing\Registrar;
use Hypervel\Http\RedirectResponse;
use Hypervel\Routing\Controller;
use Hypervel\Routing\UrlGenerator;
use Hypervel\Testbench\TestCase;

class AssertRedirectToActionTest extends TestCase
{
    public UrlGenerator $urlGenerator;

    /**
     * Set up the test environment.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $router = $this->app->make(Registrar::class);

        $router->get('controller/index', [TestActionController::class, 'index']);
        $router->get('controller/show/{id}', [TestActionController::class, 'show']);

        $router->get('redirect-to-index', function () {
            return new RedirectResponse($this->urlGenerator->action([TestActionController::class, 'index']));
        });

        $router->get('redirect-to-show', function () {
            return new RedirectResponse($this->urlGenerator->action([TestActionController::class, 'show'], ['id' => 123]));
        });

        $this->urlGenerator = $this->app->make(UrlGenerator::class);
    }

    public function testAssertRedirectToActionWithoutParameters(): void
    {
        $this->get('redirect-to-index')
            ->assertRedirectToAction([TestActionController::class, 'index']);
    }

    public function testAssertRedirectToActionWithParameters(): void
    {
        $this->get('redirect-to-show')
            ->assertRedirectToAction([TestActionController::class, 'show'], ['id' => 123]);
    }
}

class TestActionController extends Controller
{
    /**
     * Return the index response.
     */
    public function index(): string
    {
        return 'ok';
    }

    /**
     * Return the response for the requested identifier.
     */
    public function show(string $id): string
    {
        return "id: {$id}";
    }
}
