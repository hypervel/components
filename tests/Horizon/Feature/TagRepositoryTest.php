<?php

declare(strict_types=1);

namespace Hypervel\Tests\Horizon\Feature;

use Hypervel\Horizon\Contracts\TagRepository;
use Hypervel\Tests\Horizon\IntegrationTestCase;

/**
 * @internal
 * @coversNothing
 */
class TagRepositoryTest extends IntegrationTestCase
{
    public function testPaginationOfJobIdsCanBeAccomplished()
    {
        $repo = resolve(TagRepository::class);

        for ($i = 0; $i < 50; ++$i) {
            $repo->add((string) $i, ['tag']);
        }

        $results = $repo->paginate('tag', 0, 25);

        $this->assertCount(25, $results);
        $this->assertSame('49', $results[0]);
        $this->assertSame('25', $results[24]);

        $results = $repo->paginate('tag', last(array_keys($results)) + 1, 25);

        $this->assertCount(25, $results);
        $this->assertSame('24', $results[25]);
        $this->assertSame('0', $results[49]);
    }
}
