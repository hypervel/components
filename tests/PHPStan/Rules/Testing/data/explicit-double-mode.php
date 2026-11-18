<?php

declare(strict_types=1);

namespace Hypervel\Tests\PHPStan\Rules\Testing\Data;

use Hypervel\Config\Repository;
use Hypervel\Support\Sleep;
use JMac\Testing\Double as TestDouble;

class ExplicitDoubleMode
{
    private Repository $repository;

    private static Repository $cache;

    public function setup(bool $useCache): void
    {
        TestDouble::for(Repository::class)->strict();
        $this->exercise(TestDouble::for(Repository::class)->passthru());
        $this->exercise(TestDouble::for(Repository::class));
        $factory = TestDouble::for(...);
        Sleep::for(0);
        ($useCache ? TestDouble::for(Repository::class) : TestDouble::for(Repository::class))->strict();
        TestDouble::for(Repository::class, override: true)->strict()->instance();

        $this->repository = TestDouble::for(Repository::class);
        self::$cache = TestDouble::for(Repository::class);
        $this->repository->allows('get')->returns(null);
        self::$cache->passthru();
        $this->repository->strict();

        if ($useCache) {
            $double = $useCache ? TestDouble::for(Repository::class) : TestDouble::for(Repository::class);
            $double->expects('get')->returns('value');
            $double->strict();
        }

        $used = TestDouble::for(Repository::class);
        $this->exercise($used);
        $used->strict();

        $conditional = TestDouble::for(Repository::class);
        if ($useCache) {
            $conditional->strict();
        }

        $replaced = TestDouble::for(Repository::class);
        $replaced = TestDouble::for(Repository::class);
        $replaced->strict();

        $callable = TestDouble::for(Repository::class);
        $callable->strict(...);

        $this->repository = TestDouble::for(Repository::class);
        self::$cache->strict();

        TestDouble::for(Repository::class)->strict()->allows('get')->returns(TestDouble::for(Repository::class));

        $late = TestDouble::for(Repository::class);
        $late->allows('get')->returns($late->get('fallback'));
        $late->strict();

        $deferred = fn () => \JMac\Testing\Double::for(Repository::class);
        $deferred()->strict();
    }

    public function configuredFactory(bool $useCache): Repository
    {
        $double = match ($useCache) {
            true => TestDouble::for(Repository::class),
            false => TestDouble::for(Repository::class),
        };

        return $double->strict();
    }

    public function exercise(Repository $value): void
    {
    }
}
