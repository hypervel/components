<?php

declare(strict_types=1);

namespace Hypervel\Tests\Sentry\Tracing;

use Hypervel\Sentry\Tracing\EventHandler;
use Hypervel\Tests\Sentry\SentryTestCase;
use ReflectionClass;
use RuntimeException;

/**
 * @internal
 * @coversNothing
 */
class EventHandlerTest extends SentryTestCase
{
    public function testMissingEventHandlerThrowsException(): void
    {
        $this->expectException(RuntimeException::class);

        $handler = new EventHandler([]);

        /* @noinspection PhpUndefinedMethodInspection */
        $handler->thisIsNotAHandlerAndShouldThrowAnException();
    }

    public function testAllMappedEventHandlersExist(): void
    {
        $this->tryAllEventHandlerMethods(
            $this->getEventHandlerMapFromEventHandler()
        );
    }

    private function tryAllEventHandlerMethods(array $methods): void
    {
        $handler = new EventHandler([]);

        $methods = array_map(static function ($method) {
            return "{$method}Handler";
        }, array_unique(array_values($methods)));

        foreach ($methods as $handlerMethod) {
            $this->assertTrue(method_exists($handler, $handlerMethod));
        }
    }

    private function getEventHandlerMapFromEventHandler(): array
    {
        $class = new ReflectionClass(EventHandler::class);

        $attributes = $class->getStaticProperties();

        return $attributes['eventHandlerMap'];
    }
}
