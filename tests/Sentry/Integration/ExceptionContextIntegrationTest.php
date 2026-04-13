<?php

declare(strict_types=1);

namespace Hypervel\Tests\Sentry\Integration;

use Exception;
use Hypervel\Sentry\Integration\ExceptionContextIntegration;
use Hypervel\Tests\Sentry\SentryTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Sentry\Event;
use Sentry\EventHint;
use Sentry\State\Scope;

use function Sentry\withScope;

/**
 * @internal
 * @coversNothing
 */
class ExceptionContextIntegrationTest extends SentryTestCase
{
    public function testExceptionContextIntegrationIsRegistered(): void
    {
        $integration = $this->getSentryHubFromContainer()->getIntegration(ExceptionContextIntegration::class);

        $this->assertInstanceOf(ExceptionContextIntegration::class, $integration);
    }

    #[DataProvider('invokeDataProvider')]
    public function testInvoke(Exception $exception, ?array $expectedContext): void
    {
        withScope(function (Scope $scope) use ($exception, $expectedContext): void {
            $event = Event::createEvent();

            $event = $scope->applyToEvent($event, EventHint::fromArray(compact('exception')));

            $this->assertNotNull($event);

            $exceptionContext = $event->getExtra()['exception_context'] ?? null;

            $this->assertSame($expectedContext, $exceptionContext);
        });
    }

    public static function invokeDataProvider(): iterable
    {
        yield 'Exception without context method -> no exception context' => [
            new Exception('Exception without context.'),
            null,
        ];

        $context = ['some' => 'context'];

        yield 'Exception with context method returning array of context' => [
            self::generateExceptionWithContext($context),
            $context,
        ];

        yield 'Exception with context method returning string of context' => [
            self::generateExceptionWithContext('Invalid context, expects array'),
            null,
        ];
    }

    private static function generateExceptionWithContext(mixed $context): Exception
    {
        return new class($context) extends Exception {
            public function __construct(
                private readonly mixed $context,
            ) {
                parent::__construct('Exception with context.');
            }

            public function context(): mixed
            {
                return $this->context;
            }
        };
    }
}
