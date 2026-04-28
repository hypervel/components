<?php

declare(strict_types=1);

namespace Hypervel\Tests\Scout\Unit\Console\Traits;

use Hypervel\Scout\Builder;
use Hypervel\Scout\Console\Traits\ResolvesScoutModelClass;
use Hypervel\Scout\Exceptions\ScoutException;
use Hypervel\Testbench\TestCase;

class ResolvesScoutModelClassTest extends TestCase
{
    public function testResolvesFullyQualifiedClassName(): void
    {
        $this->assertSame(Builder::class, $this->resolver()->resolve(Builder::class));
    }

    public function testResolvesShortClassNameUnderAppModelsNamespace(): void
    {
        $aliasedFqcn = app()->getNamespace() . 'Models\AliasedScoutTestModel';

        if (! class_exists($aliasedFqcn)) {
            class_alias(Builder::class, $aliasedFqcn);
        }

        $this->assertSame(
            $aliasedFqcn,
            $this->resolver()->resolve('AliasedScoutTestModel'),
        );
    }

    public function testThrowsScoutExceptionForNonExistentClass(): void
    {
        $this->expectException(ScoutException::class);
        $this->expectExceptionMessage('Model [NonExistentModel] not found.');

        $this->resolver()->resolve('NonExistentModel');
    }

    private function resolver(): object
    {
        return new class {
            use ResolvesScoutModelClass {
                resolveModelClass as public resolve;
            }
        };
    }
}
