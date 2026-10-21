<?php

declare(strict_types=1);

namespace Hypervel\Tests\Head\Feature;

use Hypervel\Contracts\Foundation\Application;
use Hypervel\Head\Facades\Head;
use Hypervel\Head\HeadManager;
use Hypervel\Head\HeadServiceProvider;
use Hypervel\Inertia\Inertia;
use Hypervel\Inertia\InertiaServiceProvider;
use Hypervel\Inertia\Response;
use Hypervel\Inertia\Support\Header;
use Hypervel\Support\Facades\Route;
use Hypervel\Tests\Head\TestCase;

class InertiaCustomPropTest extends TestCase
{
    /**
     * Register an application subclass of the Inertia provider.
     *
     * @return array<int, class-string>
     */
    protected function getPackageProviders(Application $app): array
    {
        return [HeadCustomInertiaServiceProvider::class, HeadServiceProvider::class];
    }

    /**
     * Configure a custom shared head prop before providers boot.
     */
    protected function defineEnvironment(Application $app): void
    {
        parent::defineEnvironment($app);

        $app->booting(fn (): HeadManager => Head::inertia(prop: '_head'));
    }

    public function testHeadElementsAreSharedUnderTheConfiguredProp(): void
    {
        Route::get('/dashboard', fn (): Response => Inertia::render('Dashboard', [
            'head' => 'An unrelated app prop',
        ]))->withHead(title: 'Dashboard');

        $this->get('/dashboard', [Header::INERTIA => 'true'])
            ->assertOk()
            ->assertJsonPath('props.head', 'An unrelated app prop')
            ->assertJsonPath('props._head.0', '<title data-inertia="title">Dashboard</title>');
    }
}

class HeadCustomInertiaServiceProvider extends InertiaServiceProvider
{
}
