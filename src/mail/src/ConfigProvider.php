<?php

declare(strict_types=1);

namespace Hypervel\Mail;

use Hypervel\Mail\Contracts\Factory as FactoryContract;
use Hypervel\Mail\Contracts\Mailer as MailerContract;

class ConfigProvider
{
    public function __invoke(): array
    {
        return [
            'dependencies' => [
                FactoryContract::class => MailManager::class,
                MailerContract::class => MailerFactory::class,
                Markdown::class => MarkdownFactory::class,
            ],
            'publish' => [
                [
                    'id' => 'config',
                    'description' => 'The configuration file of mail.',
                    'source' => __DIR__ . '/../publish/mail.php',
                    'destination' => BASE_PATH . '/config/autoload/mail.php',
                ],
                [
                    'id' => 'resources',
                    'description' => 'The resources for mail.',
                    'source' => __DIR__ . '/../publish/resources/views/',
                    'destination' => BASE_PATH . '/storage/view/mail/',
                ],
            ],
        ];
    }
}
