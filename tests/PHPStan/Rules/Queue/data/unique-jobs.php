<?php

declare(strict_types=1);

namespace Hypervel\Tests\PHPStan\Rules\Queue\Data;

use Hypervel\Contracts\Queue\ShouldBeUnique;
use Hypervel\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Hypervel\Contracts\Queue\ShouldQueue;
use Hypervel\Foundation\Bus\Dispatchable;
use Hypervel\Queue\Attributes\UniqueFor;

class RegularJob implements ShouldQueue
{
    use Dispatchable;

    public function __construct(public int $productId)
    {
    }

    public function handle(): void
    {
    }
}

abstract class AbstractUniqueJob implements ShouldQueue, ShouldBeUnique
{
    public function __construct(public int $companyId)
    {
    }
}

class UniqueJobWithUniqueForProperty implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable;

    public int $uniqueFor = 3600;
}

class UniqueJobWithUniqueForMethod implements ShouldQueue, ShouldBeUnique
{
    public function uniqueFor(): int
    {
        return 3600;
    }
}

class UniqueJobWithoutUniqueFor implements ShouldQueue, ShouldBeUnique
{
    public function uniqueId(): string
    {
        return 'constant';
    }
}

class UniqueJobInheritingUniqueFor extends AbstractUniqueJob
{
    public int $uniqueFor = 3600;

    public function uniqueId(): string
    {
        return (string) $this->companyId;
    }
}

class ParameterizedUniqueJobWithoutUniqueId implements ShouldQueue, ShouldBeUnique
{
    public int $uniqueFor = 3600;

    public function __construct(public int $companyId)
    {
    }
}

class ParameterizedUniqueJobWithUniqueIdMethod implements ShouldQueue, ShouldBeUnique
{
    public int $uniqueFor = 3600;

    public function __construct(public int $companyId)
    {
    }

    public function uniqueId(): string
    {
        return (string) $this->companyId;
    }
}

class ParameterizedUniqueJobWithUniqueIdProperty implements ShouldQueue, ShouldBeUnique
{
    public int $uniqueFor = 3600;

    public string $uniqueId = 'fixed';

    public function __construct(public int $companyId)
    {
    }
}

class UniqueJobWithParameterlessConstructor implements ShouldQueue, ShouldBeUnique
{
    public int $uniqueFor = 3600;

    public function __construct()
    {
    }
}

class UniqueUntilProcessingJob implements ShouldQueue, ShouldBeUniqueUntilProcessing
{
    public function __construct(public int $companyId)
    {
    }
}

// Hypervel: #[UniqueFor] declares the duration, read from a parent or a used trait as the lock reads it.
#[UniqueFor(3600)]
abstract class AbstractUniqueJobWithUniqueForAttribute implements ShouldQueue, ShouldBeUnique
{
}

class UniqueJobInheritingUniqueForAttribute extends AbstractUniqueJobWithUniqueForAttribute
{
}

#[UniqueFor(3600)]
trait UniqueForAnHour
{
}

class UniqueJobUsingUniqueForTrait implements ShouldQueue, ShouldBeUnique
{
    use UniqueForAnHour;
}

// Hypervel: the unique lock calls a uniqueFor() or uniqueId() method from outside the job, so the
// method wins and must be public. It reads only a public property.
#[UniqueFor(3600)]
class UniqueJobWithProtectedUniqueForMethod implements ShouldQueue, ShouldBeUnique
{
    protected function uniqueFor(): int
    {
        return 3600;
    }
}

class UniqueJobWithProtectedUniqueForProperty implements ShouldQueue, ShouldBeUnique
{
    protected int $uniqueFor = 3600;
}

class ParameterizedUniqueJobWithPrivateUniqueIdMethod implements ShouldQueue, ShouldBeUnique
{
    public int $uniqueFor = 3600;

    public string $uniqueId = 'fixed';

    public function __construct(public int $companyId)
    {
    }

    private function uniqueId(): string
    {
        return 'fixed';
    }
}

class ParameterizedUniqueJobWithProtectedUniqueIdProperty implements ShouldQueue, ShouldBeUnique
{
    public int $uniqueFor = 3600;

    protected string $uniqueId = 'fixed';

    public function __construct(public int $companyId)
    {
    }
}
