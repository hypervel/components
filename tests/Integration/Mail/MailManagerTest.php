<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Mail;

use Hypervel\Config\Repository;
use Hypervel\Container\Container;
use Hypervel\Contracts\ObjectPool\Factory as PoolFactory;
use Hypervel\Contracts\View\Factory as ViewFactory;
use Hypervel\Log\LogManager;
use Hypervel\Mail\Mailable;
use Hypervel\Mail\MailManager;
use Hypervel\Mail\Message;
use Hypervel\Mail\Transport\LogTransport;
use Hypervel\Mail\TransportPoolProxy;
use Hypervel\Support\ClassInvoker;
use Hypervel\Support\Testing\Fakes\MailFake;
use Hypervel\Testbench\Attributes\WithConfig;
use Hypervel\Testbench\TestCase;
use InvalidArgumentException;
use Mockery as m;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestWith;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\Bridge\Postmark\Transport\PostmarkApiTransport;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\RoundRobinTransport;
use Symfony\Component\Mailer\Transport\SendmailTransport;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\RawMessage;

class MailManagerTest extends TestCase
{
    /**
     * Set up the test environment.
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->app->instance('view', m::mock(ViewFactory::class));
    }

    public function testSetApplicationRefreshesConfigWithoutRebuildingResolvedMailers(): void
    {
        config(['mail.mailers.existing' => ['transport' => 'array']]);

        $manager = new MailManager($this->app);
        $resolved = $manager->mailer('existing');
        $application = new Container;
        $application->instance('config', new Repository([
            'mail' => ['default' => 'replacement'],
        ]));

        $this->assertSame($manager, $manager->setApplication($application));
        $this->assertSame('replacement', $manager->getDefaultDriver());
        $this->assertSame($resolved, $manager->mailer('existing'));
    }

    public function testIntegerEnumMailerNamesAreNormalizedWithoutTreatingZeroAsAbsent(): void
    {
        config(['mail.mailers.0' => ['transport' => 'array']]);

        $manager = new MailManager($this->app);
        $manager->setDefaultDriver(MailManagerTestIntIdentifier::Zero);

        $mailer = $manager->mailer();

        $this->assertSame('0', $manager->getDefaultDriver());
        $this->assertSame($mailer, $manager->driver(MailManagerTestIntIdentifier::Zero));
        $this->assertSame($mailer, $manager->mailer(''));

        $manager->purge('');

        $this->assertNotSame($mailer, $manager->mailer(MailManagerTestIntIdentifier::Zero));
    }

    public function testFakeDriverNormalizesIntegerEnumsWithoutEscapingToTheManager(): void
    {
        config(['mail.default' => 'array']);

        $fake = new MailFake(new MailManager($this->app));
        $mailable = new Mailable;

        $this->assertSame($fake, $fake->driver(MailManagerTestIntIdentifier::Zero));

        $fake->send($mailable);

        $this->assertSame('0', $mailable->mailer);
    }

    #[DataProvider('emptyTransportConfigDataProvider')]
    public function testEmptyTransportConfig(mixed $transport): void
    {
        config(['mail.mailers.custom_smtp' => [
            'transport' => $transport,
            'host' => null,
            'port' => null,
            'encryption' => null,
            'username' => null,
            'password' => null,
            'timeout' => null,
        ]]);

        $this->expectExceptionObject(new InvalidArgumentException("Unsupported mail transport [{$transport}]"));

        (new MailManager($this->app))
            ->mailer('custom_smtp');
    }

    /**
     * Provide empty transport configurations.
     */
    public static function emptyTransportConfigDataProvider(): array
    {
        return [
            [null],
            [''],
            [' '],
        ];
    }

