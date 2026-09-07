<?php

declare(strict_types=1);

namespace Hypervel\Tests\NestedSet;

use Hypervel\Foundation\Testing\DatabaseTruncation;
use Hypervel\Support\Facades\DB;
use Hypervel\Testbench\TestCase;
use Hypervel\Tests\NestedSet\Models\Category;
use RuntimeException;

use function Hypervel\Coroutine\parallel;

class NestedSetMutationLifecycleTest extends TestCase
{
    use DatabaseTruncation;

    protected function migrateFreshUsing(): array
    {
        return [
            '--seed' => false,
            '--realpath' => true,
            '--path' => __DIR__ . '/migrations',
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();

        DB::table('categories')->insert([
            ['id' => 1, 'name' => 'first root', '_lft' => 1, '_rgt' => 8, 'parent_id' => null, 'depth' => 0],
            ['id' => 2, 'name' => 'first child', '_lft' => 2, '_rgt' => 3, 'parent_id' => 1, 'depth' => 1],
            ['id' => 3, 'name' => 'second child', '_lft' => 4, '_rgt' => 5, 'parent_id' => 1, 'depth' => 1],
            ['id' => 4, 'name' => 'third child', '_lft' => 6, '_rgt' => 7, 'parent_id' => 1, 'depth' => 1],
            ['id' => 5, 'name' => 'second root', '_lft' => 9, '_rgt' => 10, 'parent_id' => null, 'depth' => 0],
        ]);
    }

    public function testTransactionRetryPreparesParticipantsFromTheRolledBackTree(): void
    {
        $source = Category::findOrFail(2);
        $target = Category::findOrFail(5);
        $attempts = 0;
        $retry = new RuntimeException('database is locked');

        DB::transaction(function () use ($source, $target, &$attempts, $retry): void {
            ++$attempts;
            $source->appendToNode($target)->save();

            if ($attempts === 1) {
                throw $retry;
            }
        }, 2);

        $persistedSource = Category::findOrFail($source->getKey());

        $this->assertSame(2, $attempts);
        $this->assertSame($target->getKey(), $persistedSource->getParentId());
        $this->assertSame([8, 9], $persistedSource->getBounds());
        $this->assertSame($persistedSource->getBounds(), $source->getBounds());
        $this->assertTreeNotBroken();
    }

    public function testCompletedParentChildMutationsRemainCorrectAcrossFreshCoroutines(): void
    {
        $source = Category::findOrFail(2);
        $target = Category::findOrFail(5);

        parallel([
            static function (): void {
                DB::transaction(static function (): void {
                    Category::findOrFail(4)
                        ->appendToNode(Category::findOrFail(5))
                        ->save();
                });
            },
        ]);

        parallel([
            static function () use ($source, $target): void {
                DB::transaction(static function () use ($source, $target): void {
                    $source->appendToNode($target)->save();
                });
            },
        ]);

        $this->assertSame($target->getKey(), Category::findOrFail($source->getKey())->getParentId());
        $this->assertTreeNotBroken();
    }

    public function testCompletedSiblingMutationsRemainCorrectAcrossFreshCoroutines(): void
    {
        $source = Category::findOrFail(2);
        $target = Category::findOrFail(3);

        parallel([
            static function (): void {
                DB::transaction(static function (): void {
                    Category::query()->moveNode(5, 1);
                });
            },
        ]);

        parallel([
            static function () use ($source, $target): void {
                DB::transaction(static function () use ($source, $target): void {
                    $source->afterNode($target)->save();
                });
            },
        ]);

        $persistedSource = Category::findOrFail($source->getKey());
        $persistedTarget = Category::findOrFail($target->getKey());

        $this->assertSame($persistedTarget->getParentId(), $persistedSource->getParentId());
        $this->assertGreaterThan($persistedTarget->getRgt(), $persistedSource->getLft());
        $this->assertTreeNotBroken();
    }

    /**
     * Assert that the persisted tree has no structural errors.
     */
    private function assertTreeNotBroken(): void
    {
        $this->assertSame([
            'invalid_intervals' => 0,
            'duplicate_endpoints' => 0,
            'missing_endpoints' => 0,
            'crossing_intervals' => 0,
            'missing_parent' => 0,
            'wrong_parent' => 0,
            'wrong_depth' => 0,
        ], Category::countErrors());
    }
}
