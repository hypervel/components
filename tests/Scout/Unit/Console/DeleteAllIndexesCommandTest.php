<?php

declare(strict_types=1);

namespace Hypervel\Tests\Scout\Unit\Console;

use Exception;
use Hypervel\Config\Repository;
use Hypervel\Scout\Console\DeleteAllIndexesCommand;
use Hypervel\Scout\EngineManager;
use Hypervel\Scout\Engines\CollectionEngine;
use Hypervel\Scout\Engines\MeilisearchEngine;
use Hypervel\Tests\TestCase;
use JMac\Testing\Double;
use JMac\Testing\Integrations\PHPUnit\VerifiesDoubles;
use JMac\Testing\Matching\Argument;

class DeleteAllIndexesCommandTest extends TestCase
{
    use VerifiesDoubles;

    public function testDeletesAllIndexesSuccessfully(): void
    {
        $engine = Double::for(MeilisearchEngine::class)->strict();
        $engine->expects('deleteAllIndexes')
            ->with('test_')
            ->returns([]);

        $manager = Double::for(EngineManager::class)->strict();
        $manager->expects('engine')
            ->returns($engine);

        $config = $this->configWithPrefix('test_');

        $command = Double::for(new DeleteAllIndexesCommand)->passthru();
        $command->allows('option')->with('force')->returns(false);
        $command->expects('info')
            ->with('All indexes deleted successfully.');

        $result = $command->handle($manager, $config);

        $this->assertSame(0, $result);
    }

    public function testFailsWhenEngineDoesNotSupportDeleteAllIndexes(): void
    {
        $engine = new CollectionEngine;

        $manager = Double::for(EngineManager::class)->strict();
        $manager->expects('engine')
            ->returns($engine);
        $manager->expects('getDefaultDriver')
            ->returns('collection');

        // Must set a non-empty prefix: the safety gate runs before engine
        // resolution, and with an empty prefix we'd hit the refusal message
        // rather than the "does not support" path.
        $config = $this->configWithPrefix('test_');

        $command = Double::for(new DeleteAllIndexesCommand)->passthru();
        $command->allows('option')->with('force')->returns(false);
        $command->expects('error')
            ->with('The [collection] engine does not support deleting all indexes.');

        $result = $command->handle($manager, $config);

        $this->assertSame(1, $result);
    }

    public function testHandlesExceptionFromEngine(): void
    {
        $engine = Double::for(MeilisearchEngine::class)->strict();
        $engine->expects('deleteAllIndexes')
            ->with('test_')
            ->throws(new Exception('Connection failed'));

        $manager = Double::for(EngineManager::class)->strict();
        $manager->expects('engine')
            ->returns($engine);

        $config = $this->configWithPrefix('test_');

        $command = Double::for(new DeleteAllIndexesCommand)->passthru();
        $command->allows('option')->with('force')->returns(false);
        $command->expects('error')
            ->with('Connection failed');

        $result = $command->handle($manager, $config);

        $this->assertSame(1, $result);
    }

    public function testRefusesWhenPrefixEmptyAndNotForced(): void
    {
        $manager = Double::for(EngineManager::class)->strict();
        // Safety gate runs first; engine is never resolved.
        $manager->expects('engine')->never();

        $config = $this->configWithPrefix('');

        $capturedMessage = null;
        $command = Double::for(new DeleteAllIndexesCommand)->passthru();
        $command->allows('option')->with('force')->returns(false);
        $command->expects('error')
            ->with(Argument::capture($capturedMessage));

        $result = $command->handle($manager, $config);

        $this->assertSame(1, $result);
        $this->assertStringContainsString('scout.prefix', $capturedMessage);
        $this->assertStringContainsString('--force', $capturedMessage);
    }

    public function testRunsUnscopedWhenForcedWithEmptyPrefix(): void
    {
        $engine = Double::for(MeilisearchEngine::class)->strict();
        $engine->expects('deleteAllIndexes')
            ->with(null)
            ->returns([]);

        $manager = Double::for(EngineManager::class)->strict();
        $manager->expects('engine')
            ->returns($engine);

        $config = $this->configWithPrefix('');

        $command = Double::for(new DeleteAllIndexesCommand)->passthru();
        $command->allows('option')->with('force')->returns(true);
        $command->expects('info');

        $result = $command->handle($manager, $config);

        $this->assertSame(0, $result);
    }

    public function testRunsScopedWhenPrefixSet(): void
    {
        $engine = Double::for(MeilisearchEngine::class)->strict();
        $engine->expects('deleteAllIndexes')
            ->with('app_')
            ->returns([]);

        $manager = Double::for(EngineManager::class)->strict();
        $manager->expects('engine')
            ->returns($engine);

        $config = $this->configWithPrefix('app_');

        $command = Double::for(new DeleteAllIndexesCommand)->passthru();
        $command->allows('option')->with('force')->returns(false);
        $command->expects('info');

        $result = $command->handle($manager, $config);

        $this->assertSame(0, $result);
    }

    public function testForceDoesNotOverrideScopingWhenPrefixSet(): void
    {
        $engine = Double::for(MeilisearchEngine::class)->strict();
        // Even with --force, the configured prefix still scopes the deletion.
        $engine->expects('deleteAllIndexes')
            ->with('app_')
            ->returns([]);

        $manager = Double::for(EngineManager::class)->strict();
        $manager->expects('engine')
            ->returns($engine);

        $config = $this->configWithPrefix('app_');

        $command = Double::for(new DeleteAllIndexesCommand)->passthru();
        $command->allows('option')->with('force')->returns(true);
        $command->expects('info');

        $result = $command->handle($manager, $config);

        $this->assertSame(0, $result);
    }

    /**
     * Create configuration with the given search index prefix.
     */
    protected function configWithPrefix(string $prefix): Repository
    {
        return new Repository(['scout' => ['prefix' => $prefix]]);
    }
}