    #[TestWith([null, 5876])]
    #[TestWith([null, 465])]
    #[TestWith(['smtp', 25])]
    #[TestWith(['smtp', 2525])]
    #[TestWith(['smtps', 465])]
    #[TestWith(['smtp', 465])]
    public function testMailUrlConfig(?string $scheme, int $port): void
    {
        config(['mail.mailers.smtp_url' => [
            'scheme' => $scheme,
            'url' => "smtp://usr:pwd@127.0.0.2:{$port}",
        ]]);

        $transport = (new MailManager($this->app))
            ->removePoolableDriver('smtp')
            ->mailer('smtp_url')
            ->getSymfonyTransport();

        $this->assertInstanceOf(EsmtpTransport::class, $transport);
        $this->assertSame('usr', $transport->getUsername());
        $this->assertSame('pwd', $transport->getPassword());
        $this->assertSame('127.0.0.2', $transport->getStream()->getHost());
        $this->assertSame($port, $transport->getStream()->getPort());
        $this->assertSame($port === 465, $transport->getStream()->isTLS());
        $this->assertTrue($transport->isAutoTls());
    }

    #[TestWith([null, 5876])]
    #[TestWith([null, 465])]
    #[TestWith(['smtp', 25])]
    #[TestWith(['smtp', 2525])]
    #[TestWith(['smtps', 465])]
    #[TestWith(['smtp', 465])]
    public function testMailUrlConfigWithAutoTls(?string $scheme, int $port): void
    {
        config(['mail.mailers.smtp_url' => [
            'scheme' => $scheme,
            'url' => "smtp://usr:pwd@127.0.0.2:{$port}?auto_tls=true",
        ]]);

        $transport = (new MailManager($this->app))
            ->removePoolableDriver('smtp')
            ->mailer('smtp_url')
            ->getSymfonyTransport();

        $this->assertInstanceOf(EsmtpTransport::class, $transport);
        $this->assertSame('usr', $transport->getUsername());
        $this->assertSame('pwd', $transport->getPassword());
        $this->assertSame('127.0.0.2', $transport->getStream()->getHost());
        $this->assertSame($port, $transport->getStream()->getPort());
        $this->assertSame($port === 465, $transport->getStream()->isTLS());
        $this->assertTrue($transport->isAutoTls());
    }

    #[TestWith([null, 5876])]
    #[TestWith([null, 465])]
    #[TestWith(['smtp', 25])]
    #[TestWith(['smtp', 2525])]
    #[TestWith(['smtps', 465])]
    #[TestWith(['smtp', 465])]
    public function testMailUrlConfigWithAutoTlsDisabled(?string $scheme, int $port): void
    {
        config(['mail.mailers.smtp_url' => [
            'scheme' => $scheme,
            'url' => "smtp://usr:pwd@127.0.0.2:{$port}?auto_tls=false",
        ]]);

        $transport = (new MailManager($this->app))
            ->removePoolableDriver('smtp')
            ->mailer('smtp_url')
            ->getSymfonyTransport();

        $this->assertInstanceOf(EsmtpTransport::class, $transport);
        $this->assertSame('usr', $transport->getUsername());
        $this->assertSame('pwd', $transport->getPassword());
        $this->assertSame('127.0.0.2', $transport->getStream()->getHost());
        $this->assertSame($port, $transport->getStream()->getPort());
        $this->assertFalse($transport->isAutoTls());
        $this->assertSame($port === 465 && $scheme !== 'smtp', $transport->getStream()->isTLS());
    }

    public function testBuildIsDirectWithoutAnExplicitPoolOption(): void
    {
        $config = [
            'transport' => 'smtp',
            'host' => '127.0.0.2',
            'port' => 5876,
            'encryption' => 'tls',
            'username' => 'usr',
            'password' => 'pwd',
            'timeout' => 5,
        ];

        $manager = new MailManager($this->app);
        $first = $manager->build(['name' => 'first', ...$config])->getSymfonyTransport();
        $second = $manager->build(['name' => 'second', ...$config])->getSymfonyTransport();

        $this->assertInstanceOf(EsmtpTransport::class, $first);
        $this->assertSame('usr', $first->getUsername());
        $this->assertSame('pwd', $first->getPassword());
        $this->assertSame('127.0.0.2', $first->getStream()->getHost());
        $this->assertSame(5876, $first->getStream()->getPort());
        $this->assertInstanceOf(EsmtpTransport::class, $second);
        $this->assertNotSame($first, $second);
    }

