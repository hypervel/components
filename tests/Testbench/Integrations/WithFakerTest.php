<?php

declare(strict_types=1);

namespace Hypervel\Tests\Testbench\Integrations;

use Faker\Generator;
use Hypervel\Testbench\Attributes\WithConfig;
use Hypervel\Tests\Testbench\TestCase;
use PHPUnit\Framework\Attributes\Test;

class WithFakerTest extends TestCase
{
    #[Test]
    public function itCanUseFaker(): void
    {
        $this->assertInstanceOf(Generator::class, $this->faker);
    }

    #[Test]
    #[WithConfig('app.faker_locale', 'it_IT')]
    public function itCanOverrideFakerLocale(): void
    {
        $providerNames = array_map(
            static fn (object $provider): string => $provider::class,
            $this->faker()->getProviders()
        );

        $this->assertContains('Faker\Provider\it_IT\Person', $providerNames);
    }
}
