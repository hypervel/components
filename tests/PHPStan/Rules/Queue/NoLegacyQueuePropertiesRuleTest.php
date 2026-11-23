<?php

declare(strict_types=1);

namespace Hypervel\Tests\PHPStan\Rules\Queue;

use Hypervel\PHPStan\Rules\Queue\NoLegacyQueuePropertiesRule;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;

/** @extends RuleTestCase<NoLegacyQueuePropertiesRule> */
class NoLegacyQueuePropertiesRuleTest extends RuleTestCase
{
    protected function getRule(): Rule
    {
        return self::getContainer()->getByType(NoLegacyQueuePropertiesRule::class);
    }

    public function testRule(): void
    {
        $data = 'Hypervel\Tests\PHPStan\Rules\Queue\Data\\';
        $subclasses = "Subclasses' own properties and attributes can take precedence differently: review them, and keep the property where attributes cannot express the hierarchy. See https://hypervel.org/docs/static-analysis#queue-configuration";
        $tip = "\n    💡 " . $subclasses;

        $this->analyse([__DIR__ . '/data/legacy-queue-properties.php'], [
            ['Queue property ' . $data . 'SendInvoice::$tries should be replaced by attribute #[Tries(3)].' . $tip, 27],
            ['Queue property ' . $data . 'SendInvoice::$timeout should be replaced by attribute #[Timeout(120)].' . $tip, 29],
            ['Queue property ' . $data . 'SendInvoice::$maxExceptions should be replaced by attribute #[MaxExceptions(2)].' . $tip, 31],
            ['Queue property ' . $data . 'SendInvoice::$backoff should be replaced by attribute #[Backoff([1, 5, 10])].' . $tip, 33],
            ['Queue property ' . $data . 'SendInvoice::$failOnTimeout should be replaced by attribute #[FailOnTimeout].' . $tip, 35],
            ['Queue property ' . $data . 'SendInvoice::$countCrashesAsExceptions should be replaced by attribute #[CountCrashesAsExceptions].' . $tip, 37],
            ['Queue property ' . $data . 'SendInvoice::$deleteWhenMissingModels should be replaced by attribute #[DeleteWhenMissingModels].' . $tip, 39],
            ['Queue property ' . $data . 'SendReminder::$queue should be replaced by attribute #[Queue(\'emails\')].' . $tip, 44],
            ['Queue property ' . $data . 'SendReminder::$delay should be replaced by attribute #[Delay(30)].' . $tip, 46],
            ['Queue property ' . $data . 'SendReminder::$tries should be removed: the same value applies without it.' . $tip, 49],
            ['Queue property ' . $data . 'SendWelcome::$connection should be replaced by attribute #[Connection(\Hypervel\Tests\PHPStan\Rules\Queue\Data\QueueConnection::Redis)].' . $tip, 54],
            ['Queue property ' . $data . 'ImportFeed::$uniqueFor should be replaced by attribute #[UniqueFor(3600)].' . $tip, 65],
            ["Queue property {$data}RebuildSearchIndex::\$debounceFor should be replaced by attribute #[DebounceFor(10, maxWait: 300)].\n    💡 • {$subclasses}\n• A subclass's own #[DebounceFor] replaces this one entirely, so it must repeat the maximum wait it inherits.", 75],
            ['Queue property ' . $data . 'SyncAccount::$tries should be removed: the same value applies without it.' . $tip, 82],
            ['Queue property $tries of trait ' . $data . 'RetriesThreeTimes should be replaced by attribute #[Tries(3)] on the trait.' . $tip, 119],
            ['Queue property ' . $data . 'AliasedConstructorJob::$tries should be replaced by attribute #[Tries(2)].' . $tip, 150],
            ['Queue property ' . $data . 'InvoiceMail::$tries should be replaced by attribute #[Tries(3)].' . $tip, 155],
            ['Queue property ' . $data . 'InvoicePaid::$timeout should be replaced by attribute #[Timeout(60)].' . $tip, 167],
            ['Queue property ' . $data . 'OrderShipped::$queue should be replaced by attribute #[Queue(\'broadcasts\')].' . $tip, 177],
            ['Queue property ' . $data . 'SendDigest::$tries should be replaced by attribute #[Tries(4)].' . $tip, 205],
        ]);
    }

    /** @return string[] */
    public static function getAdditionalConfigFiles(): array
    {
        return [__DIR__ . '/../../phpstan-tests.neon'];
    }
}