    public function testMailManagerCanResolveBackedEnumMailer(): void
    {
        config(['mail.mailers.array' => ['transport' => 'array']]);

        $mailer1 = $this->app->make('mail.manager')->mailer(MailerName::ArrayMailer);
        $mailer2 = $this->app->make('mail.manager')->mailer('array');

        $this->assertSame($mailer1, $mailer2);
    }

    public function testMailManagerCanResolveBackedEnumDriver(): void
    {
        config(['mail.mailers.array' => ['transport' => 'array']]);

        $mailer1 = $this->app->make('mail.manager')->driver(MailerName::ArrayMailer);
        $mailer2 = $this->app->make('mail.manager')->driver('array');

        $this->assertSame($mailer1, $mailer2);
    }

    public function testSetDefaultDriverAcceptsBackedEnum(): void
    {
        config(['mail.mailers.array' => ['transport' => 'array']]);

        $this->app->make('mail.manager')->setDefaultDriver(MailerName::ArrayMailer);

        $this->assertSame('array', config('mail.default'));
    }

    public function testPurgeAcceptsBackedEnum(): void
    {
        config(['mail.mailers.array' => ['transport' => 'array']]);

        $manager = $this->app->make('mail.manager');

        $mailer1 = $manager->mailer(MailerName::ArrayMailer);
        $manager->purge(MailerName::ArrayMailer);
        $mailer2 = $manager->mailer(MailerName::ArrayMailer);

        $this->assertNotSame($mailer1, $mailer2);
    }

    #[WithConfig('mail.mailers.array', ['transport' => 'array'])]
    #[WithConfig('mail.to', ['address' => 'taylor@hypervel.com'])]
    public function testGlobalToAddressWithoutName(): void
    {
        $mailer = $this->app->make('mail.manager')->mailer('array');

        $sentMessage = $mailer->raw('Hello World', function (Message $message): void {
            $message->to('jack@hypervel.com');
        });

        $this->assertStringContainsString('To: taylor@hypervel.com', $sentMessage->toString());
    }

    #[DataProvider('onDemandPoolConfigProvider')]
    public function testBuildPoolsOnlyWhenExplicitlyEnabled(bool|array $poolConfig, int $maxObjects): void
    {
        $config = [
            'transport' => 'smtp',
            'host' => '127.0.0.2',
            'port' => 5876,
            'username' => 'usr',
            'password' => 'pwd',
            'pool' => $poolConfig,
        ];
        $manager = new MailManager($this->app);
        $first = $manager->build($config)->getSymfonyTransport();
        $second = $manager->build($config)->getSymfonyTransport();

        $this->assertInstanceOf(TransportPoolProxy::class, $first);
        $this->assertInstanceOf(TransportPoolProxy::class, $second);
        $this->assertSame($first->getPoolName(), $second->getPoolName());
        $this->assertSame($maxObjects, $first->getDefinition()->options->maxObjects);
    }

    /**
     * Provide explicit pool configurations.
     */
    public static function onDemandPoolConfigProvider(): array
    {
        return [
            'true uses defaults' => [true, 10],
            'empty array uses defaults' => [[], 10],
            'partial options override defaults' => [['max_objects' => 3], 3],
        ];
    }

    public function testMailTransportPoolsNamedMailersButKeepsOnDemandBuildsDirectByDefault(): void
    {
        config(['mail.mailers.php' => [
            'transport' => 'mail',
            'pool' => ['max_objects' => 2],
        ]]);

        $manager = new MailManager($this->app);
        $named = $manager->mailer('php')->getSymfonyTransport();
        $direct = $manager->build(['transport' => 'mail'])->getSymfonyTransport();
        $pooled = $manager->build(['transport' => 'mail', 'pool' => true])->getSymfonyTransport();

        $this->assertInstanceOf(TransportPoolProxy::class, $named);
        $this->assertSame(2, $named->getDefinition()->options->maxObjects);
        $this->assertInstanceOf(SendmailTransport::class, $direct);
        $this->assertInstanceOf(TransportPoolProxy::class, $pooled);
    }

