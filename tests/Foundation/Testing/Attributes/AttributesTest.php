<?php

declare(strict_types=1);

namespace Hypervel\Tests\Foundation\Testing\Attributes;

use Attribute;
use Closure;
use Hypervel\Foundation\Testing\RefreshDatabaseState;
use Hypervel\Testbench\Attributes\Define;
use Hypervel\Testbench\Attributes\DefineDatabase;
use Hypervel\Testbench\Attributes\DefineEnvironment;
use Hypervel\Testbench\Attributes\DefineRoute;
use Hypervel\Testbench\Attributes\RequiresEnv;
use Hypervel\Testbench\Attributes\ResetRefreshDatabaseState;
use Hypervel\Testbench\Attributes\WithConfig;
use Hypervel\Testbench\Attributes\WithMigration;
use Hypervel\Testbench\Contracts\Attributes\Actionable;
use Hypervel\Testbench\Contracts\Attributes\AfterAll;
use Hypervel\Testbench\Contracts\Attributes\AfterEach;
use Hypervel\Testbench\Contracts\Attributes\BeforeAll;
use Hypervel\Testbench\Contracts\Attributes\BeforeEach;
use Hypervel\Testbench\Contracts\Attributes\Invokable;
use Hypervel\Testbench\Contracts\Attributes\Resolvable;
use Hypervel\Testbench\TestCase;
use ReflectionClass;

/**
 * @internal
 * @coversNothing
 */
class AttributesTest extends TestCase
{
    public function testDefineEnvironmentImplementsActionable(): void
    {
        $attribute = new DefineEnvironment('someMethod');

        $this->assertInstanceOf(Actionable::class, $attribute);
        $this->assertSame('someMethod', $attribute->method);
    }

    public function testDefineEnvironmentCallsMethod(): void
    {
        $attribute = new DefineEnvironment('testMethod');
        $called = false;
        $receivedArgs = null;

        $action = function (string $method, array $params) use (&$called, &$receivedArgs) {
            $called = true;
            $receivedArgs = [$method, $params];
        };

        $attribute->handle($this->app, $action);

        $this->assertTrue($called);
        $this->assertSame('testMethod', $receivedArgs[0]);
        $this->assertSame([$this->app], $receivedArgs[1]);
    }

    public function testWithConfigImplementsInvokable(): void
    {
        $attribute = new WithConfig('app.name', 'TestApp');

        $this->assertInstanceOf(Invokable::class, $attribute);
        $this->assertSame('app.name', $attribute->key);
        $this->assertSame('TestApp', $attribute->value);
    }

    public function testWithConfigSetsConfigValue(): void
    {
        $attribute = new WithConfig('testing.attributes.key', 'test_value');

        $attribute($this->app);

        $this->assertSame('test_value', $this->app->get('config')->get('testing.attributes.key'));
    }

    public function testDefineRouteImplementsActionable(): void
    {
        $attribute = new DefineRoute('defineTestRoutes');

        $this->assertInstanceOf(Actionable::class, $attribute);
        $this->assertSame('defineTestRoutes', $attribute->method);
    }

    public function testDefineDatabaseImplementsRequiredInterfaces(): void
    {
        $attribute = new DefineDatabase('defineMigrations');

        $this->assertInstanceOf(Actionable::class, $attribute);
        $this->assertInstanceOf(BeforeEach::class, $attribute);
        $this->assertInstanceOf(AfterEach::class, $attribute);
    }

    public function testDefineDatabaseDeferredExecution(): void
    {
        $attribute = new DefineDatabase('defineMigrations', defer: true);
        $called = false;

        $action = function () use (&$called) {
            $called = true;
        };

        $result = $attribute->handle($this->app, $action);

        $this->assertFalse($called);
        $this->assertInstanceOf(Closure::class, $result);

        // Execute the deferred callback
        $result();
        $this->assertTrue($called);
    }

    public function testDefineDatabaseImmediateExecution(): void
    {
        $attribute = new DefineDatabase('defineMigrations', defer: false);
        $called = false;

        $action = function () use (&$called) {
            $called = true;
        };

        $result = $attribute->handle($this->app, $action);

        $this->assertTrue($called);
        $this->assertNull($result);
    }

    public function testResetRefreshDatabaseStateImplementsLifecycleInterfaces(): void
    {
        $attribute = new ResetRefreshDatabaseState();

        $this->assertInstanceOf(BeforeAll::class, $attribute);
        $this->assertInstanceOf(AfterAll::class, $attribute);
    }

    public function testResetRefreshDatabaseStateResetsState(): void
    {
        // Set some state
        RefreshDatabaseState::$migrated = true;
        RefreshDatabaseState::$lazilyRefreshed = true;
        RefreshDatabaseState::$inMemoryConnections = ['test'];

        ResetRefreshDatabaseState::run();

        $this->assertFalse(RefreshDatabaseState::$migrated);
        $this->assertFalse(RefreshDatabaseState::$lazilyRefreshed);
        $this->assertEmpty(RefreshDatabaseState::$inMemoryConnections);
    }

    public function testWithMigrationImplementsInvokable(): void
    {
        $attribute = new WithMigration('laravel');

        $this->assertInstanceOf(Invokable::class, $attribute);
        $this->assertSame(['laravel'], $attribute->types);
    }

