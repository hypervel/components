<?php

declare(strict_types=1);

namespace Hypervel\Container\Attributes;

use Attribute;
use Hypervel\Contracts\Container\Container;
use Hypervel\Contracts\Container\ContextualAttribute;
use Hypervel\Log\Logger;
use Psr\Log\LoggerInterface;
use UnitEnum;

use function Hypervel\Support\enum_value;

#[Attribute(Attribute::TARGET_PARAMETER)]
class Log implements ContextualAttribute
{
    /**
     * Create a new class instance.
     *
     * @param null|string|UnitEnum $channel the log configuration's channel name
     * @param null|string|UnitEnum $name The name to prefix all logs with. Only to be used with Monolog drivers.
     */
    public function __construct(
        public UnitEnum|string|null $channel = null,
        public UnitEnum|string|null $name = null,
    ) {
    }

    /**
     * Resolve the log channel.
     */
    public static function resolve(self $attribute, Container $container): LoggerInterface
    {
        $channel = $attribute->channel instanceof UnitEnum
            ? (string) enum_value($attribute->channel)
            : $attribute->channel;

        /** @var Logger $logger */
        $logger = $container->make('log')->channel($channel);

        if ($attribute->name !== null) {
            $name = $attribute->name instanceof UnitEnum
                ? (string) enum_value($attribute->name)
                : $attribute->name;

            $logger = $logger->withName($name);
        }

        return $logger;
    }
}
