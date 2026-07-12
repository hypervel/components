<?php

declare(strict_types=1);

namespace Hypervel\Tests\Testing;

use Hypervel\Tests\TestCase;
use Hypervel\Tests\Testing\Fixtures\CleanupActions;
use RuntimeException;

class CleanupActionsTest extends TestCase
{
    public function testEveryActionRunsAndTheFirstFailureRemainsPrimary(): void
    {
        $first = new RuntimeException('first');
        $actions = [];

        try {
            CleanupActions::run(
                static function () use (&$actions, $first): void {
                    $actions[] = 'first';

                    throw $first;
                },
                static function () use (&$actions): void {
                    $actions[] = 'second';
                },
                static function () use (&$actions): void {
                    $actions[] = 'third';

                    throw new RuntimeException('third');
                },
            );

            $this->fail('Expected cleanup to rethrow its first failure.');
        } catch (RuntimeException $exception) {
            $this->assertSame($first, $exception);
        }

        $this->assertSame(['first', 'second', 'third'], $actions);
    }
}
