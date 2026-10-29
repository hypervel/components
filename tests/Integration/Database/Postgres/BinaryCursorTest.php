<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Database\Postgres;

use Hypervel\Database\BinaryParameter;
use Hypervel\Database\Schema\Blueprint;
use Hypervel\Support\Collection;
use Hypervel\Support\Facades\DB;
use Hypervel\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\TestWith;

class BinaryCursorTest extends PostgresTestCase
{
    /**
     * Create the binary-keyed rows.
     */
    protected function afterRefreshingDatabase(): void
    {
        Schema::create('binary_cursors', function (Blueprint $table): void {
            $table->binary('hash')->primary();
        });

        foreach ([1, 2, 3] as $id) {
            DB::table('binary_cursors')->insert(['hash' => new BinaryParameter(pack('N', $id))]);
        }
    }

    #[TestWith(['chunkById', [1, 2, 3]])]
    #[TestWith(['chunkByIdDesc', [3, 2, 1]])]
    public function testConsumedBinaryStreamsCanBeUsedAsPagingCursors(string $method, array $expected): void
    {
        $actual = [];

        DB::table('binary_cursors')->{$method}(2, function (Collection $rows) use (&$actual): bool {
            foreach ($rows as $row) {
                $actual[] = unpack('N', stream_get_contents($row->hash))[1];
            }

            return count($actual) < 4;
        }, 'hash');

        $this->assertSame($expected, $actual);
    }
}
