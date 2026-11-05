<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\Mailer\Bridge\PostHog\Tests\Transport;

use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\Mailer\Bridge\PostHog\Transport\PostHogApiTransport;
use Symfony\Component\Mailer\Bridge\PostHog\Transport\PostHogTransportFactory;
use Symfony\Component\Mailer\Test\AbstractTransportFactoryTestCase;
use Symfony\Component\Mailer\Test\IncompleteDsnTestTrait;
use Symfony\Component\Mailer\Transport\Dsn;
use Symfony\Component\Mailer\Transport\TransportFactoryInterface;

class PostHogTransportFactoryTest extends AbstractTransportFactoryTestCase
{
    use IncompleteDsnTestTrait;

    public function getFactory(): TransportFactoryInterface
    {
        return new PostHogTransportFactory(null, new MockHttpClient(), new NullLogger());
    }

    public static function supportsProvider(): iterable
    {
        yield [
            new Dsn('posthog', 'default'),
            true,
        ];

        yield [
            new Dsn('posthog+api', 'default'),
            true,
        ];

        yield [
            new Dsn('posthog+smtp', 'default'),
            false,
        ];
    }

    public static function createProvider(): iterable
    {
        $logger = new NullLogger();

        yield [
            new Dsn('posthog', 'default', self::USER),
            new PostHogApiTransport(self::USER, new MockHttpClient(), null, $logger),
        ];

        yield [
            new Dsn('posthog+api', 'default', self::USER),
            new PostHogApiTransport(self::USER, new MockHttpClient(), null, $logger),
        ];

        yield [
            new Dsn('posthog+api', 'eu.posthog.com', self::USER),
            (new PostHogApiTransport(self::USER, new MockHttpClient(), null, $logger))->setHost('eu.posthog.com'),
        ];

        yield [
            new Dsn('posthog+api', 'posthog.example.com', self::USER, null, 8443),
            (new PostHogApiTransport(self::USER, new MockHttpClient(), null, $logger))->setHost('posthog.example.com')->setPort(8443),
        ];
    }

    public static function unsupportedSchemeProvider(): iterable
    {
        yield [
            new Dsn('posthog+smtp', 'default', self::USER),
            'The "posthog+smtp" scheme is not supported; supported schemes for mailer "posthog" are: "posthog", "posthog+api".',
        ];
    }

    public static function incompleteDsnProvider(): iterable
    {
        yield [new Dsn('posthog+api', 'default')];
    }
}
