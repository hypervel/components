<?php

declare(strict_types=1);

namespace Hypervel\Tests\Scout\Unit;

use Hypervel\Scout\Engine;
use Hypervel\Scout\EngineManager;
use Hypervel\Scout\Jobs\MakeSearchable;
use Hypervel\Scout\Jobs\RemoveFromSearch;
use Hypervel\Scout\Scout;
use Hypervel\Tests\Scout\ScoutTestCase;
use Mockery as m;

/**
 * Tests for the Scout utility class.
 *
 * @internal
 * @coversNothing
 */
class ScoutTest extends ScoutTestCase
{
    protected function tearDown(): void
    {
        Scout::resetJobClasses();
        m::close();
        parent::tearDown();
    }

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

    public function testResetJobClassesRestoresDefaults(): void
    {
        Scout::makeSearchableUsing(CustomMakeSearchable::class);
        Scout::removeFromSearchUsing(CustomRemoveFromSearch::class);

        Scout::resetJobClasses();

        $this->assertSame(MakeSearchable::class, Scout::$makeSearchableJob);
        $this->assertSame(RemoveFromSearch::class, Scout::$removeFromSearchJob);
    }

    public function testEngineMethodReturnsEngineFromManager(): void
    {
        $engine = m::mock(Engine::class);

        $manager = m::mock(EngineManager::class);
        $manager->shouldReceive('engine')
            ->with('meilisearch')
            ->once()
            ->andReturn($engine);

        $this->app->instance(EngineManager::class, $manager);

        $result = Scout::engine('meilisearch');

        $this->assertSame($engine, $result);
    }

    public function testEngineMethodWithNullUsesDefaultEngine(): void
    {
        $engine = m::mock(Engine::class);

        $manager = m::mock(EngineManager::class);
        $manager->shouldReceive('engine')
            ->with(null)
            ->once()
            ->andReturn($engine);

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