    public function testOnDemandTransportsDoNotInheritNamedMailerFallbacks(): void
    {
        config(['mail.sendmail' => '/usr/sbin/sendmail -bs -i']);
        config(['mail.log_channel' => 'legacy-channel']);

        $logger = m::mock(LoggerInterface::class);
        $logManager = m::mock(LogManager::class);
        $logManager->shouldReceive('channel')->once()->with(null)->andReturn($logger);
        $this->app->instance(LoggerInterface::class, $logManager);

        $manager = new MailManager($this->app);
        $sendmail = $manager->build(['transport' => 'sendmail'])->getSymfonyTransport();
        $log = $manager->build(['transport' => 'log'])->getSymfonyTransport();

        $this->assertInstanceOf(SendmailTransport::class, $sendmail);
        $this->assertSame('/usr/sbin/sendmail -bs', (new ClassInvoker($sendmail))->command);
        $this->assertInstanceOf(LogTransport::class, $log);
        $this->assertSame($logger, $log->logger());
    }

    public function testFalseDisablesNamedAndOnDemandPooling(): void
    {
        $config = [
            'transport' => 'smtp',
            'host' => '127.0.0.2',
            'port' => 5876,
            'pool' => false,
        ];
        config(['mail.mailers.direct' => $config]);
        $manager = new MailManager($this->app);

        $this->assertInstanceOf(EsmtpTransport::class, $manager->mailer('direct')->getSymfonyTransport());
        $this->assertInstanceOf(EsmtpTransport::class, $manager->build($config)->getSymfonyTransport());
    }

    #[DataProvider('invalidPoolConfigProvider')]
    public function testInvalidPoolConfigurationIsRejected(mixed $poolConfig): void
    {
        $this->expectExceptionObject(new InvalidArgumentException('must be false, true, or an array'));

        (new MailManager($this->app))->build([
            'transport' => 'smtp',
            'host' => '127.0.0.2',
            'port' => 5876,
            'pool' => $poolConfig,
        ]);
    }

    /**
     * Provide invalid pool configurations.
     */
    public static function invalidPoolConfigProvider(): array
    {
        return [[null], [1], ['enabled']];
    }

    public function testExplicitPoolingRejectsANonPoolableTransport(): void
    {
        $this->expectExceptionObject(new InvalidArgumentException('Mail transport [array] is not registered as poolable.'));

        (new MailManager($this->app))->build(['transport' => 'array', 'pool' => true]);
    }

    public function testSmtpTransportUsesSecureSchemeWhenPortIsConfiguredAsString(): void
    {
        $transport = (new MailManager($this->app))
            ->createSymfonyTransport([
                'transport' => 'smtp',
                'host' => '127.0.0.2',
                'port' => '465',
                'username' => null,
                'password' => null,
            ]);

        $this->assertInstanceOf(EsmtpTransport::class, $transport);
        $this->assertSame(465, $transport->getStream()->getPort());
        $this->assertTrue($transport->getStream()->isTLS());
    }

    public function testPoolableMailUrlConfig(): void
    {
        config(['mail.mailers.smtp_url' => [
            'url' => 'smtp://usr:pwd@127.0.0.2:5876',
        ]]);

        $transport = (new MailManager($this->app))
            ->mailer('smtp_url')
            ->getSymfonyTransport();

        $this->assertInstanceOf(TransportPoolProxy::class, $transport);
    }

    public function testPostmarkTransportCanBeCreatedDirectly(): void
    {
        $transport = (new MailManager($this->app))
            ->createSymfonyTransport([
                'transport' => 'postmark',
                'key' => 'postmark-key',
            ]);

        $this->assertInstanceOf(PostmarkApiTransport::class, $transport);
        $this->assertSame('postmark-key', (new ClassInvoker($transport))->key);
    }

    public function testPostmarkTransportAcceptsTokenConfiguration(): void
    {
        $transport = (new MailManager($this->app))
            ->createSymfonyTransport([
                'transport' => 'postmark',
                'token' => 'postmark-token',
                'key' => 'postmark-key',
            ]);

        $this->assertInstanceOf(PostmarkApiTransport::class, $transport);
        $this->assertSame('postmark-token', (new ClassInvoker($transport))->key);
    }

