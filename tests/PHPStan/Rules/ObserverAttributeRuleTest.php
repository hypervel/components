<?php

declare(strict_types=1);

namespace Hypervel\Tests\PHPStan\Rules;

use Composer\Autoload\ClassLoader;
use Hypervel\PHPStan\Rules\ObserverAttributeRule;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;

/** @extends RuleTestCase<ObserverAttributeRule> */
class ObserverAttributeRuleTest extends RuleTestCase
{
    protected function getRule(): Rule
    {
        return self::getContainer()->getByType(ObserverAttributeRule::class);
    }

    public function testRule(): void
    {
        $error = static fn (string $model, int $line): array => [
            'Hypervel\Tests\PHPStan\Rules\ObserverAttributes\\' . $model . ' registers its observers with observe() in a service provider.',
            $line,
            'By convention, list them in #[ObservedBy] on the model. Subclasses inherit attribute observers, so keep observe() with an ignore when they are meant for this class only.',
        ];

        // The loader's installed packages are dependencies, whose models the application cannot change.
        $dependencies = new ClassLoader(__DIR__ . '/data/observer-vendor');
        $dependencies->register();

        try {
            $this->analyse([
                __DIR__ . '/data/observer-attributes.php',
                __DIR__ . '/data/observer-vendor/acme/AuditEntry.php',
                __DIR__ . '/data/observer-packages/ledger/LedgerEntry.php',
            ], [
                $error('Invoice', 41),
                $error('Payment', 46),
                $error('Payment', 47),
            ]);
        } finally {
            $dependencies->unregister();
        }
    }

    public static function getAdditionalConfigFiles(): array
    {
        return [__DIR__ . '/phpstan-rules.neon'];
    }
}
