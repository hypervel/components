<?php

declare(strict_types=1);

namespace Hypervel\Tests\Scout\Unit;

use Hypervel\Database\Eloquent\Model;
use Hypervel\Scout\Builder;
use Hypervel\Scout\EngineManager;
use Hypervel\Scout\Engines\Engine;
use Hypervel\Scout\Jobs\MakeSearchable;
use Hypervel\Scout\Jobs\RemoveFromSearch;
use Hypervel\Scout\Scout;
use Hypervel\Tests\Scout\ScoutTestCase;
use JMac\Testing\Double;
use JMac\Testing\Integrations\PHPUnit\VerifiesDoubles;
use RuntimeException;

/**
 * Tests for the Scout utility class.
 */
class ScoutTest extends ScoutTestCase
{
    use VerifiesDoubles;

    public function testDefaultMakeSearchableJobClass(): void
    {
        $this->assertSame(MakeSearchable::class, Scout::$makeSearchableJob);
    }

    public function testDefaultRemoveFromSearchJobClass(): void
    {
        $this->assertSame(RemoveFromSearch::class, Scout::$removeFromSearchJob);
    }

    public function testMakeSearchableUsingChangesJobClass(): void
    {
        Scout::makeSearchableUsing(CustomMakeSearchable::class);

        $this->assertSame(CustomMakeSearchable::class, Scout::$makeSearchableJob);
    }

    public function testRemoveFromSearchUsingChangesJobClass(): void
    {
        Scout::removeFromSearchUsing(CustomRemoveFromSearch::class);

        $this->assertSame(CustomRemoveFromSearch::class, Scout::$removeFromSearchJob);
    }

    public function testLifecycleCallbacksPreserveValuesWhenUnset(): void
    {
        $builder = Double::for(Builder::class)->strict();
        $engine = Double::for(Engine::class)->strict();
        $model = Double::for(Model::class)->strict();

        Scout::prepareBuilder($builder, $engine);

        $this->assertSame(['name' => 'Taylor'], Scout::prepareSearchableDocument(
            ['name' => 'Taylor'],
            $model,
            $engine
        ));
        $this->assertSame(['filterableAttributes' => ['status']], Scout::prepareIndexSettings(
            ['filterableAttributes' => ['status']],
            $model,
            $engine,
            'users'
        ));

        Scout::guardModelFlush($model, $engine, false);
    }

    public function testLifecycleCallbacksReceiveTheirCompleteBoundaries(): void
    {
        $builder = Double::for(Builder::class)->strict();
        $engine = Double::for(Engine::class)->strict();
        $model = Double::for(Model::class)->strict();
        $preparedBuilder = false;
        $guardedFlush = false;

        Scout::prepareBuilderUsing(function (Builder $givenBuilder, Engine $givenEngine) use (
            $builder,
            $engine,
            &$preparedBuilder
        ): void {
            $this->assertSame($builder, $givenBuilder);
            $this->assertSame($engine, $givenEngine);
            $preparedBuilder = true;
        });
        Scout::prepareSearchableDocumentUsing(function (
            array $document,
            Model $givenModel,
            Engine $givenEngine
        ) use ($model, $engine): array {
            $this->assertSame($model, $givenModel);
            $this->assertSame($engine, $givenEngine);

            return [...$document, 'prepared' => true];
        });
        Scout::prepareIndexSettingsUsing(function (
            array $settings,
            ?Model $givenModel,
            Engine $givenEngine,
            string $index
        ) use ($model, $engine): array {
            $this->assertSame($model, $givenModel);
            $this->assertSame($engine, $givenEngine);
            $this->assertSame('users', $index);

            return [...$settings, 'prepared' => true];
        });
        Scout::guardModelFlushUsing(function (
            Model $givenModel,
            Engine $givenEngine,
            bool $force
        ) use ($model, $engine, &$guardedFlush): void {
            $this->assertSame($model, $givenModel);
            $this->assertSame($engine, $givenEngine);
            $this->assertTrue($force);
            $guardedFlush = true;
        });

        Scout::prepareBuilder($builder, $engine);
        $document = Scout::prepareSearchableDocument(['name' => 'Taylor'], $model, $engine);
        $settings = Scout::prepareIndexSettings(['searchableAttributes' => ['name']], $model, $engine, 'users');
        Scout::guardModelFlush($model, $engine, true);

        $this->assertTrue($preparedBuilder);
        $this->assertSame(['name' => 'Taylor', 'prepared' => true], $document);
        $this->assertSame(['searchableAttributes' => ['name'], 'prepared' => true], $settings);
        $this->assertTrue($guardedFlush);
    }

    public function testRegisteringLifecycleCallbackAgainReplacesPreviousCallback(): void
    {
        $builder = Double::for(Builder::class)->strict();
        $engine = Double::for(Engine::class)->strict();
        $calls = [];

        Scout::prepareBuilderUsing(function () use (&$calls): void {
            $calls[] = 'first';
        });
        Scout::prepareBuilderUsing(function () use (&$calls): void {
            $calls[] = 'second';
        });

        Scout::prepareBuilder($builder, $engine);

        $this->assertSame(['second'], $calls);
    }

    public function testFlushStateRestoresDefaults(): void
    {
        Scout::makeSearchableUsing(CustomMakeSearchable::class);
        Scout::removeFromSearchUsing(CustomRemoveFromSearch::class);
        Scout::prepareBuilderUsing(fn () => throw new RuntimeException('Builder callback was not flushed.'));
        Scout::prepareSearchableDocumentUsing(fn () => throw new RuntimeException('Document callback was not flushed.'));
        Scout::prepareIndexSettingsUsing(fn () => throw new RuntimeException('Settings callback was not flushed.'));
        Scout::guardModelFlushUsing(fn () => throw new RuntimeException('Flush callback was not flushed.'));

        Scout::flushState();

        $this->assertSame(MakeSearchable::class, Scout::$makeSearchableJob);
        $this->assertSame(RemoveFromSearch::class, Scout::$removeFromSearchJob);

        $builder = Double::for(Builder::class)->strict();
        $engine = Double::for(Engine::class)->strict();
        $model = Double::for(Model::class)->strict();

        Scout::prepareBuilder($builder, $engine);
        $this->assertSame([], Scout::prepareSearchableDocument([], $model, $engine));
        $this->assertSame([], Scout::prepareIndexSettings([], null, $engine, 'users'));
        Scout::guardModelFlush($model, $engine, false);
    }

    public function testEngineMethodReturnsEngineFromManager(): void
    {
        $engine = Double::for(Engine::class)->strict();

        $manager = Double::for(EngineManager::class)->strict();
        $manager->expects('engine')
            ->with('meilisearch')
            ->returns($engine);

        $this->app->instance(EngineManager::class, $manager);

        $result = Scout::engine('meilisearch');

        $this->assertSame($engine, $result);
    }

    public function testEngineMethodWithNullUsesDefaultEngine(): void
    {
        $engine = Double::for(Engine::class)->strict();

        $manager = Double::for(EngineManager::class)->strict();
        $manager->expects('engine')
            ->with(null)
            ->returns($engine);

        $this->app->instance(EngineManager::class, $manager);

        $result = Scout::engine();

        $this->assertSame($engine, $result);
    }
}

/**
 * Custom job class for testing makeSearchableUsing().
 */
class CustomMakeSearchable extends MakeSearchable
{
}

/**
 * Custom job class for testing removeFromSearchUsing().
 */
class CustomRemoveFromSearch extends RemoveFromSearch
{
}