    #[DataProvider('poolableTransportDataProvider')]
    public function testConfiguredPoolableTransportsResolveToPoolProxy(string $transport): void
    {
        config(["mail.mailers.{$transport}" => [
            'transport' => $transport,
            'host' => '127.0.0.1',
            'port' => 2525,
            'username' => null,
            'password' => null,
            'path' => '/usr/sbin/sendmail -bs -i',
            'mailers' => ['smtp', 'log'],
        ]]);

        $transport = (new MailManager($this->app))
            ->mailer($transport)
            ->getSymfonyTransport();

        $this->assertInstanceOf(TransportPoolProxy::class, $transport);
    }

    /**
     * Provide poolable transports.
     */
    public static function poolableTransportDataProvider(): array
    {
        return [
            ['smtp'],
            ['sendmail'],
            ['mail'],
            ['mailgun'],
            ['ses-v2'],
            ['postmark'],
            ['resend'],
            ['cloudflare'],
            ['failover'],
            ['roundrobin'],
        ];
    }

    public function testBuiltInPresentationKeysDoNotSplitTransportPools(): void
    {
        $transportConfig = [
            'transport' => 'smtp',
            'host' => '127.0.0.1',
            'port' => 2525,
            'username' => 'user',
            'password' => 'secret',
        ];

        config(['mail.mailers.first' => [
            ...$transportConfig,
            'from' => ['address' => 'first@example.com', 'name' => 'First'],
        ]]);
        config(['mail.mailers.second' => [
            ...$transportConfig,
            'from' => ['address' => 'second@example.com', 'name' => 'Second'],
        ]]);

        $manager = new MailManager($this->app);
        $first = $manager->mailer('first')->getSymfonyTransport();
        $second = $manager->mailer('second')->getSymfonyTransport();

        $this->assertInstanceOf(TransportPoolProxy::class, $first);
        $this->assertInstanceOf(TransportPoolProxy::class, $second);
        $this->assertSame($first->getPoolName(), $second->getPoolName());
    }

    public function testGlobalAddressMayOmitTheOptionalName(): void
    {
        config(['mail.mailers.array' => [
            'transport' => 'array',
            'from' => ['address' => 'sender@example.com'],
        ]]);

        $mailer = (new MailManager($this->app))->mailer('array');

        $this->assertSame([
            'address' => 'sender@example.com',
            'name' => null,
        ], (new ClassInvoker($mailer))->from);
    }

    public function testCustomPresentationKeysRemainConstructionInput(): void
    {
        config(['mail.mailers.first' => [
            'transport' => 'custom',
            'from' => ['address' => 'first@example.com'],
        ]]);
        config(['mail.mailers.second' => [
            'transport' => 'custom',
            'from' => ['address' => 'second@example.com'],
        ]]);

        $manager = (new MailManager($this->app))->extend(
            'custom',
            fn (array $config): MailManagerTestTransport => new MailManagerTestTransport,
            poolable: true,
        );
        $first = $manager->mailer('first')->getSymfonyTransport();
        $second = $manager->mailer('second')->getSymfonyTransport();

        $this->assertInstanceOf(TransportPoolProxy::class, $first);
        $this->assertInstanceOf(TransportPoolProxy::class, $second);
        $this->assertNotSame($first->getPoolName(), $second->getPoolName());
    }

    public function testCustomCreatorNeverReceivesPoolControlMetadata(): void
    {
        $received = null;
        $manager = (new MailManager($this->app))->extend(
            'custom',
            function (array $config) use (&$received): MailManagerTestTransport {
                $received = $config;

                return new MailManagerTestTransport;
            },
            poolable: true,
        );

        $transport = $manager->build([
            'transport' => 'custom',
            'label' => 'pooled-custom',
            'pool' => ['name' => 'custom-transport'],
        ])->getSymfonyTransport();

        $this->assertInstanceOf(TransportPoolProxy::class, $transport);
        $this->assertSame('mail-manager-test', (string) $transport);
        $this->assertSame([
            'transport' => 'custom',
            'label' => 'pooled-custom',
        ], $received);
    }

