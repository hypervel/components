<?php

declare(strict_types=1);

namespace Hypervel\Tests\Support;

use Hypervel\Context\Context;
use Hypervel\Support\Number;
use Hypervel\Tests\TestCase;

use function Hypervel\Coroutine\parallel;
use function Hypervel\Coroutine\run;

/**
 * @internal
 * @coversNothing
 * @requires extension intl
 */
class NumberTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Context::destroyAll();
    }

    protected function tearDown(): void
    {
        Context::destroyAll();

        parent::tearDown();
    }

    // ==========================================================================
    // Basic Formatting Tests
    // ==========================================================================

    public function testFormat(): void
    {
        $this->assertSame('1', Number::format(1));
        $this->assertSame('10', Number::format(10));
        $this->assertSame('25', Number::format(25));
        $this->assertSame('100', Number::format(100));
        $this->assertSame('1,000', Number::format(1000));
        $this->assertSame('1,000,000', Number::format(1000000));
        $this->assertSame('123,456,789', Number::format(123456789));
    }

    public function testFormatWithPrecision(): void
    {
        $this->assertSame('1.00', Number::format(1, precision: 2));
        $this->assertSame('1.20', Number::format(1.2, precision: 2));
        $this->assertSame('1.23', Number::format(1.234, precision: 2));
        $this->assertSame('1.1', Number::format(1.123, maxPrecision: 1));
    }

    public function testFormatWithLocale(): void
    {
        $this->assertSame('1,234.56', Number::format(1234.56, precision: 2, locale: 'en'));
        $this->assertSame('1.234,56', Number::format(1234.56, precision: 2, locale: 'de'));
        // French uses non-breaking space as thousands separator
        $this->assertStringContainsString('234', Number::format(1234.56, precision: 2, locale: 'fr'));
        $this->assertStringContainsString(',56', Number::format(1234.56, precision: 2, locale: 'fr'));
    }

    public function testSpell(): void
    {
        $this->assertSame('one', Number::spell(1));
        $this->assertSame('ten', Number::spell(10));
        $this->assertSame('one hundred twenty-three', Number::spell(123));
    }

    public function testSpellWithAfter(): void
    {
        $this->assertSame('10', Number::spell(10, after: 10));
        $this->assertSame('eleven', Number::spell(11, after: 10));
    }

    public function testSpellWithUntil(): void
    {
        $this->assertSame('nine', Number::spell(9, until: 10));
        $this->assertSame('10', Number::spell(10, until: 10));
    }

    public function testOrdinal(): void
    {
        $this->assertSame('1st', Number::ordinal(1));
        $this->assertSame('2nd', Number::ordinal(2));
        $this->assertSame('3rd', Number::ordinal(3));
        $this->assertSame('4th', Number::ordinal(4));
        $this->assertSame('21st', Number::ordinal(21));
    }

    public function testPercentage(): void
    {
        $this->assertSame('0%', Number::percentage(0));
        $this->assertSame('1%', Number::percentage(1));
        $this->assertSame('50%', Number::percentage(50));
        $this->assertSame('100%', Number::percentage(100));
        $this->assertSame('12.34%', Number::percentage(12.34, precision: 2));
    }

    public function testCurrency(): void
    {
        $this->assertSame('$0.00', Number::currency(0));
        $this->assertSame('$1.00', Number::currency(1));
        $this->assertSame('$1,000.00', Number::currency(1000));
    }

    public function testCurrencyWithDifferentCurrency(): void
    {
        $this->assertStringContainsString('1,000', Number::currency(1000, 'EUR'));
        $this->assertStringContainsString('1,000', Number::currency(1000, 'GBP'));
    }

    public function testFileSize(): void
    {
        $this->assertSame('0 B', Number::fileSize(0));
        $this->assertSame('1 B', Number::fileSize(1));
        $this->assertSame('1 KB', Number::fileSize(1024));
        $this->assertSame('1 MB', Number::fileSize(1024 * 1024));
        $this->assertSame('1 GB', Number::fileSize(1024 * 1024 * 1024));
    }

    public function testFileSizeWithPrecision(): void
    {
        $this->assertSame('1.50 KB', Number::fileSize(1536, precision: 2));
    }

    public function testAbbreviate(): void
    {
        $this->assertSame('0', Number::abbreviate(0));
        $this->assertSame('1', Number::abbreviate(1));
        $this->assertSame('1K', Number::abbreviate(1000));
        $this->assertSame('1M', Number::abbreviate(1000000));
        $this->assertSame('1B', Number::abbreviate(1000000000));
    }

    public function testForHumans(): void
    {
        $this->assertSame('0', Number::forHumans(0));
        $this->assertSame('1', Number::forHumans(1));
        $this->assertSame('1 thousand', Number::forHumans(1000));
        $this->assertSame('1 million', Number::forHumans(1000000));
        $this->assertSame('1 billion', Number::forHumans(1000000000));
    }

    public function testClamp(): void
    {
        $this->assertSame(5, Number::clamp(5, 1, 10));
        $this->assertSame(1, Number::clamp(0, 1, 10));
        $this->assertSame(10, Number::clamp(15, 1, 10));
        $this->assertSame(5.5, Number::clamp(5.5, 1.0, 10.0));
    }

    public function testPairs(): void
    {
        $this->assertSame([[1, 10], [11, 20], [21, 25]], Number::pairs(25, 10));
        $this->assertSame([[0, 10], [10, 20], [20, 25]], Number::pairs(25, 10, 0));
    }

    public function testTrim(): void
    {
        $this->assertSame(1, Number::trim(1.0));
        $this->assertSame(1.5, Number::trim(1.50));
        $this->assertSame(1.23, Number::trim(1.230));
    }

    // ==========================================================================
    // Context-Based Locale/Currency Tests - These are critical for coroutine safety
    // ==========================================================================

    public function testUseLocaleStoresInContext(): void
    {
        $this->assertSame('en', Number::defaultLocale());

        Number::useLocale('de');

        $this->assertSame('de', Number::defaultLocale());
        $this->assertSame('de', Context::get('__support.number.locale'));
    }

    public function testUseCurrencyStoresInContext(): void
    {
        $this->assertSame('USD', Number::defaultCurrency());

        Number::useCurrency('EUR');

        $this->assertSame('EUR', Number::defaultCurrency());
        $this->assertSame('EUR', Context::get('__support.number.currency'));
    }

    public function testDefaultLocaleReturnsStaticDefaultWhenNotSet(): void
    {
        $this->assertSame('en', Number::defaultLocale());
        $this->assertNull(Context::get('__support.number.locale'));
    }

    public function testDefaultCurrencyReturnsStaticDefaultWhenNotSet(): void
    {
        $this->assertSame('USD', Number::defaultCurrency());
        $this->assertNull(Context::get('__support.number.currency'));
    }

    public function testWithLocaleTemporarilySetsLocale(): void
    {
        Number::useLocale('en');

        $result = Number::withLocale('de', function () {
            return Number::defaultLocale();
        });

        $this->assertSame('de', $result);
        $this->assertSame('en', Number::defaultLocale());
    }

    public function testWithCurrencyTemporarilySetsCurrency(): void
    {
        Number::useCurrency('USD');

        $result = Number::withCurrency('EUR', function () {
            return Number::defaultCurrency();
        });

        $this->assertSame('EUR', $result);
        $this->assertSame('USD', Number::defaultCurrency());
    }

    public function testWithLocaleRestoresPreviousContextValue(): void
    {
        // Set a custom locale first
        Number::useLocale('fr');

        // Then use withLocale to temporarily change it
        $result = Number::withLocale('de', function () {
            return Number::defaultLocale();
        });

        // Should have used 'de' during callback
        $this->assertSame('de', $result);
        // Should restore to 'fr' (the previous Context value), not 'en' (static default)
        $this->assertSame('fr', Number::defaultLocale());
    }

    public function testWithCurrencyRestoresPreviousContextValue(): void
    {
        // Set a custom currency first
        Number::useCurrency('GBP');

        // Then use withCurrency to temporarily change it
        $result = Number::withCurrency('EUR', function () {
            return Number::defaultCurrency();
        });

        // Should have used 'EUR' during callback
        $this->assertSame('EUR', $result);
        // Should restore to 'GBP' (the previous Context value), not 'USD' (static default)
        $this->assertSame('GBP', Number::defaultCurrency());
    }

    // ==========================================================================
    // Coroutine Isolation Tests - Critical for Swoole coroutine safety
    // ==========================================================================

    public function testLocaleIsIsolatedBetweenCoroutines(): void
    {
        $results = [];

        run(function () use (&$results): void {
            $results = parallel([
                function () {
                    Number::useLocale('de');
                    usleep(1000); // Small delay to allow interleaving
                    return Number::defaultLocale();
                },
                function () {
                    Number::useLocale('fr');
                    usleep(1000);
                    return Number::defaultLocale();
                },
            ]);
        });

        // Each coroutine should see its own locale, not affected by the other
        $this->assertContains('de', $results);
        $this->assertContains('fr', $results);
    }

    public function testCurrencyIsIsolatedBetweenCoroutines(): void
    {
        $results = [];

        run(function () use (&$results): void {
            $results = parallel([
                function () {
                    Number::useCurrency('EUR');
                    usleep(1000);
                    return Number::defaultCurrency();
                },
                function () {
                    Number::useCurrency('GBP');
                    usleep(1000);
                    return Number::defaultCurrency();
                },
            ]);
        });

        // Each coroutine should see its own currency
        $this->assertContains('EUR', $results);
        $this->assertContains('GBP', $results);
    }

    public function testLocaleDoesNotLeakBetweenCoroutines(): void
    {
        $leakedLocale = null;

        run(function () use (&$leakedLocale): void {
            parallel([
                function () {
                    Number::useLocale('de');
                    usleep(5000); // Hold the coroutine
                },
                function () use (&$leakedLocale) {
                    usleep(1000); // Let first coroutine set its locale
                    // This coroutine should NOT see 'de' from the other coroutine
                    $leakedLocale = Number::defaultLocale();
                },
            ]);
        });

        // Second coroutine should see the default 'en', not 'de' from first coroutine
        $this->assertSame('en', $leakedLocale);
    }

    public function testCurrencyDoesNotLeakBetweenCoroutines(): void
    {
        $leakedCurrency = null;

        run(function () use (&$leakedCurrency): void {
            parallel([
                function () {
                    Number::useCurrency('EUR');
                    usleep(5000);
                },
                function () use (&$leakedCurrency) {
                    usleep(1000);
                    $leakedCurrency = Number::defaultCurrency();
                },
            ]);
        });

        $this->assertSame('USD', $leakedCurrency);
    }

    // ==========================================================================
    // Regression Tests - Prevent the SUP-01 bug from recurring
    // ==========================================================================

    /**
     * Regression test for SUP-01: useCurrency was using Context::get instead of Context::set.
     */
    public function testUseCurrencyActuallySetsValue(): void
    {
        // Before the fix, useCurrency() called Context::get() which doesn't set anything
        $this->assertNull(Context::get('__support.number.currency'));

        Number::useCurrency('JPY');

        // After calling useCurrency, the value should be set in Context
        $this->assertSame('JPY', Context::get('__support.number.currency'));
        $this->assertSame('JPY', Number::defaultCurrency());
    }

    /**
     * Ensures useCurrency changes actually affect subsequent currency() calls
     * when using defaultCurrency().
     */
    public function testUseCurrencyAffectsDefaultCurrency(): void
    {
        // Set currency and verify defaultCurrency returns it
        Number::useCurrency('CAD');
        $this->assertSame('CAD', Number::defaultCurrency());

        // Change it again
        Number::useCurrency('AUD');
        $this->assertSame('AUD', Number::defaultCurrency());
    }

    /**
     * Ensures useLocale changes actually affect subsequent formatting calls
     * when using defaultLocale().
     */
    public function testUseLocaleAffectsDefaultLocale(): void
    {
        Number::useLocale('ja');
        $this->assertSame('ja', Number::defaultLocale());

        Number::useLocale('zh');
        $this->assertSame('zh', Number::defaultLocale());
    }
}
