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
        $this->assertStringContainsString('services.postmark.key', $result->details);
        $this->assertStringNotContainsString('services.resend.key', $result->details);
    }

    #[DataProvider('postmarkCredentials')]
    public function testRecognizesSupportedPostmarkCredentialLocations(string $credential, string $status): void
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

        $this->assertSame($status, $result->status->value);
    }

    /**
     * Provide supported keys and unsupported legacy token locations.
     */
    public static function postmarkCredentials(): array
    {
        return [
            'mailer token' => ['mail.mailers.transactional.token', 'fail'],
            'mailer key' => ['mail.mailers.transactional.key', 'pass'],
            'service token' => ['services.postmark.token', 'fail'],
            'service key' => ['services.postmark.key', 'pass'],
        ];
    }

    public function testRespectsHypervelPostmarkCredentialPrecedence(): void
    {
        config([
            'mail.default' => 'postmark',
            'mail.mailers.postmark.transport' => 'postmark',
            'mail.mailers.postmark.key' => '',
            'services.postmark.key' => 'fallback-key',
        ]);

        $result = (new RequiredConfigurationValuesAreSet)->check();

        $this->assertSame('fail', $result->status->value);
        $this->assertStringContainsString('mail.mailers.postmark.key', $result->details);
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

    public function testUsesTheDefaultCacheStoreWhenSqsOverflowStoreIsNull(): void
    {
        config([
            'queue.default' => 'jobs',
            'queue.connections.jobs.driver' => 'sqs',
            'queue.connections.jobs.overflow.enabled' => true,
            'queue.connections.jobs.overflow.store' => null,
        ]);

        $result = (new RequiredConfigurationValuesAreSet)->check();

        $this->assertSame('pass', $result->status->value);
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
            'queue.connections.overflow' => ['driver' => 'sqs', 'overflow' => ['enabled' => true, 'store' => '']],
        ]);

        $result = (new RequiredConfigurationValuesAreSet)->check();

        $this->assertSame('fail', $result->status->value);
        $this->assertStringContainsString('queue.connections.overflow.overflow.store', $result->details);
    }

    #[DataProvider('effectiveMailerConfigurations')]
    public function testChecksTheMailFactorysEffectiveConfiguration(array $mailer, array $services, string $status, ?string $missing): void
    {
        config([
            'mail.default' => 'selected',
            'mail.mailers.selected' => $mailer,
            'services' => $services,
        ]);

        $result = (new RequiredConfigurationValuesAreSet)->check();

        $this->assertSame($status, $result->status->value, $result->details ?? '');

        if ($missing !== null) {
            $this->assertStringContainsString($missing, $result->details);
        }
    }

    /**
     * Provide mail URLs and transport-specific credential precedence.
     */
    public static function effectiveMailerConfigurations(): array
    {
        return [
            'SMTP URL supplies transport and host' => [['url' => 'smtp://mail.example.test:587'], [], 'pass', null],
            'URL overrides wrapper transport' => [['transport' => 'failover', 'url' => 'smtp://mail.example.test', 'mailers' => ['missing']], [], 'pass', null],
            'undefined failover child preserves other missing credentials' => [['transport' => 'failover', 'mailers' => ['missing', 'postmark']], [], 'fail', 'services.postmark.key'],
            'URL query overrides an existing host' => [['transport' => 'smtp', 'host' => 'original', 'url' => 'smtp://mail.example.test?host='], [], 'fail', 'mail.mailers.selected.host'],
            'Resend mailer key' => [['transport' => 'resend', 'key' => 'key'], [], 'pass', null],
            'Resend null falls back' => [['transport' => 'resend', 'key' => null], ['resend' => ['key' => 'service-key']], 'pass', null],
            'Resend empty override' => [['transport' => 'resend', 'key' => ''], ['resend' => ['key' => 'service-key']], 'fail', 'mail.mailers.selected.key'],
            'Cloudflare mixed sources' => [['transport' => 'cloudflare', 'account_id' => 'account'], ['cloudflare' => ['key' => 'service-key']], 'pass', null],
            'Cloudflare legacy token' => [['transport' => 'cloudflare', 'account_id' => 'account', 'token' => 'legacy'], [], 'fail', 'services.cloudflare.key'],
            'Mailgun service record' => [['transport' => 'mailgun', 'secret' => null, 'domain' => 'ignored'], ['mailgun' => ['secret' => 'secret', 'domain' => 'mail.example.test']], 'pass', null],
            'Mailgun selected mailer record' => [['transport' => 'mailgun', 'secret' => 'secret', 'domain' => 'mail.example.test'], [], 'pass', null],
            'Mailgun does not merge records' => [['transport' => 'mailgun', 'secret' => 'secret'], ['mailgun' => ['domain' => 'ignored']], 'fail', 'mail.mailers.selected.domain'],
        ];
    }

    #[DataProvider('syslogArguments')]
    public function testUsesTheMergedSyslogHandlerArguments(?array $handlerWith, string $status): void
    {
        config([
            'logging.default' => 'papertrail',
            'logging.channels.papertrail' => [
                'driver' => 'monolog',
                'handler' => SyslogUdpHandler::class,
                'with' => ['host' => 'logs.example.test', 'port' => 514],
                'handler_with' => $handlerWith,
            ],
        ]);

        $result = (new RequiredConfigurationValuesAreSet)->check();

        $this->assertSame($status, $result->status->value);

        if ($status === 'fail') {
            $this->assertStringContainsString('logging.channels.papertrail.handler_with.host', $result->details);
        }
    }

    /**
     * Provide merged and overridden handler arguments.
     */
    public static function syslogArguments(): array
    {
        return [
            'with only' => [null, 'pass'],
            'partial override' => [['port' => 1514], 'pass'],
            'null field overrides with' => [['host' => null], 'fail'],
        ];
    }
}