    #[DataProvider('serviceBackedTransportDataProvider')]
    public function testResolvedServiceConfigurationChangesTransportFingerprint(
        string $transport,
        string $service,
        array $serviceConfig,
        string $changedKey,
        string $changedValue,
    ): void {
        config(['mail.mailers.service' => ['transport' => $transport]]);
        config(["services.{$service}" => $serviceConfig]);

        $manager = new MailManager($this->app);
        $first = $manager->mailer('service')->getSymfonyTransport();
        $manager->forgetMailers();
        config(["services.{$service}.{$changedKey}" => $changedValue]);
        $second = $manager->mailer('service')->getSymfonyTransport();

        $this->assertInstanceOf(TransportPoolProxy::class, $first);
        $this->assertInstanceOf(TransportPoolProxy::class, $second);
        $this->assertNotSame($first->getPoolName(), $second->getPoolName());
    }

    /**
     * Provide transports with service configuration.
     */
    public static function serviceBackedTransportDataProvider(): array
    {
        return [
            'ses' => [
                'ses-v2',
                'ses',
                ['key' => 'key', 'secret' => 'secret', 'region' => 'us-east-1'],
                'region',
                'eu-west-1',
            ],
            'resend' => ['resend', 'resend', ['key' => 'first-key'], 'key', 'second-key'],
            'cloudflare' => [
                'cloudflare',
                'cloudflare',
                ['account_id' => 'account', 'token' => 'first-token'],
                'token',
                'second-token',
            ],
            'mailgun' => [
                'mailgun',
                'mailgun',
                ['domain' => 'example.com', 'secret' => 'first-secret'],
                'secret',
                'second-secret',
            ],
            'postmark' => ['postmark', 'postmark', ['token' => 'first-token'], 'token', 'second-token'],
        ];
    }

    public function testCompositeFingerprintTracksChildConstructionAndOrder(): void
    {
        config(['mail.mailers' => [
            'composite' => [
                'transport' => 'failover',
                'mailers' => ['primary', 'backup'],
            ],
            'primary' => [
                'transport' => 'smtp',
                'host' => '127.0.0.1',
                'port' => 2525,
                'username' => 'user',
                'password' => 'first-secret',
            ],
            'backup' => [
                'transport' => 'sendmail',
                'path' => '/usr/sbin/sendmail -bs',
            ],
        ]]);

        $manager = new MailManager($this->app);
        $first = $manager->mailer('composite')->getSymfonyTransport();
        $manager->forgetMailers();
        config(['mail.mailers.primary.password' => 'second-secret']);
        $second = $manager->mailer('composite')->getSymfonyTransport();
        $manager->forgetMailers();
        config(['mail.mailers.composite.mailers' => ['backup', 'primary']]);
        $reordered = $manager->mailer('composite')->getSymfonyTransport();

        $this->assertInstanceOf(TransportPoolProxy::class, $first);
        $this->assertInstanceOf(TransportPoolProxy::class, $second);
        $this->assertInstanceOf(TransportPoolProxy::class, $reordered);
        $this->assertNotSame($first->getPoolName(), $second->getPoolName());
        $this->assertNotSame($second->getPoolName(), $reordered->getPoolName());
    }

    public function testCompositeUsesItsOwnRetryAfterAndDirectChildTransports(): void
    {
        config(['mail.mailers' => [
            'array' => ['transport' => 'array'],
            'sendmail' => [
                'transport' => 'sendmail',
                'path' => '/usr/sbin/sendmail -bs',
                'retry_after' => 999,
            ],
        ]]);

        $transport = (new MailManager($this->app))->createSymfonyTransport([
            'transport' => 'roundrobin',
            'mailers' => ['array', 'sendmail'],
            'retry_after' => 7,
        ]);

        $this->assertInstanceOf(RoundRobinTransport::class, $transport);

        $transportState = new ClassInvoker($transport);
        $this->assertSame(7, $transportState->retryPeriod);
        $this->assertCount(2, $transportState->transports);

        foreach ($transportState->transports as $child) {
            $this->assertNotInstanceOf(TransportPoolProxy::class, $child);
        }
    }

    public function testCompositeDefinitionCyclesAreRejected(): void
    {
        config(['mail.mailers' => [
            'first' => [
                'transport' => 'failover',
                'mailers' => ['second'],
            ],
            'second' => [
                'transport' => 'roundrobin',
                'mailers' => ['first'],
            ],
        ]]);

        $this->expectExceptionObject(new InvalidArgumentException('Circular mailer transport definition detected: first -> second -> first.'));

        (new MailManager($this->app))->mailer('first');
    }

