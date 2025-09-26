<?php

declare(strict_types=1);

namespace Hypervel\Sentry\Integrations;

use Sentry\Event;
use Sentry\Integration\IntegrationInterface;
use Sentry\SentrySdk;
use Sentry\State\Scope;
use Sentry\UserDataBag;

class RequestIntegration implements IntegrationInterface
{
    public function setupOnce(): void
    {
        Scope::addGlobalEventProcessor(function (Event $event): Event {
            $hub = SentrySdk::getCurrentHub();
            $self = $hub->getIntegration(self::class);

            if (! $self instanceof self || ! $hub->getClient()?->getOptions()->shouldSendDefaultPii()) {
                return $event;
            }

            $ip = request()->ip();

            if (! $user = $event->getUser()) {
                $user = UserDataBag::createFromUserIpAddress($ip);
            } else {
                $user->setIpAddress($ip);
            }

            $event->setUser($user);

            return $event;
        });
    }
}
