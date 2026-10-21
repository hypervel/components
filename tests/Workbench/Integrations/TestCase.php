<?php

declare(strict_types=1);

namespace Hypervel\Tests\Workbench\Integrations;

use Hypervel\Database\Schema\Blueprint;
use Hypervel\Support\Facades\Schema;
use Hypervel\Testbench\Attributes\WithConfig;
use Hypervel\Testbench\Attributes\WithMigration;
use Hypervel\Testbench\Concerns\WithWorkbench;
use Hypervel\Testbench\Contracts\Config as ConfigContract;
use Hypervel\Testbench\Foundation\Config;
use Hypervel\Testbench\TestCase as BaseTestCase;
use Hypervel\Testbench\Workbench\Workbench;
use Hypervel\Tests\Workbench\Fixtures\Member;
use Override;

#[WithConfig('app.key', 'AckfSECXIvnK5r28GVIWUAxmbBSjTsmF')]
#[WithConfig('database.default', 'testing')]
#[WithMigration]
abstract class TestCase extends BaseTestCase
{
    use WithWorkbench;

    /**
     * Get the cached Workbench configuration with authentication enabled.
     *
     * The shared testbench.yaml leaves Workbench authentication disabled for
     * every other Testbench test.
     */
    public static function cachedConfigurationForWorkbench(): ConfigContract
    {
        $config = Workbench::configuration();

        return new Config([
            ...$config->getAttributes(),
            'workbench' => [...$config['workbench'], 'auth' => true],
        ]);
    }

    /**
     * Boot without the shared Testbench skeleton's application routes.
     *
     * Its stub dashboard route would replace the package's own, as application routes
     * do. The upstream package tests its routes against a bootstrap without routes too.
     */
    #[Override]
    protected function getApplicationBootstrapFile(string $filename): string|false
    {
        return $filename === 'app.php' ? false : parent::getApplicationBootstrapFile($filename);
    }

    /**
     * Render pages without the built Vite assets.
     */
    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    /**
     * Create the table for the custom Workbench user model.
     */
    protected function createMembersTable(): void
    {
        Schema::create('workbench_members', static function (Blueprint $table): void {
            $table->id('member_id');
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->string('member_remember_token')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Make a session guard for the custom Workbench user model the default guard.
     */
    protected function useMembersGuard(): void
    {
        $this->createMembersTable();

        config([
            'auth.defaults.guard' => 'members',
            'auth.guards.members' => ['driver' => 'session', 'provider' => 'members'],
            'auth.providers.members' => ['driver' => 'eloquent', 'model' => Member::class],
        ]);
    }
}