    public function testPurgeInvalidatesACachedTransportPool(): void
    {
        config(['mail.mailers.smtp' => [
            'transport' => 'smtp',
            'host' => '127.0.0.1',
            'port' => 2525,
        ]]);

        $manager = new MailManager($this->app);
        $transport = $manager->mailer('smtp')->getSymfonyTransport();
        $this->assertInstanceOf(TransportPoolProxy::class, $transport);

        $identity = $transport->getPoolName();
        $pools = $this->app->make(PoolFactory::class);
        (string) $transport;
        $this->assertTrue($pools->has($identity));

        $manager->purge('smtp');
        $this->assertFalse($pools->has($identity));

        (string) $transport;
        $this->assertTrue($pools->has($identity));
    }

    public function testForgetIsCacheOnlyAndUncachedPurgeDerivesThePoolIdentity(): void
    {
        config(['mail.mailers.smtp' => [
            'transport' => 'smtp',
            'host' => '127.0.0.1',
            'port' => 2525,
        ]]);

        $manager = new MailManager($this->app);
        $transport = $manager->mailer('smtp')->getSymfonyTransport();
        $this->assertInstanceOf(TransportPoolProxy::class, $transport);

        $identity = $transport->getPoolName();
        $pools = $this->app->make(PoolFactory::class);
        (string) $transport;

        $manager->forgetMailers();
        $this->assertTrue($pools->has($identity));

        $manager->purge('smtp');
        $this->assertFalse($pools->has($identity));
    }

    public function testTransportPoolProxyEnumeratesSendAndStringConversion(): void
    {
        MailManagerTestTransport::$sent = 0;

        $transport = (new MailManager($this->app))
            ->extend('custom', fn (array $config): MailManagerTestTransport => new MailManagerTestTransport, poolable: true)
            ->build(['transport' => 'custom', 'pool' => true])
            ->getSymfonyTransport();

        $this->assertInstanceOf(TransportPoolProxy::class, $transport);
        $this->assertNull($transport->send(new RawMessage('body')));
        $this->assertSame(1, MailManagerTestTransport::$sent);
        $this->assertSame('mail-manager-test', (string) $transport);
    }

    public function testCustomOnDemandTransportRequiresPerBuildOptIn(): void
    {
        $manager = (new MailManager($this->app))
            ->extend('custom', fn (array $config): MailManagerTestTransport => new MailManagerTestTransport, poolable: true);

        $direct = $manager->build(['transport' => 'custom'])->getSymfonyTransport();
        $pooled = $manager->build(['transport' => 'custom', 'pool' => true])->getSymfonyTransport();

        $this->assertInstanceOf(MailManagerTestTransport::class, $direct);
        $this->assertInstanceOf(TransportPoolProxy::class, $pooled);
    }

    public function testOnDemandPoolCanBeInvalidatedThroughItsTransportProxy(): void
    {
        $transport = (new MailManager($this->app))->build([
            'transport' => 'smtp',
            'host' => '127.0.0.1',
            'port' => 2525,
            'pool' => true,
        ])->getSymfonyTransport();
        $this->assertInstanceOf(TransportPoolProxy::class, $transport);

        (string) $transport;
        $identity = $transport->getPoolName();
        $pools = $this->app->make(PoolFactory::class);
        $this->assertTrue($pools->has($identity));

        $this->assertTrue($transport->invalidatePool());
        $this->assertFalse($pools->has($identity));
    }
}

enum MailerName: string
{
    case ArrayMailer = 'array';
}

enum MailManagerTestIntIdentifier: int
{
    case Zero = 0;
}

class MailManagerTestTransport implements TransportInterface
{
    public static int $sent = 0;

    /**
     * Record the send attempt.
     */
    public function send(RawMessage $message, ?Envelope $envelope = null): ?SentMessage
    {
        ++static::$sent;

        return null;
    }

    /**
     * Get the transport's name.
     */
    public function __toString(): string
    {
        return 'mail-manager-test';
    }
}
