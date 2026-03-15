<?php

declare(strict_types=1);

namespace Hypervel\Sentry\Integrations;

use Sentry\Integration\IntegrationInterface;

// @TODO: Enable once Hypervel\Log\Context\Repository is ported
// This integration adds log context data to Sentry events using the Log Context Repository.
class ContextIntegration implements IntegrationInterface
{
    public function setupOnce(): void
    {
        // @TODO: Uncomment once Hypervel\Log\Context\Repository is ported
        // use Hypervel\Log\Context\Repository as ContextRepository;
        //
        // if (! class_exists(ContextRepository::class)) {
        //     return;
        // }
        //
        // Scope::addGlobalEventProcessor(static function (Event $event, ?EventHint $hint = null): Event {
        //     $self = SentrySdk::getCurrentHub()->getIntegration(self::class);
        //
        //     if (! $self instanceof self) {
        //         return $event;
        //     }
        //
        //     if (! in_array($event->getType(), [EventType::event(), EventType::transaction()], true)) {
        //         return $event;
        //     }
        //
        //     $event->setContext('hypervel', app(ContextRepository::class)->all());
        //
        //     return $event;
        // });
    }
}
