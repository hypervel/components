<?php

declare(strict_types=1);

namespace Hypervel\Tests\Telescope\Console;

use Hypervel\Telescope\Database\Factories\EntryModelFactory;
use Hypervel\Tests\Telescope\FeatureTestCase;

class PruneCommandTest extends FeatureTestCase
{
    public function testPruneCommandWillClearOldRecords()
    {
        $recent = EntryModelFactory::new()->create(['created_at' => now()]);

        $old = EntryModelFactory::new()->create(['created_at' => now()->subDays(2)]);

        $this->artisan('telescope:prune')->expectsOutput('1 entries pruned.');

        $this->assertDatabaseHas('telescope_entries', ['uuid' => $recent->uuid]);

        $this->assertDatabaseMissing('telescope_entries', ['uuid' => $old->uuid]);
    }

    public function testPruneCommandCanVaryHours()
    {
        $recent = EntryModelFactory::new()->create(['created_at' => now()->subHours(5)]);

        $this->artisan('telescope:prune')->expectsOutput('0 entries pruned.');

        $this->artisan('telescope:prune', ['--hours' => 4])->expectsOutput('1 entries pruned.');

        $this->assertDatabaseMissing('telescope_entries', ['uuid' => $recent->uuid]);
    }
}
