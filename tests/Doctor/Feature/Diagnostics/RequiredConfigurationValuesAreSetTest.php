<?php

declare(strict_types=1);

namespace Hypervel\Tests\Doctor\Feature\Diagnostics;

use Hypervel\Doctor\Diagnostics\RequiredConfigurationValuesAreSet;
use Hypervel\Doctor\Results\Link;
use Hypervel\Tests\Doctor\TestCase;
use Monolog\Handler\SyslogUdpHandler;
use PHPUnit\Framework\Attributes\DataProvider;

class RequiredConfigurationValuesAreSetTest extends TestCase
{
    public function testPassesWithTheDefaultConfiguration(): void
    {
        $result = (new RequiredConfigurationValuesAreSet)->check();

        $this->assertSame('pass', $result->status->value);
    }

    public function testReportsMissingCredentialsForTheActivePusherBroadcastConnection(): void
    {
        config([
            'broadcasting.default' => 'pusher',
            'broadcasting.connections.pusher.driver' => 'pusher',
            'broadcasting.connections.pusher.app_id' => null,
            'broadcasting.connections.pusher.key' => '',
            'broadcasting.connections.pusher.secret' => null,
        ]);

        $result = (new RequiredConfigurationValuesAreSet)->check();

        $this->assertSame('fail', $result->status->value);
        $this->assertStringContainsString('broadcasting.connections.pusher.app_id', $result->details);
        $this->assertStringContainsString('broadcasting.connections.pusher.key', $result->details);
        $this->assertStringContainsString('broadcasting.connections.pusher.secret', $result->details);
        $this->assertEquals([Link::docs('configuration', 'environment-configuration')], $result->links);
    }

    public function testPassesWhenTheActiveBroadcastConnectionIsFullyConfigured(): void
    {
        config([
            'broadcasting.default' => 'reverb',
            'broadcasting.connections.reverb.driver' => 'reverb',
            'broadcasting.connections.reverb.app_id' => '12345',
            'broadcasting.connections.reverb.key' => 'key',
            'broadcasting.connections.reverb.secret' => 'secret',
        ]);

        $result = (new RequiredConfigurationValuesAreSet)->check();

        $this->assertSame('pass', $result->status->value);
    }

    public function testIgnoresCredentialsForInactiveBroadcastConnections(): void
    {
        config([
            'broadcasting.default' => 'null',
            'broadcasting.connections.null.driver' => 'null',
            'broadcasting.connections.pusher.driver' => 'pusher',
            'broadcasting.connections.pusher.key' => null,
        ]);

        $result = (new RequiredConfigurationValuesAreSet)->check();

        $this->assertSame('pass', $result->status->value);
    }

    public function testResolvesRenamedBroadcastConnectionsByDriver(): void
    {
        config([
            'broadcasting.default' => 'websockets',
            'broadcasting.connections.websockets.driver' => 'pusher',
            'broadcasting.connections.websockets.app_id' => null,
            'broadcasting.connections.websockets.key' => null,
            'broadcasting.connections.websockets.secret' => null,
        ]);

        $result = (new RequiredConfigurationValuesAreSet)->check();

        $this->assertSame('fail', $result->status->value);
        $this->assertStringContainsString('broadcasting.connections.websockets.app_id', $result->details);
        $this->assertStringContainsString('broadcasting.connections.websockets.key', $result->details);
        $this->assertStringContainsString('broadcasting.connections.websockets.secret', $result->details);
    }

    public function testRequiresTheAblyKeyForAblyBroadcastConnections(): void
    {
        config([
            'broadcasting.default' => 'ably',
            'broadcasting.connections.ably.driver' => 'ably',
            'broadcasting.connections.ably.key' => null,
        ]);

        $result = (new RequiredConfigurationValuesAreSet)->check();

        $this->assertSame('fail', $result->status->value);
        $this->assertStringContainsString('broadcasting.connections.ably.key', $result->details);
        $this->assertStringNotContainsString('secret', $result->details);
    }

    public function testRequiresABucketAndRegionForTheDefaultS3FilesystemDisk(): void
    {
        config([
            'filesystems.default' => 'media',
            'filesystems.disks.media.driver' => 's3',
            'filesystems.disks.media.bucket' => null,
            'filesystems.disks.media.region' => null,
            'filesystems.disks.media.key' => null,
            'filesystems.disks.media.secret' => null,
        ]);

        $result = (new RequiredConfigurationValuesAreSet)->check();

        $this->assertSame('fail', $result->status->value);
        $this->assertStringContainsString('filesystems.disks.media.bucket', $result->details);
        $this->assertStringContainsString('filesystems.disks.media.region', $result->details);
        $this->assertStringNotContainsString('filesystems.disks.media.key', $result->details);
        $this->assertStringNotContainsString('filesystems.disks.media.secret', $result->details);
    }

