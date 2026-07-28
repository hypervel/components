<?php

declare(strict_types=1);

namespace Hypervel\Tests\Queue;

use Hypervel\Queue\Failed\DatabaseFailedJobProvider;
use Hypervel\Queue\Failed\DatabaseUuidFailedJobProvider;
use Hypervel\Queue\Failed\FileFailedJobProvider;
use Hypervel\Queue\Failed\NullFailedJobProvider;
use Hypervel\Testbench\TestCase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionProperty;

class QueueServiceProviderTest extends TestCase
{
    #[DataProvider('failedJobProviders')]
    public function testFailedJobProviderIsSelectedExplicitly(mixed $driver, string $provider): void
    {
        $this->app->make('config')->set('queue.failed.driver', $driver);
        $this->app->forgetInstance('queue.failer');

        $this->assertInstanceOf($provider, $this->app->make('queue.failer'));
    }

    public static function failedJobProviders(): array
    {
        return [
            'null value' => [null, NullFailedJobProvider::class],
            'null driver' => ['null', NullFailedJobProvider::class],
            'file driver' => ['file', FileFailedJobProvider::class],
            'database driver' => ['database', DatabaseFailedJobProvider::class],
            'database UUID driver' => ['database-uuids', DatabaseUuidFailedJobProvider::class],
        ];
    }

    public function testUnsupportedFailedJobProviderIsRejected(): void
    {
        $this->app->make('config')->set('queue.failed.driver', 'unsupported');
        $this->app->forgetInstance('queue.failer');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported failed job provider [unsupported].');

        $this->app->make('queue.failer');
    }

    public function testFileFailedJobProviderUsesItsDefaultsWhenOptionalConfigIsOmitted(): void
    {
        $this->app->make('config')->set('queue.failed', ['driver' => 'file']);
        $this->app->forgetInstance('queue.failer');

        $provider = $this->app->make('queue.failer');

        $this->assertSame(
            $this->app->storagePath('framework/cache/failed-jobs.json'),
            (new ReflectionProperty(FileFailedJobProvider::class, 'path'))->getValue($provider),
        );
        $this->assertSame(
            100,
            (new ReflectionProperty(FileFailedJobProvider::class, 'limit'))->getValue($provider),
        );
    }
}
