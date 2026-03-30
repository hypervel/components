<?php

declare(strict_types=1);

namespace Hypervel\Sentry\Integrations;

use Hypervel\Log\Context\Repository;
use Sentry\Event;
use Sentry\EventHint;
use Sentry\EventType;
use Sentry\Integration\IntegrationInterface;
use Sentry\SentrySdk;
use Sentry\State\Scope;

/**
 * Adds log context data to Sentry events.
 */
class ContextIntegration implements IntegrationInterface
{
    public function setupOnce(): void
    {
        Scope::addGlobalEventProcessor(static function (Event $event, ?EventHint $hint = null): Event {
            $self = SentrySdk::getCurrentHub()->getIntegration(self::class);

            if (! $self instanceof self) {
                return $event;
            }

            if (! in_array($event->getType(), [EventType::event(), EventType::transaction()], true)) {
                return $event;
            }

            if (Repository::hasInstance()) {
                $event->setContext('hypervel', Repository::getInstance()->all());
            }

            return $event;
        });
    }
}
