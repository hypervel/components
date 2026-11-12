<?php

declare(strict_types=1);

namespace Hypervel\Tests\PHPStan\Rules;

use Hypervel\ApiClient\PendingRequest;
use Hypervel\Container\Container;
use Hypervel\Database\Schema\ColumnDefinition;
use Hypervel\PHPStan\Rules\UnsupportedCallRule;
use Hypervel\PHPStan\Support\FacadeHelper;
use Hypervel\PHPStan\Support\RuntimeInputs;
use Hypervel\Tests\PHPStan\Rules\UnsupportedCall\Service;
use Hypervel\Tests\PHPStan\Rules\UnsupportedCall\ServiceContract;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;

use function sprintf;

/** @extends RuleTestCase<UnsupportedCallRule> */
class UnsupportedCallRuleTest extends RuleTestCase
{
    private const string MESSAGE = '%s::%s() is not supported by this implementation and always throws.';

    protected function getRule(): Rule
    {
        $facadeHelper = new FacadeHelper(
            $this->createReflectionProvider(),
            self::getContainer()->getService('cachedRichParser'),
            self::getContainer()->getByType(RuntimeInputs::class),
        );

        $facadeHelper->setContainer($this->createApplication());

        return new UnsupportedCallRule($this->createReflectionProvider(), $facadeHelper);
    }

    public function testRule(): void
    {
        $bare = sprintf(self::MESSAGE, Service::class, 'bare');
        $guided = sprintf(self::MESSAGE, Service::class, 'guided') . "\nUse supported() instead.";

        $this->analyse([__DIR__ . '/data/unsupported-calls.php'], [
            [$bare, 178],
            [$bare, 179],
            [$guided, 180],
            [sprintf(self::MESSAGE, PendingRequest::class, 'setClient') . "\nUse setHandler() to configure a request-specific transport handler.", 181],
            [$bare, 183],
            [sprintf(self::MESSAGE, ColumnDefinition::class, 'whereNotNull') . "\nChain it onto an index: \$table->index(...)->whereNotNull(...).", 184],
            [$guided, 185],
        ]);
    }

    public static function getAdditionalConfigFiles(): array
    {
        return [__DIR__ . '/phpstan-rules.neon'];
    }

    /**
     * Create the booted application the facade roots are read from.
     */
    private function createApplication(): Container
    {
        $container = new Container;

        $container->singleton('unsupported.factory', static fn (): Service => new Service);
        $container->instance('unsupported.instance', new ColumnDefinition);
        $container->bind('unsupported.chain', ServiceContract::class);
        $container->bind(ServiceContract::class, Service::class);

        // An extender can return another object.
        $container->singleton('unsupported.extended', static fn (): Service => new Service);
        $container->extend('unsupported.extended', static fn (Service $service): Service => $service);

        // Two factories on one line, which reflection's line numbers cannot tell apart.
        [$factory] = [static fn (): Service => new Service, static fn (): Service => new Service];
        $container->bind('unsupported.shared-line', $factory);

        return $container;
    }
}
