<?php

declare(strict_types=1);

namespace Hypervel\Tests\Database;

use Hypervel\Console\Command;
use Hypervel\Console\OutputStyle;
use Hypervel\Container\Container as ContainerImplementation;
use Hypervel\Contracts\Container\Container;
use Hypervel\Database\Seeder;
use Hypervel\Tests\TestCase;
use Mockery as m;
use Mockery\Mock;
use RuntimeException;

class TestSeeder extends Seeder
{
    public function run(): void
    {
    }
}

class TestDepsSeeder extends Seeder
{
    public function run(Mock $someDependency, string $someParam = ''): void
    {
    }
}

class CountingSeeder extends Seeder
{
    public static int $runs = 0;

    public function run(): void
    {
        ++static::$runs;
    }
}

class CallsCountingSeederOnce extends Seeder
{
    public function run(): void
    {
        $this->callOnce(CountingSeeder::class, true);
    }
}

class CallsNestedSeederTwice extends Seeder
{
    public function run(): void
    {
        $this->call([CallsCountingSeederOnce::class, CallsCountingSeederOnce::class], true);
    }
}

class CallsCountingSeederOnceThenThrows extends Seeder
{
    public function __construct(public RuntimeException $exception)
    {
    }

    public function run(): void
    {
        $this->callOnce(CountingSeeder::class, true);

        throw $this->exception;
    }
}

class CallsThrowingSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(CallsCountingSeederOnceThenThrows::class, true);
    }
}

class ReentrantSeeder extends Seeder
{
    private bool $reentered = false;

    public function run(): void
    {
        if ($this->reentered) {
            $this->callOnce(CountingSeeder::class, true);

            return;
        }

        $this->reentered = true;

        try {
            $this->call(ReentersSeeder::class, true);
        } finally {
            $this->reentered = false;
        }

        $this->callOnce(CountingSeeder::class, true);
    }
}

class ReentersSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(ReentrantSeeder::class, true);
    }
}

class CallsReentrantSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(ReentrantSeeder::class, true);
    }
}

class RecordsSeederInstance extends Seeder
{
    /** @var list<self> */
    public static array $instances = [];

    public function run(): void
    {
        static::$instances[] = $this;
    }
}

class CallsRecordingSeederTwice extends Seeder
{
    public function run(): void
    {
        $this->call([RecordsSeederInstance::class, RecordsSeederInstance::class], true);
    }
}

class DatabaseSeederTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        CountingSeeder::$runs = 0;
        RecordsSeederInstance::$instances = [];
    }

    public function testCallResolvesTheClassAndCallsRun(): void
    {
        $seeder = new TestSeeder;
        $seeder->setContainer($container = m::mock(Container::class));
        $output = m::mock(OutputStyle::class);
        $output->shouldReceive('writeln')->times(3);
        $command = m::mock(Command::class);
        $command->shouldReceive('getOutput')->times(3)->andReturn($output);
        $seeder->setCommand($command);
        $container->shouldReceive('make')->once()->with('ClassName')->andReturn($child = m::mock(Seeder::class));
        $child->shouldReceive('setContainer')->once()->with($container)->andReturn($child);
        $child->shouldReceive('setCommand')->once()->with($command)->andReturn($child);
        $child->shouldReceive('__invoke')->once();

        $seeder->call('ClassName');
    }

    public function testSetContainer(): void
    {
        $seeder = new TestSeeder;
        $container = m::mock(Container::class);
        $this->assertEquals($seeder->setContainer($container), $seeder);
    }

    public function testSetCommand(): void
    {
        $seeder = new TestSeeder;
        $command = m::mock(Command::class);
        $this->assertEquals($seeder->setCommand($command), $seeder);
    }

    public function testInjectDependenciesOnRunMethod(): void
    {
        $container = m::mock(Container::class);
        $container->shouldReceive('call');

        $seeder = new TestDepsSeeder;
        $seeder->setContainer($container);

        $seeder->__invoke();

        $container->shouldHaveReceived('call')->once()->with([$seeder, 'run'], []);
    }

    public function testSendParamsOnCallMethodWithDeps(): void
    {
        $container = m::mock(Container::class);
        $container->shouldReceive('call');

        $seeder = new TestDepsSeeder;
        $seeder->setContainer($container);

        $seeder->__invoke(['test1', 'test2']);

        $container->shouldHaveReceived('call')->once()->with([$seeder, 'run'], ['test1', 'test2']);
    }

    public function testCallOnceResetsForEveryInvocationOfTheSameRoot(): void
    {
        $seeder = new CallsCountingSeederOnce;

        $seeder();
        $seeder();

        $this->assertSame(2, CountingSeeder::$runs);
    }

    public function testCallOnceStateIsIndependentBetweenRoots(): void
    {
        (new CallsCountingSeederOnce)();
        (new CallsCountingSeederOnce)();

        $this->assertSame(2, CountingSeeder::$runs);
    }

    public function testContainerlessNestedSeedersShareTheRootRegistry(): void
    {
        (new CallsNestedSeederTwice)();

        $this->assertSame(1, CountingSeeder::$runs);
    }

    public function testUnboundNestedSeedersAreFresh(): void
    {
        $seeder = (new CallsRecordingSeederTwice)
            ->setContainer(new ContainerImplementation);

        $seeder();

        $this->assertCount(2, RecordsSeederInstance::$instances);
        $this->assertNotSame(
            RecordsSeederInstance::$instances[0],
            RecordsSeederInstance::$instances[1],
        );
    }

    public function testExplicitNestedSeederInstancesAreHonored(): void
    {
        $container = new ContainerImplementation;
        $boundSeeder = new RecordsSeederInstance;
        $container->instance(RecordsSeederInstance::class, $boundSeeder);

        $seeder = (new CallsRecordingSeederTwice)->setContainer($container);
        $seeder();

        $this->assertSame([
            $boundSeeder,
            $boundSeeder,
        ], RecordsSeederInstance::$instances);
    }

    public function testExplicitNestedSeederCanLaterRunAsItsOwnRoot(): void
    {
        $container = new ContainerImplementation;
        $boundSeeder = new CallsCountingSeederOnce;
        $container->instance(CallsCountingSeederOnce::class, $boundSeeder);

        (new CallsNestedSeederTwice)->setContainer($container)();
        $boundSeeder();

        $this->assertSame(2, CountingSeeder::$runs);
    }

    public function testExplicitNestedSeederCanRunAsItsOwnRootAfterThrowing(): void
    {
        $container = new ContainerImplementation;
        $exception = new RuntimeException('Seeder failed.');
        $boundSeeder = new CallsCountingSeederOnceThenThrows($exception);
        $container->instance(CallsCountingSeederOnceThenThrows::class, $boundSeeder);

        try {
            (new CallsThrowingSeeder)->setContainer($container)();
            $this->fail('The nested seeder did not throw.');
        } catch (RuntimeException $thrown) {
            $this->assertSame($exception, $thrown);
        }

        try {
            $boundSeeder();
            $this->fail('The root seeder did not throw.');
        } catch (RuntimeException $thrown) {
            $this->assertSame($exception, $thrown);
        }

        $this->assertSame(2, CountingSeeder::$runs);
    }

    public function testReentrantExplicitSeederRestoresItsOuterRoot(): void
    {
        $container = new ContainerImplementation;
        $container->instance(ReentrantSeeder::class, new ReentrantSeeder);

        (new CallsReentrantSeeder)->setContainer($container)();

        $this->assertSame(1, CountingSeeder::$runs);
    }
}