    public function testWithMigrationDefaultsToLaravel(): void
    {
        $attribute = new WithMigration();

        $this->assertSame(['laravel'], $attribute->types);
    }

    public function testWithMigrationAliasesMapToLaravel(): void
    {
        // cache, queue, session all map to 'laravel'
        $cacheAttr = new WithMigration('cache');
        $queueAttr = new WithMigration('queue');
        $sessionAttr = new WithMigration('session');

        $this->assertSame(['laravel'], $cacheAttr->types);
        $this->assertSame(['laravel'], $queueAttr->types);
        $this->assertSame(['laravel'], $sessionAttr->types);
    }

    public function testWithMigrationDeduplicatesTypes(): void
    {
        // Multiple aliases that map to 'laravel' should dedupe
        $attribute = new WithMigration('cache', 'queue', 'session', 'laravel');

        $this->assertSame(['laravel'], $attribute->types);
    }

    public function testWithMigrationPreservesLiteralPaths(): void
    {
        $attribute = new WithMigration('/path/to/migrations');

        $this->assertSame(['/path/to/migrations'], $attribute->types);
    }

    public function testWithMigrationMixedTypesAndPaths(): void
    {
        $attribute = new WithMigration('cache', '/custom/path');

        $this->assertSame(['laravel', '/custom/path'], $attribute->types);
    }

    public function testRequiresEnvImplementsActionable(): void
    {
        $attribute = new RequiresEnv('SOME_VAR');

        $this->assertInstanceOf(Actionable::class, $attribute);
        $this->assertSame('SOME_VAR', $attribute->key);
    }

    public function testDefineImplementsResolvable(): void
    {
        $attribute = new Define('env', 'setupEnv');

        $this->assertInstanceOf(Resolvable::class, $attribute);
        $this->assertSame('env', $attribute->group);
        $this->assertSame('setupEnv', $attribute->method);
    }

    public function testDefineResolvesToDefineEnvironment(): void
    {
        $attribute = new Define('env', 'setupEnv');
        $resolved = $attribute->resolve();

        $this->assertInstanceOf(DefineEnvironment::class, $resolved);
        $this->assertSame('setupEnv', $resolved->method);
    }

    public function testDefineResolvesToDefineDatabase(): void
    {
        $attribute = new Define('db', 'setupDb');
        $resolved = $attribute->resolve();

        $this->assertInstanceOf(DefineDatabase::class, $resolved);
        $this->assertSame('setupDb', $resolved->method);
    }

    public function testDefineResolvesToDefineRoute(): void
    {
        $attribute = new Define('route', 'setupRoutes');
        $resolved = $attribute->resolve();

        $this->assertInstanceOf(DefineRoute::class, $resolved);
        $this->assertSame('setupRoutes', $resolved->method);
    }

    public function testDefineReturnsNullForUnknownGroup(): void
    {
        $attribute = new Define('unknown', 'someMethod');
        $resolved = $attribute->resolve();

        $this->assertNull($resolved);
    }

    public function testDefineGroupIsCaseInsensitive(): void
    {
        $envUpper = new Define('ENV', 'method');
        $envMixed = new Define('Env', 'method');

        $this->assertInstanceOf(DefineEnvironment::class, $envUpper->resolve());
        $this->assertInstanceOf(DefineEnvironment::class, $envMixed->resolve());
    }

    public function testAttributesHaveCorrectTargets(): void
    {
        $this->assertAttributeHasTargets(DefineEnvironment::class, ['TARGET_CLASS', 'TARGET_METHOD', 'IS_REPEATABLE']);
        $this->assertAttributeHasTargets(WithConfig::class, ['TARGET_CLASS', 'TARGET_METHOD', 'IS_REPEATABLE']);
        $this->assertAttributeHasTargets(DefineRoute::class, ['TARGET_METHOD', 'IS_REPEATABLE']);
        $this->assertAttributeHasTargets(DefineDatabase::class, ['TARGET_METHOD', 'IS_REPEATABLE']);
        $this->assertAttributeHasTargets(ResetRefreshDatabaseState::class, ['TARGET_CLASS']);
        $this->assertAttributeHasTargets(WithMigration::class, ['TARGET_CLASS', 'TARGET_METHOD', 'IS_REPEATABLE']);
        $this->assertAttributeHasTargets(RequiresEnv::class, ['TARGET_CLASS', 'TARGET_METHOD', 'IS_REPEATABLE']);
        $this->assertAttributeHasTargets(Define::class, ['TARGET_CLASS', 'TARGET_METHOD', 'IS_REPEATABLE']);
    }

    private function assertAttributeHasTargets(string $class, array $expectedTargets): void
    {
        $reflection = new ReflectionClass($class);
        $attributes = $reflection->getAttributes(Attribute::class);

        $this->assertNotEmpty($attributes, "Class {$class} should have #[Attribute]");

        $attributeInstance = $attributes[0]->newInstance();
        $flags = $attributeInstance->flags;

        foreach ($expectedTargets as $target) {
            $constant = constant("Attribute::{$target}");
            $this->assertTrue(
                ($flags & $constant) !== 0,
                "Class {$class} should have {$target} flag"
            );
        }
    }
}
