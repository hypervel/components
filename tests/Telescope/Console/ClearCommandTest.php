<?php

declare(strict_types=1);

namespace Hypervel\Tests\Telescope\Console;

use Hypervel\Support\Facades\DB;
use Hypervel\Telescope\Database\Factories\EntryModelFactory;
use Hypervel\Telescope\Storage\EntryModel;
use Hypervel\Tests\Telescope\FeatureTestCase;

/**
 * @internal
 * @coversNothing
 */
class ClearCommandTest extends FeatureTestCase
{
    public function testClearCommandWillDeleteAllEntries()
    {
        EntryModelFactory::new()->create();

        DB::table('telescope_monitoring')->insert([
            ['tag' => 'one'],
            ['tag' => 'two'],
        ]);

        $this->artisan('telescope:clear');

        $this->assertSame(0, EntryModel::query()->count());
        $this->assertSame(0, DB::table('telescope_monitoring')->count());
    }
}
