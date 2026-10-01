<?php

declare(strict_types=1);

namespace Hypervel\Tests\Workbench\Integrations;

use Hypervel\Foundation\Testing\RefreshDatabase;
use Hypervel\Support\Facades\File;
use Hypervel\Testbench\Concerns\InteractsWithPublishedFiles;
use PHPUnit\Framework\Attributes\Depends;
use PHPUnit\Framework\Attributes\Test;
use Workbench\Database\Factories\UserFactory;

use function Hypervel\Filesystem\join_paths;

class WorkbenchTest extends TestCase
{
    use InteractsWithPublishedFiles;
    use RefreshDatabase;

    protected array $files = [
        'resources/views/dashboard.blade.php',
    ];

    #[Test]
    public function itCanDisplayTheDefaultDashboard(): void
    {
        $user = UserFactory::new()->create();

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertSee('You\'re logged in!');
    }

    #[Test]
    #[Depends('itCanDisplayTheDefaultDashboard')]
    public function itCanOverrideTheConfiguredViews(): void
    {
        File::put(resource_path(join_paths('views', 'dashboard.blade.php')), 'Hello World');

        $user = UserFactory::new()->create();

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertDontSeeText('You\'re logged in!')
            ->assertSee('Hello World');
    }
}
