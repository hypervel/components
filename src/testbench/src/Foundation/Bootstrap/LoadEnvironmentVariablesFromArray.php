<?php

declare(strict_types=1);

namespace Hypervel\Testbench\Foundation\Bootstrap;

use Dotenv\Parser\Entry;
use Dotenv\Parser\Parser;
use Dotenv\Store\StringStore;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Support\Collection;
use Hypervel\Testbench\Foundation\Env;

/**
 * @internal
 */
final class LoadEnvironmentVariablesFromArray
{
    /**
     * Construct a new environment bootstrapper.
     *
     * @param array<int, mixed> $environmentVariables
     */
    public function __construct(
        public readonly array $environmentVariables
    ) {
    }

    /**
     * Bootstrap the given application.
     */
    public function bootstrap(Application $app): void
    {
        $store = new StringStore(implode(PHP_EOL, $this->environmentVariables));
        $parser = new Parser();

        (new Collection($parser->parse($store->read())))
            ->filter(static fn (Entry $entry): bool => $entry->getValue()->isDefined())
            ->each(static function (Entry $entry): void {
                Env::set($entry->getName(), $entry->getValue()->get()->getChars());
            });
    }
}
