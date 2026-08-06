<?php

declare(strict_types=1);

namespace Hypervel\Tests\Testbench\Workbench;

use Composer\InstalledVersions;
use Hypervel\Database\Eloquent\Factories\Factory;
use Hypervel\Foundation\Events\DiagnosingHealth;
use Hypervel\Foundation\Testing\Concerns\InteractsWithViews;
use Hypervel\Support\CarbonImmutable;
use Hypervel\Support\Facades\Event;
use Hypervel\Testbench\Attributes\WithConfig;
use Hypervel\Testbench\Concerns\WithWorkbench;
use Hypervel\Testbench\TestCase;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestWith;
use RuntimeException;

use function Hypervel\Testbench\package_version_compare;

#[WithConfig('app.key', 'AckfSECXIvnK5r28GVIWUAxmbBSjTsmF')]
class DiscoversTest extends TestCase
{
    use InteractsWithViews;
    use WithWorkbench;

    #[Test]
    public function itCanResolveWebRoutesFromDiscovers()
    {
        $this->get('/api/hello')
            ->assertOk();
    }

    #[Test]
    public function itCanResolveWebRoutesUsingMacroFromDiscovers()
    {
        $contentType = package_version_compare('symfony/http-foundation', '7.4.0', '>=')
            ? 'text/plain; charset=utf-8'
            : 'text/plain; charset=UTF-8';

        $this->get('/hello-world')
            ->assertOk()
            ->assertSee('Hello world')
            ->assertHeader('Content-Type', $contentType);
    }

    #[Test]
    public function itCanResolveHealthCheckFromDiscovers(): void
    {
        CarbonImmutable::setTestNow('2026-08-06 12:00:00 UTC');

        $this->call('GET', '/up', server: [
            'REQUEST_TIME_FLOAT' => CarbonImmutable::now()->subSeconds(5)->getPreciseTimestamp(6) / 1_000_000,
        ])
            ->assertOk()
            ->assertSee('HTTP request received')
            ->assertSee('Response rendered in 5000ms.');
    }

    #[Test]
    #[WithConfig('app.debug', false)]
    public function itRendersHealthCheckFailureFromDiscoversWhenExceptionMessageIsEmpty(): void
    {
        Event::listen(DiagnosingHealth::class, static function (): never {
            throw new RuntimeException('');
        });

        $this->get('/up')
            ->assertStatus(500)
            ->assertSee('status-down')
            ->assertSee('experiencing problems')
            ->assertDontSee('Application up');
    }

    #[Test]
    public function itCanResolveViewsFromDiscovers()
    {
        $this->get('/testbench')
            ->assertOk()
            ->assertSee('Alert Component')
            ->assertSee('Notification Component');
    }

    #[Test]
    public function itCanResolveErrorsViewsFromDiscovers()
    {
        $this->get('/root')
            ->assertStatus(418)
            ->assertSeeText('I\'m a teapot')
            ->assertDontSeeText('412');
    }

    #[Test]
    public function itCanResolveRouteNameFromDiscovers()
    {
        $this->assertSame(url('/testbench'), route('testbench'));
    }

    #[Test]
    public function itCanResolveCommandsFromDiscovers()
    {
        $this->artisan('workbench:inspire')->assertOk();
    }

    #[Test]
    public function itCanDiscoverConfigFiles()
    {
        $this->assertSame(InstalledVersions::isInstalled('hypervel/components'), config('workbench.installed'));

        $this->assertSame(InstalledVersions::isInstalled('hypervel/components'), config('nested.workbench.installed'));
    }

    #[Test]
    public function itCanDiscoverViewsFiles()
    {
        $this->view('workbench::testbench')
            ->assertSee('Alert Component')
            ->assertSee('Notification Component');

        $this->view('testbench')
            ->assertSee('Alert Component')
            ->assertSee('Notification Component');
    }

    #[Test]
    public function itCanDiscoverTranslationFiles()
    {
        $this->assertSame('Good Morning', __('workbench::welcome.morning'));
    }

    #[Test]
    #[TestWith(['Workbench\Database\Factories\Hypervel\Foundation\Auh\UserFactory', 'Hypervel\Foundation\Auh\User'])]
    #[TestWith(['Workbench\Database\Factories\UserFactory', 'Workbench\App\Models\User'])]
    public function itCanDiscoverDatabaseFactoriesFromModel(string $factory, string $model)
    {
        $this->assertSame($factory, Factory::resolveFactoryName($model));
    }

    #[Test]
    public function itCanDiscoverModelFromFactory()
    {
        $this->assertSame('Workbench\App\Models\User', \Workbench\Database\Factories\UserFactory::new()->modelName());
    }
}
