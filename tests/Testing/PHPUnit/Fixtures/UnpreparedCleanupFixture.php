<?php

declare(strict_types=1);

namespace Hypervel\Tests\Testing\PHPUnit\Fixtures;

use Hypervel\Permission\PermissionRegistrar;
use Hypervel\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;

class UnpreparedCleanupFixture extends TestCase
{
    /**
     * Fail or stop selected tests after mutating framework static state.
     */
    protected function setUp(): void
    {
        parent::setUp();

        if ($this->name() === 'testSetupErrorDoesNotLeak') {
            $this->configurePermissionPartition();

            throw new RuntimeException('intentional setup error');
        }

        if ($this->name() === 'testSetupSkipDoesNotLeak') {
            $this->configurePermissionPartition();
            $this->markTestSkipped('intentional setup skip');
        }

        if ($this->name() === 'testSetupIncompleteDoesNotLeak') {
            $this->configurePermissionPartition();
            $this->markTestIncomplete('intentional setup incomplete');
        }

        if ($this->name() === 'testDataProviderSetupErrorDoesNotLeak'
            && $this->dataName() === 'failing') {
            $this->configurePermissionPartition();

            throw new RuntimeException('intentional data-provider setup error');
        }
    }

    public function testSetupErrorDoesNotLeak(): void
    {
    }

    public function testStateIsCleanAfterSetupError(): void
    {
        $this->configurePermissionPartition();
        $this->appendMarker('error');
    }

    public function testSetupSkipDoesNotLeak(): void
    {
    }

    public function testStateIsCleanAfterSetupSkip(): void
    {
        $this->configurePermissionPartition();
        $this->appendMarker('skip');
    }

    public function testSetupIncompleteDoesNotLeak(): void
    {
    }

    public function testStateIsCleanAfterSetupIncomplete(): void
    {
        $this->configurePermissionPartition();
        $this->appendMarker('incomplete');
    }

    #[DataProvider('dataProviderRows')]
    public function testDataProviderSetupErrorDoesNotLeak(string $marker): void
    {
        $this->configurePermissionPartition();
        $this->appendMarker($marker);
    }

    /**
     * Provide a failing row followed by a row that verifies clean state.
     */
    public static function dataProviderRows(): array
    {
        return [
            'failing' => ['unused'],
            'following' => ['data-provider'],
        ];
    }

    /**
     * Configure worker-lifetime state that must be reset between tests.
     */
    private function configurePermissionPartition(): void
    {
        PermissionRegistrar::resolvePartitionUsing(
            'workspace_id',
            static fn (): string => 'workspace-a',
        );
    }

    /**
     * Record a successful clean-state check for the parent process.
     */
    private function appendMarker(string $marker): void
    {
        $path = getenv('HYPERVEL_UNPREPARED_CLEANUP_MARKER');

        if (! is_string($path) || $path === '') {
            throw new RuntimeException('The unprepared-cleanup marker path is not configured.');
        }

        file_put_contents($path, $marker . PHP_EOL, FILE_APPEND);
    }
}
