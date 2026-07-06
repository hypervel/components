<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Cache\Redis;

use Hypervel\Cache\Exceptions\NotSupportedException;
use Hypervel\Cache\FileStore;
use Hypervel\Cache\Repository as CacheRepository;
use Hypervel\Cache\StackStore;
use Hypervel\Cache\StackStoreProxy;
use Hypervel\Cache\TagMode;
use Hypervel\Filesystem\Filesystem;
use Hypervel\Testing\ParallelTesting;

class StackTaggedCacheIntegrationTest extends RedisCacheIntegrationTestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempDir = ParallelTesting::tempDir('StackTaggedCacheIntegrationTest');

        $filesystem = new Filesystem;
        $filesystem->deleteDirectory($this->tempDir);
        $filesystem->makeDirectory($this->tempDir, 0777, true);
    }

    protected function tearDown(): void
    {
        (new Filesystem)->deleteDirectory($this->tempDir);

        parent::tearDown();
    }

    public function testTaggedWriteIsReadablePlainAndIndexedInRedis(): void
    {
        $this->setTagMode(TagMode::Any);

        $stack = $this->stackCache();

        $this->assertTrue($stack->tags(['stack-tag'])->put('stack-key', 'value', 30));

        $this->assertSame('value', $stack->get('stack-key'));
        $this->assertTrue($this->anyModeTagHasEntry('stack-tag', 'stack-key'));
        $this->assertContains('stack-tag', $this->getAnyModeReverseIndex('stack-key'));
    }

    public function testTagFlushLeavesOnlyBoundedL1Staleness(): void
    {
        $this->setTagMode(TagMode::Any);

        $stack = $this->stackCache(l1Ttl: 1);

        $stack->tags(['stack-tag'])->put('stack-key', 'value', 30);
        $this->assertSame('value', $stack->get('stack-key'));

        $stack->tags(['stack-tag'])->flush();

        $this->assertNull($this->cache()->get('stack-key'));
        $this->assertSame('value', $stack->get('stack-key'));

        sleep(2);

        $this->assertNull($stack->get('stack-key'));
    }

    public function testNonTaggableLayerBelowRedisWouldResurrectFlushedValues(): void
    {
        $this->setTagMode(TagMode::Any);

        $stack = new StackStore([
            new StackStoreProxy($this->store()),
            new StackStoreProxy($this->fileStore()),
        ]);

        $this->expectException(NotSupportedException::class);

        $stack->tags(['stack-tag']);
    }

    public function testReadBackfillsL1FromTaggedL2Record(): void
    {
        $this->setTagMode(TagMode::Any);

        $file = $this->fileStore();
        $stack = $this->stackCache(file: $file);

        $expiration = time() + 30;

        $this->cache()->tags(['stack-tag'])->put('stack-key', [
            'value' => 'from-redis',
            'expiration' => $expiration,
        ], 30);

        $this->assertSame('from-redis', $stack->get('stack-key'));
        $this->assertSame([
            'value' => 'from-redis',
            'expiration' => $expiration,
        ], $file->get('stack-key'));
    }

    public function testPlainForgetPreventsTagFlushFromDeletingReusedStackKey(): void
    {
        $this->setTagMode(TagMode::Any);

        $stack = $this->stackCache();

        $stack->tags(['stack-tag'])->put('stack-key', 'tagged', 30);

        $this->assertTrue($stack->forget('stack-key'));

        $stack->put('stack-key', 'plain', 30);
        $stack->tags(['stack-tag'])->flush();

        $this->assertSame('plain', $stack->get('stack-key'));
    }

    private function stackCache(?FileStore $file = null, int $l1Ttl = 1): CacheRepository
    {
        return new CacheRepository(new StackStore([
            new StackStoreProxy($file ?? $this->fileStore(), $l1Ttl),
            new StackStoreProxy($this->store()),
        ]));
    }

    private function fileStore(): FileStore
    {
        return new FileStore(new Filesystem, $this->tempDir);
    }
}
