<?php

declare(strict_types=1);

namespace Hypervel\Tests\Testbench;

use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Foundation\Application;
use Hypervel\Testbench\Concerns\CreatesApplication;
use Hypervel\Testbench\PHPUnit\TestCase;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Throwable;

use function Hypervel\Testbench\default_skeleton_path;

class CreatesApplicationTest extends TestCase
{
    use CreatesApplication;

    #[Test]
    public function itProperlyLoadsHypervelApplication(): void
    {
        $app = $this->createApplication();

        $this->assertInstanceOf(Application::class, $app);
        $this->assertTrue($app->bound('config'));
        $this->assertTrue($app->bound('view'));
    }

    #[Test]
    public function itTerminatesAndFlushesAPartialApplicationWhilePreservingTheBootstrapFailure(): void
    {
        $bootstrapFailure = new RuntimeException('bootstrap failed');
        $terminationFailure = new RuntimeException('termination failed');
        $testCase = new FailingCreatesApplicationTestCaseFixture('testPlaceholder');
        $testCase->failDuringEnvironmentDefinition($bootstrapFailure, $terminationFailure);

        try {
            $testCase->createApplication();
            $this->fail('Expected application bootstrap to fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame($bootstrapFailure, $exception);
        }

        $this->assertSame(
            ['terminate', 'flush'],
            $testCase->createdApplication?->lifecycle,
        );
    }
}

class FailingCreatesApplicationTestCaseFixture extends \Hypervel\Testbench\TestCase
{
    public ?CreatesApplicationTrackingApplication $createdApplication = null;

    protected ?Throwable $bootstrapFailure = null;

    protected ?Throwable $terminationFailure = null;

    public function testPlaceholder(): void
    {
    }

    public function failDuringEnvironmentDefinition(Throwable $bootstrapFailure, Throwable $terminationFailure): void
    {
        $this->bootstrapFailure = $bootstrapFailure;
        $this->terminationFailure = $terminationFailure;
    }

    protected function resolveApplication(): ApplicationContract
    {
        return $this->createdApplication = new CreatesApplicationTrackingApplication(
            (string) default_skeleton_path(),
            $this->terminationFailure,
        );
    }

    protected function defineEnvironment(ApplicationContract $app): void
    {
        throw $this->bootstrapFailure ?? new RuntimeException('bootstrap failed');
    }
}

class CreatesApplicationTrackingApplication extends Application
{
    /** @var list<string> */
    public array $lifecycle = [];

    public function __construct(?string $basePath, protected ?Throwable $terminationFailure)
    {
        parent::__construct($basePath);
    }

    public function terminate(): void
    {
        $this->lifecycle[] = 'terminate';

        if ($this->terminationFailure !== null) {
            throw $this->terminationFailure;
        }

        parent::terminate();
    }

    public function flush(): void
    {
        $this->lifecycle[] = 'flush';

        parent::flush();
    }
}
