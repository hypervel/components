<?php

declare(strict_types=1);

namespace Hypervel\Tests\Horizon\Feature;

use Carbon\CarbonImmutable;
use Hypervel\Horizon\Contracts\ProcessRepository;
use Hypervel\Tests\Horizon\IntegrationTestCase;

/**
 * @internal
 * @coversNothing
 */
class ProcessRepositoryTest extends IntegrationTestCase
{
    public function testExpiredOrphansCanBeFound()
    {
        $repo = resolve(ProcessRepository::class);

        CarbonImmutable::setTestNow($time = CarbonImmutable::create(2026, 1, 1, 0, 0, 0));

        try {
            $repo->orphaned('foo', ['1', '2', '3', '4', '5', '6']);

            CarbonImmutable::setTestNow($time->addSeconds(2));
            $repo->orphaned('foo', ['1', '2', '3']);

            $orphans = $repo->orphanedFor('foo', 1);

            $this->assertEquals(['1', '2', '3'], $orphans);
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function testOrphansCanBeDeleted()
    {
        $repo = resolve(ProcessRepository::class);
        $repo->orphaned('foo', ['1', '2', '3']);
        $repo->forgetOrphans('foo', ['1', '2']);
        $this->assertEquals(['3'], array_keys($repo->allOrphans('foo')));
    }
}
