<?php

declare(strict_types=1);

namespace Hypervel\Tests\ObjectPool;

use Closure;
use Hypervel\ObjectPool\Contracts\Factory;
use Hypervel\ObjectPool\PoolDefinition;
use Hypervel\ObjectPool\PoolFingerprint;
use Hypervel\ObjectPool\PoolManager;
use Hypervel\ObjectPool\PoolProxy;
use Hypervel\ObjectPool\Traits\HasPoolProxy;
use Hypervel\Tests\TestCase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use stdClass;

class HasPoolProxyTest extends TestCase
{
    protected PoolTraitManager $manager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->manager = new PoolTraitManager(new PoolManager);
    }

    protected function tearDownInCoroutine(): void
    {
        $this->manager->factory->flush();
    }

    public function testAutomaticDefinitionIsNamespacedAndFingerprintsConstructionInput(): void
    {
        $definition = $this->manager->definition(
            's3',
            ['max_objects' => 20],
            ['credentials' => ['key' => 'key']],
        );
        $fingerprint = PoolFingerprint::fromConfig(['credentials' => ['key' => 'key']]);

        $this->assertSame($fingerprint, $definition->fingerprint);
        $this->assertSame(PoolTraitManager::class . ':auto:s3:' . $fingerprint, $definition->identity);
        $this->assertSame('s3', $definition->resourceType);
        $this->assertSame(20, $definition->options->maxObjects);
    }

    public function testExplicitNameAndFingerprintUseDisjointDomains(): void
    {
        $definition = $this->manager->definition(
            's3',
            ['name' => 'shared-cloud', 'fingerprint' => 'declared-equivalent'],
            ['unfingerprintable' => new stdClass],
        );

        $this->assertSame(PoolTraitManager::class . ':named:shared-cloud', $definition->identity);
        $this->assertSame(PoolFingerprint::fromExplicit('declared-equivalent'), $definition->fingerprint);
    }

    public function testExplicitFingerprintWithoutANameKeepsAutomaticResourceIdentity(): void
    {
        $definition = $this->manager->definition(
            's3',
            ['fingerprint' => 'declared-equivalent'],
            ['unfingerprintable' => new stdClass],
        );

        $this->assertSame(
            PoolTraitManager::class . ':auto:s3:' . PoolFingerprint::fromExplicit('declared-equivalent'),
            $definition->identity,
        );
    }

    #[DataProvider('invalidControlValues')]
    public function testPoolControlValuesMustBeNonEmptyStrings(string $name, mixed $value): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("The pool [{$name}] option must be a non-empty string.");

        $this->manager->definition('s3', [$name => $value], []);
    }

    public static function invalidControlValues(): array
    {
        return [
            ['name', null],
            ['name', ''],
            ['name', '   '],
            ['name', 1],
            ['fingerprint', null],
            ['fingerprint', ''],
            ['fingerprint', "\t"],
            ['fingerprint', true],
        ];
    }

    public function testProxyCreationUsesTheDefinitionAndConfiguredReleaseCallback(): void
    {
        $definition = $this->manager->definition('service', [], []);
        $released = [];
        $this->manager->setReleaseCallback('service', function (object $object) use (&$released): void {
            $released[] = $object;
        });
        $proxy = $this->manager->proxy(
            'service',
            static fn (): object => new TraitPoolObject,
            $definition,
        );

        $this->assertSame('initial:value', $proxy->handle('value'));
        $this->assertCount(1, $released);
        $this->assertSame($definition, $proxy->getDefinition());
    }

    public function testPoolableDriverMutatorsKeepAListShape(): void
    {
        $this->manager->setPoolables(['first', 'second']);
        $this->manager->removePoolable('first');
        $this->manager->addPoolable('second');
        $this->manager->addPoolable('third');

        $this->assertSame(['second', 'third'], $this->manager->getPoolables());
    }
}

class PoolTraitManager
{
    use HasPoolProxy;

    protected array $poolables = [];

    public function __construct(
        public PoolManager $factory,
    ) {
    }

    public function definition(string $resource, array $poolConfig, array $fingerprintSource): PoolDefinition
    {
        return $this->poolDefinition($resource, $poolConfig, $fingerprintSource);
    }

    public function proxy(string $driver, Closure $resolver, PoolDefinition $definition): TraitPoolProxy
    {
        return $this->createPoolProxy($driver, $resolver, $definition, TraitPoolProxy::class);
    }

    protected function poolFactory(): Factory
    {
        return $this->factory;
    }
}

class TraitPoolProxy extends PoolProxy
{
    public function handle(string $value): string
    {
        return $this->invoke('handle', [$value]);
    }
}

class TraitPoolObject
{
    public string $state = 'initial';

    public function handle(string $value): string
    {
        return $this->state . ':' . $value;
    }
}