    public function testIgnoresS3ValuesWhenTheDefaultDiskIsLocal(): void
    {
        config([
            'filesystems.default' => 'local',
            'filesystems.disks.local.driver' => 'local',
            'filesystems.disks.s3.driver' => 's3',
            'filesystems.disks.s3.bucket' => null,
            'filesystems.disks.s3.region' => null,
        ]);

        $result = (new RequiredConfigurationValuesAreSet)->check();

        $this->assertSame('pass', $result->status->value);
    }

    public function testRequiresTheWebhookUrlForActiveSlackLogChannels(): void
    {
        config([
            'logging.default' => 'stack',
            'logging.channels.stack.driver' => 'stack',
            'logging.channels.stack.channels' => ['single', 'alerts'],
            'logging.channels.single.driver' => 'single',
            'logging.channels.alerts.driver' => 'slack',
            'logging.channels.alerts.url' => null,
        ]);

        $result = (new RequiredConfigurationValuesAreSet)->check();

        $this->assertSame('fail', $result->status->value);
        $this->assertStringContainsString('logging.channels.alerts.url', $result->details);
    }

    public function testIgnoresInactiveLogChannels(): void
    {
        config([
            'logging.default' => 'single',
            'logging.channels.single.driver' => 'single',
            'logging.channels.slack.driver' => 'slack',
            'logging.channels.slack.url' => null,
        ]);

        $result = (new RequiredConfigurationValuesAreSet)->check();

        $this->assertSame('pass', $result->status->value);
    }

    public function testRequiresAHostAndPortForSyslogUdpLogChannels(): void
    {
        config([
            'logging.default' => 'papertrail',
            'logging.channels.papertrail.driver' => 'monolog',
            'logging.channels.papertrail.handler' => SyslogUdpHandler::class,
            'logging.channels.papertrail.handler_with.host' => null,
            'logging.channels.papertrail.handler_with.port' => null,
        ]);

        $result = (new RequiredConfigurationValuesAreSet)->check();

        $this->assertSame('fail', $result->status->value);
        $this->assertStringContainsString('logging.channels.papertrail.handler_with.host', $result->details);
        $this->assertStringContainsString('logging.channels.papertrail.handler_with.port', $result->details);
    }

    public function testRequiresPostmarkCredentialsForTheActiveMailer(): void
    {
        config([
            'mail.default' => 'postmark',
            'mail.mailers.postmark.transport' => 'postmark',
            'mail.mailers.postmark.token' => null,
            'mail.mailers.postmark.key' => null,
            'services.postmark.token' => null,
            'services.postmark.key' => null,
            'services.resend.key' => null,
        ]);

        $result = (new RequiredConfigurationValuesAreSet)->check();

        $this->assertSame('fail', $result->status->value);
        $this->assertStringContainsString('services.postmark.token', $result->details);
        $this->assertStringNotContainsString('services.resend.key', $result->details);
    }

    #[DataProvider('postmarkCredentials')]
    public function testAcceptsEveryPostmarkCredentialLocationSupportedByHypervel(string $credential): void
    {
        config([
            'mail.default' => 'transactional',
            'mail.mailers.transactional.transport' => 'postmark',
            'mail.mailers.transactional.token' => null,
            'mail.mailers.transactional.key' => null,
            'services.postmark.token' => null,
            'services.postmark.key' => null,
            $credential => 'postmark-credential',
        ]);

        $result = (new RequiredConfigurationValuesAreSet)->check();

        $this->assertSame('pass', $result->status->value);
    }

    /**
     * Provide supported Postmark credential locations.
     */
    public static function postmarkCredentials(): array
    {
        return [
            'mailer token' => ['mail.mailers.transactional.token'],
            'mailer key' => ['mail.mailers.transactional.key'],
            'service token' => ['services.postmark.token'],
            'service key' => ['services.postmark.key'],
        ];
    }

    public function testRespectsHypervelPostmarkCredentialPrecedence(): void
    {
        config([
            'mail.default' => 'postmark',
            'mail.mailers.postmark.transport' => 'postmark',
            'mail.mailers.postmark.token' => '',
            'services.postmark.token' => 'fallback-token',
        ]);

        $result = (new RequiredConfigurationValuesAreSet)->check();

        $this->assertSame('fail', $result->status->value);
        $this->assertStringContainsString('mail.mailers.postmark.token', $result->details);
    }

    public function testResolvesMailersBehindFailoverTransports(): void
    {
        config([
            'mail.default' => 'failover',
            'mail.mailers.failover.transport' => 'failover',
            'mail.mailers.failover.mailers' => ['transactional', 'log'],
            'mail.mailers.transactional.transport' => 'resend',
            'mail.mailers.log.transport' => 'log',
            'services.resend.key' => null,
        ]);

        $result = (new RequiredConfigurationValuesAreSet)->check();

        $this->assertSame('fail', $result->status->value);
        $this->assertStringContainsString('services.resend.key', $result->details);
    }

    public function testIgnoresCredentialsForUnusedMailers(): void
    {
        config([
            'mail.default' => 'log',
            'mail.mailers.log.transport' => 'log',
            'mail.mailers.postmark.transport' => 'postmark',
            'services.postmark.token' => null,
            'services.resend.key' => null,
        ]);

        $result = (new RequiredConfigurationValuesAreSet)->check();

        $this->assertSame('pass', $result->status->value);
    }

    public function testRequiresAHostForTheActiveSmtpMailer(): void
    {
        config([
            'mail.default' => 'smtp',
            'mail.mailers.smtp.transport' => 'smtp',
            'mail.mailers.smtp.host' => null,
        ]);

        $result = (new RequiredConfigurationValuesAreSet)->check();

        $this->assertSame('fail', $result->status->value);
        $this->assertStringContainsString('mail.mailers.smtp.host', $result->details);
    }

    public function testRequiresAnOverflowStoreWhenSqsQueueOverflowIsEnabled(): void
    {
        config([
            'queue.default' => 'jobs',
            'queue.connections.jobs.driver' => 'sqs',
            'queue.connections.jobs.overflow.enabled' => true,
            'queue.connections.jobs.overflow.store' => null,
        ]);

        $result = (new RequiredConfigurationValuesAreSet)->check();

        $this->assertSame('fail', $result->status->value);
        $this->assertStringContainsString('queue.connections.jobs.overflow.store', $result->details);
    }

    public function testIgnoresTheOverflowStoreWhenSqsQueueOverflowIsDisabled(): void
    {
        config([
            'queue.default' => 'jobs',
            'queue.connections.jobs.driver' => 'sqs',
            'queue.connections.jobs.overflow.enabled' => false,
            'queue.connections.jobs.overflow.store' => null,
        ]);

        $result = (new RequiredConfigurationValuesAreSet)->check();

        $this->assertSame('pass', $result->status->value);
    }

    #[DataProvider('mercureCredentials')]
    public function testChecksEffectiveMercurePublishingAndSubscriptionCredentials(array $credentials, string $status, ?string $missing): void
    {
        config([
            'broadcasting.default' => 'notifications',
            'broadcasting.connections.notifications' => ['driver' => 'mercure', 'url' => 'https://hub.example.test/.well-known/mercure', ...$credentials],
        ]);

        $result = (new RequiredConfigurationValuesAreSet)->check();

        $this->assertSame($status, $result->status->value);

        if ($missing !== null) {
            $this->assertStringContainsString('broadcasting.connections.notifications.' . $missing, $result->details);
        }
    }

    /**
     * Provide effective Mercure credentials and missing values.
     */
    public static function mercureCredentials(): array
    {
        return [
            'shared secret' => [['secret' => 'shared'], 'pass', null],
            'separate secrets' => [['publish_secret' => 'publisher', 'subscribe_secret' => 'subscriber'], 'pass', null],
            'empty override falls back' => [['secret' => 'shared', 'publish_secret' => '', 'subscribe_secret' => null], 'pass', null],
            'no subscription secret' => [['publish_secret' => 'publisher'], 'fail', 'secret'],
            'no publishing secret' => [['subscribe_secret' => 'subscriber'], 'fail', 'secret'],
            'whitespace override is selected' => [['secret' => 'shared', 'publish_secret' => ' '], 'fail', 'publish_secret'],
            'missing URL' => [['secret' => 'shared', 'url' => null], 'fail', 'url'],
        ];
    }

    public function testFindsRequiredSettingsBehindQueueFailover(): void
    {
        config([
            'queue.default' => 'main',
            'queue.connections.main' => ['driver' => 'failover', 'connections' => ['sync', 'overflow']],
            'queue.connections.overflow' => ['driver' => 'sqs', 'overflow' => ['enabled' => true, 'store' => null]],
        ]);

        $result = (new RequiredConfigurationValuesAreSet)->check();

        $this->assertSame('fail', $result->status->value);
        $this->assertStringContainsString('queue.connections.overflow.overflow.store', $result->details);
    }
}
