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

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Mailer\Bridge\PostHog\Transport\PostHogApiTransport;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\HttpTransportException;
use Symfony\Component\Mailer\Exception\LogicException;
use Symfony\Component\Mailer\Header\MetadataHeader;
use Symfony\Component\Mailer\Header\TagHeader;
use Symfony\Component\Mailer\Header\TrackingHeader;
use Symfony\Component\Mailer\RemoteTemplateEmail;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Part\DataPart;
use Symfony\Component\Mime\Part\Multipart\AlternativePart;
use Symfony\Component\Mime\Part\Multipart\MixedPart;
use Symfony\Component\Mime\Part\TextPart;
use Symfony\Contracts\HttpClient\ResponseInterface;

class PostHogApiTransportTest extends TestCase
{
    #[DataProvider('getTransportData')]
    public function testToString(PostHogApiTransport $transport, string $expected)
    {
        $this->assertSame($expected, (string) $transport);
    }

    public static function getTransportData(): iterable
    {
        yield [
            new PostHogApiTransport('KEY'),
            'posthog+api://us.posthog.com',
        ];

        yield [
            (new PostHogApiTransport('KEY'))->setHost('eu.posthog.com'),
            'posthog+api://eu.posthog.com',
        ];

        yield [
            (new PostHogApiTransport('KEY'))->setHost('posthog.example.com')->setPort(8443),
            'posthog+api://posthog.example.com:8443',
        ];
    }

    public function testSendRenderedEmail()
    {
        $client = new MockHttpClient(function (string $method, string $url, array $options): ResponseInterface {
            $this->assertSame('POST', $method);
            $this->assertSame('https://eu.posthog.com/api/projects/@current/messaging/send/email/', $url);
            $this->assertContains('Authorization: Bearer KEY', $options['headers']);
            $this->assertContains('Accept: application/json', $options['headers']);
            $this->assertMatchesRegularExpression('{^symfony-mailer/[0-9A-Za-z.+-]{1,40}$}', self::getHeader($options, 'X-PostHog-Integration'));
            $this->assertNull(self::getHeader($options, 'Idempotency-Key'));

            $this->assertEquals([
                'to' => 'ana@example.com',
                'from' => 'Shop <shop@example.com>',
                'subject' => 'Your order shipped',
                'html' => '<p>On its way.</p>',
                'text' => 'On its way.',
                'template_key' => 'symfony-mailer',
            ], json_decode($options['body'], true));

            return new JsonMockResponse(['message_id' => 'msg-1234', 'replayed' => false], ['http_code' => 202]);
        });

        $sentMessage = (new PostHogApiTransport('KEY', $client))->setHost('eu.posthog.com')->send(self::createEmail());

        $this->assertSame('msg-1234', $sentMessage->getMessageId());
    }

    public function testRecipientDisplayNamesAreDroppedAndTheSenderNameIsKept()
    {
        $email = self::createEmail()
            ->cc(new Address('bo@example.com', 'Bo'))
            ->replyTo(new Address('help@example.com', 'Help'));

        $body = $this->sendAndGetBody($email);

        $this->assertSame('ana@example.com', $body['to']);
        $this->assertSame('Shop <shop@example.com>', $body['from']);
        $this->assertSame(['bo@example.com'], $body['cc']);
        $this->assertSame(['help@example.com'], $body['reply_to']);
    }

    public function testSendCcBccAndReplyTo()
    {
        $email = self::createEmail()
            ->cc('bo@example.com')
            ->bcc('audit@example.com')
            ->replyTo('a@example.com', 'b@example.com');

        $body = $this->sendAndGetBody($email);

        $this->assertSame(['bo@example.com'], $body['cc']);
        $this->assertSame(['audit@example.com'], $body['bcc']);
        $this->assertSame(['a@example.com', 'b@example.com'], $body['reply_to']);
        $this->assertSame('ana@example.com', $body['to']);
    }

    public function testSendWithoutSenderNameUsesTheBareAddress()
    {
        $email = self::createEmail()->from('shop@example.com');

        $this->assertSame('shop@example.com', $this->sendAndGetBody($email)['from']);
    }

    #[DataProvider('getQuotedSenderNames')]
    public function testSenderNameIsQuotedWhenItHasSpecialCharacters(string $name, string $expected)
    {
        $email = self::createEmail()->from(new Address('shop@example.com', $name));

        $this->assertSame($expected, $this->sendAndGetBody($email)['from']);
    }

    public static function getQuotedSenderNames(): iterable
    {
        yield 'comma' => ['Shop, Inc', '"Shop, Inc" <shop@example.com>'];
        yield 'quote' => ['Say "hi"', '"Say \\"hi\\"" <shop@example.com>'];
        yield 'dot' => ['Shop Inc.', '"Shop Inc." <shop@example.com>'];
        yield 'non ascii' => ['Jürgen', 'Jürgen <shop@example.com>'];
    }

    public function testSendAttachmentAndInlineImage()
    {
        $email = self::createEmail()
            ->html('<img src="cid:logo@example.com">')
            ->addPart(new DataPart('%PDF-1.4'."\n", 'invoice.pdf', 'application/pdf'))
            ->addPart((new DataPart('image-bytes', 'logo.png', 'image/png'))->asInline()->setContentId('logo@example.com'));

        $body = $this->sendAndGetBody($email);

        $this->assertSame('<img src="cid:logo@example.com">', $body['html']);
        $this->assertSame([
            [
                'filename' => 'invoice.pdf',
                'content_type' => 'application/pdf',
                'content' => 'JVBERi0xLjQK',
            ],
            [
                'filename' => 'logo.png',
                'content_type' => 'image/png',
                'content' => base64_encode('image-bytes'),
                'content_id' => 'logo@example.com',
            ],
        ], $body['attachments']);
    }

    public function testEmbeddedImageReferencedByItsNameUsesTheNameAsContentId()
    {
        $email = self::createEmail()
            ->html('<img src="cid:logo">')
            ->embed('image-bytes', 'logo', 'image/png');

        $this->assertSame([[
            'filename' => 'logo',
            'content_type' => 'image/png',
            'content' => base64_encode('image-bytes'),
            'content_id' => 'logo',
        ]], $this->sendAndGetBody($email)['attachments']);
    }

    public function testEmbeddedImageKeepsItsNameAfterTheBodyIsRendered()
    {
        $email = self::createEmail()
            ->html('<img src="cid:logo">')
            ->embed('image-bytes', 'logo', 'image/png');
        $email->getBody();

        $this->assertSame('logo', $this->sendAndGetBody($email)['attachments'][0]['content_id']);
    }

    public function testContentIdThatStartsWithThePartNameIsMatchedInFull()
    {
        $email = self::createEmail()
            ->html('<img src="cid:logo\'v2@shop.example">')
            ->addPart((new DataPart('image-bytes', 'logo', 'image/png'))->asInline()->setContentId('logo\'v2@shop.example'));

        $this->assertSame('logo\'v2@shop.example', $this->sendAndGetBody($email)['attachments'][0]['content_id']);
    }

    public function testUnquotedReferenceEndsAtTheClosingParenthesis()
    {
        $email = self::createEmail()
            ->html('<div style="background: url(cid:logo)">')
            ->embed('image-bytes', 'logo', 'image/png');

        $this->assertSame('logo', $this->sendAndGetBody($email)['attachments'][0]['content_id']);
    }

    public function testAttachmentReferencedByTheHtmlIsSentInline()
    {
        $email = self::createEmail()
            ->html('<img src="cid:logo.png">')
            ->attach('image-bytes', 'logo.png', 'image/png');

        $this->assertSame('logo.png', $this->sendAndGetBody($email)['attachments'][0]['content_id']);
    }

    public function testNonAsciiDistinctIdIsSentAsIs()
    {
        $email = self::createEmail();
        $email->getHeaders()->addTextHeader('X-PostHog-Distinct-Id', '用户42');

        $this->assertSame('用户42', $this->sendAndGetBody($email)['distinct_id']);
    }

    public function testNonSeekableHtmlStreamIsReadOnce()
    {
        $email = self::createEmail()->html(self::createNonSeekableStream('<p>On its way.</p>'));

        $this->assertSame('<p>On its way.</p>', $this->sendAndGetBody($email)['html']);
    }

    public function testExplicitBodyWithTheSameTextAndHtmlIsSent()
    {
        $email = self::createEmail()->setBody(new AlternativePart(new TextPart('On its way.'), new TextPart('<p>On its way.</p>', 'utf-8', 'html')));

        $body = $this->sendAndGetBody($email);

        $this->assertSame('On its way.', $body['text']);
        $this->assertSame('<p>On its way.</p>', $body['html']);
    }

    public function testSendPostHogOptions()
    {
        $email = self::createEmail();
        $email->getHeaders()
            ->addTextHeader('X-PostHog-Distinct-Id', 'user_42')
            ->addTextHeader('X-PostHog-Template-Key', 'order-shipped')
            ->addTextHeader('X-PostHog-Idempotency-Key', 'order-981-shipped')
            ->addTextHeader('X-PostHog-Capture-Engagement', 'false');

        $client = new MockHttpClient(function (string $method, string $url, array $options): ResponseInterface {
            $this->assertSame('order-981-shipped', self::getHeader($options, 'Idempotency-Key'));
            $this->assertMatchesRegularExpression('{^symfony-mailer/}', self::getHeader($options, 'X-PostHog-Integration'));
            $this->assertNull(self::getHeader($options, 'X-PostHog-Distinct-Id'));

            $this->assertEquals([
                'to' => 'ana@example.com',
                'from' => 'Shop <shop@example.com>',
                'subject' => 'Your order shipped',
                'html' => '<p>On its way.</p>',
                'text' => 'On its way.',
                'template_key' => 'order-shipped',
                'distinct_id' => 'user_42',
                'capture_engagement' => false,
            ], json_decode($options['body'], true));

            return new JsonMockResponse(['message_id' => 'msg-1234', 'replayed' => false], ['http_code' => 202]);
        });

        (new PostHogApiTransport('KEY', $client))->send($email);
    }

    #[DataProvider('getTrackingData')]
    public function testTrackingHeaderSetsCaptureEngagementWhenBothFlagsAgree(TrackingHeader $tracking, ?bool $expected)
    {
        $email = self::createEmail();
        $email->getHeaders()->add($tracking);

        $body = $this->sendAndGetBody($email);

        $this->assertSame($expected, $body['capture_engagement'] ?? null);
        $this->assertSame(null !== $expected, \array_key_exists('capture_engagement', $body));
    }

    public static function getTrackingData(): iterable
    {
        yield 'both on' => [new TrackingHeader(true, true), true];
        yield 'both off' => [new TrackingHeader(false, false), false];
        yield 'both default' => [new TrackingHeader(), null];
    }

    public function testCaptureEngagementHeaderWinsOverTheTrackingHeader()
    {
        $email = self::createEmail();
        $email->getHeaders()
            ->add(new TrackingHeader(true, true))
            ->addTextHeader('X-PostHog-Capture-Engagement', 'false');

        $this->assertFalse($this->sendAndGetBody($email)['capture_engagement']);
    }

    public function testTemplateKeyComesFromADefaultHeader()
    {
        $email = self::createEmail();
        $email->getHeaders()->addTextHeader('X-PostHog-Template-Key', 'transactional');

        $this->assertSame('transactional', $this->sendAndGetBody($email)['template_key']);
    }

    public function testNormalPriorityIsIgnored()
    {
        $email = self::createEmail()->priority(Email::PRIORITY_NORMAL);

        $this->assertArrayNotHasKey('priority', $this->sendAndGetBody($email));
    }

    public function testFrameworkHeadersAreIgnored()
    {
        $email = self::createEmail();
        $email->getHeaders()
            ->addIdHeader('Message-ID', 'x@example.com')
            ->addDateHeader('Date', new \DateTimeImmutable('2026-11-05 10:00:00'))
            ->addTextHeader('MIME-Version', '1.0')
            ->addTextHeader('X-Mailer', 'Symfony');

        $this->assertArrayNotHasKey('headers', $this->sendAndGetBody($email));
    }

    public function testSendTemplate()
    {
        $email = (new RemoteTemplateEmail())
            ->from('shop@example.com')
            ->to(new Address('ana@example.com', 'Ana'))
            ->template('0190f0c2-7d1e-7a3b-9c2e-5f1a2b3c4d5e', ['order' => '981']);
        $email->getHeaders()->addTextHeader('X-PostHog-Distinct-Id', 'user_42');

        $client = new MockHttpClient(function (string $method, string $url, array $options): ResponseInterface {
            $this->assertMatchesRegularExpression('{^symfony-mailer/}', self::getHeader($options, 'X-PostHog-Integration'));

            $this->assertSame([
                'to' => 'ana@example.com',
                'template_id' => '0190f0c2-7d1e-7a3b-9c2e-5f1a2b3c4d5e',
                'message_data' => ['order' => '981'],
                'distinct_id' => 'user_42',
            ], json_decode($options['body'], true));

            return new JsonMockResponse(['message_id' => 'msg-5678', 'replayed' => false], ['http_code' => 202]);
        });

        $sentMessage = (new PostHogApiTransport('KEY', $client))->send($email);

        $this->assertSame('msg-5678', $sentMessage->getMessageId());
    }

    public function testSendTemplateWithoutVariables()
    {
        $email = (new RemoteTemplateEmail())
            ->from('shop@example.com')
            ->to('ana@example.com')
            ->template('0190f0c2-7d1e-7a3b-9c2e-5f1a2b3c4d5e');

        $this->assertSame([
            'to' => 'ana@example.com',
            'template_id' => '0190f0c2-7d1e-7a3b-9c2e-5f1a2b3c4d5e',
        ], $this->sendAndGetBody($email));
    }

    public function testTemplateSendLeavesOutTheOptionsOfTheRenderedForm()
    {
        $email = (new RemoteTemplateEmail())
            ->from('shop@example.com')
            ->to('ana@example.com')
            ->template('0190f0c2-7d1e-7a3b-9c2e-5f1a2b3c4d5e');
        $email->getHeaders()
            ->addTextHeader('X-PostHog-Template-Key', 'order-shipped')
            ->addTextHeader('X-PostHog-Capture-Engagement', 'false');

        $this->assertSame([
            'to' => 'ana@example.com',
            'template_id' => '0190f0c2-7d1e-7a3b-9c2e-5f1a2b3c4d5e',
        ], $this->sendAndGetBody($email));
    }

    public function testRemoteTemplateEmailWithoutTemplateIsSentRendered()
    {
        $email = (new RemoteTemplateEmail())
            ->from('shop@example.com')
            ->to('ana@example.com')
            ->subject('Your order shipped')
            ->text('On its way.');

        $body = $this->sendAndGetBody($email);

        $this->assertSame('On its way.', $body['text']);
        $this->assertArrayNotHasKey('template_id', $body);
    }

    #[DataProvider('getRefusedEmails')]
    public function testRefusesWithoutSending(Email $email, string $code, ?Envelope $envelope = null)
    {
        $transport = new PostHogApiTransport('KEY', new MockHttpClient(function (): ResponseInterface {
            $this->fail('No request must be sent.');
        }));

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageMatches('{^PostHog cannot send this email: '.$code.'\b}');

        $transport->send($email, $envelope);
    }

    public static function getRefusedEmails(): iterable
    {
        $email = self::createEmail();
        $email->getHeaders()->addTextHeader('X-PostHog-Template-Key', 'order shipped!');
        yield 'invalid template key' => [$email, 'invalid_template_key'];

        yield 'more than one recipient' => [self::createEmail()->to('ana@example.com', 'bo@example.com'), 'multiple_to'];

        yield 'no recipient in to' => [self::createEmail()->to()->cc('bo@example.com'), 'multiple_to'];

        yield 'inline part without content id' => [
            self::createEmail()->addPart((new DataPart('image-bytes', 'logo.png', 'image/png'))->asInline()),
            'inline_without_cid',
        ];

        $email = self::createEmail();
        $email->getHeaders()
            ->addIdHeader('Message-ID', 'x@example.com')
            ->addTextHeader('X-Campaign', 'spring');
        yield 'custom header' => [$email, 'unsupported_header'];

        $email = self::createEmail();
        $email->getHeaders()->addTextHeader('X-PostHog-Unknown', 'value');
        yield 'unknown option header' => [$email, 'unsupported_header'];

        $email = self::createEmail();
        $email->getHeaders()->addTextHeader('X-PostHog-Capture-Engagement', 'yes');
        yield 'capture engagement that is not a boolean' => [$email, 'unsupported_header'];

        yield 'several senders' => [self::createEmail()->from('shop@example.com', 'help@example.com')->sender('shop@example.com'), 'unsupported_header'];

        $email = self::createEmail();
        $email->getHeaders()->add(new TagHeader('receipts'));
        yield 'tags' => [$email, 'tags'];

        $email = self::createEmail();
        $email->getHeaders()->add(new MetadataHeader('order', '981'));
        yield 'metadata' => [$email, 'metadata'];

        yield 'envelope sender differs from the sender' => [
            self::createEmail(),
            'envelope_mismatch',
            new Envelope(new Address('bounce@example.com'), [new Address('ana@example.com')]),
        ];

        yield 'envelope recipients differ from the recipients' => [
            self::createEmail(),
            'envelope_mismatch',
            new Envelope(new Address('shop@example.com'), [new Address('ana@example.com'), new Address('other@example.com')]),
        ];

        $email = self::createEmail();
        $email->getHeaders()->add(new TrackingHeader(true, false));
        yield 'mixed tracking' => [$email, 'mixed_tracking'];

        yield 'explicit body with an AMP part' => [
            self::createEmail()->setBody(new AlternativePart(new TextPart('On its way.'), new TextPart('<p>On its way.</p>', 'utf-8', 'html'), new TextPart('<!doctype html><html amp4email></html>', 'utf-8', 'x-amp-html'))),
            'unsupported_part',
        ];

        yield 'explicit body with a part that is not an attachment' => [
            self::createEmail()->setBody(new MixedPart(new TextPart('On its way.'), new DataPart('BEGIN:VCALENDAR', 'invite.ics', 'text/calendar'))),
            'unsupported_part',
        ];

        yield 'explicit body with a second text part' => [
            self::createEmail()->setBody(new MixedPart(new TextPart('On its way.'), new TextPart('And a second one.'))),
            'unsupported_part',
        ];

        yield 'explicit HTML part without an HTML body' => [
            (new Email())->from('shop@example.com')->to('ana@example.com')->subject('Your order shipped')->text('On its way.')->setBody(new AlternativePart(new TextPart('On its way.'), new TextPart('<p>On its way.</p>', 'utf-8', 'html'))),
            'unsupported_part',
        ];

        $email = self::createEmail();
        $email->getHeaders()
            ->add(new TrackingHeader(true, false))
            ->addTextHeader('X-PostHog-Capture-Engagement', 'true');
        yield 'mixed tracking with a capture engagement header' => [$email, 'mixed_tracking'];

        yield 'priority other than normal' => [self::createEmail()->priority(Email::PRIORITY_HIGH), 'unsupported_priority'];

        yield 'template with a subject' => [
            (new RemoteTemplateEmail())
                ->from('shop@example.com')
                ->to('ana@example.com')
                ->subject('Your order shipped')
                ->template('0190f0c2-7d1e-7a3b-9c2e-5f1a2b3c4d5e'),
            'template_with_content',
        ];

        yield 'template with a copy recipient' => [
            (new RemoteTemplateEmail())
                ->from('shop@example.com')
                ->to('ana@example.com')
                ->cc('bo@example.com')
                ->template('0190f0c2-7d1e-7a3b-9c2e-5f1a2b3c4d5e'),
            'template_with_content',
        ];

        yield 'template with an attachment' => [
            (new RemoteTemplateEmail())
                ->from('shop@example.com')
                ->to('ana@example.com')
                ->attach('%PDF-1.4', 'invoice.pdf', 'application/pdf')
                ->template('0190f0c2-7d1e-7a3b-9c2e-5f1a2b3c4d5e'),
            'template_with_content',
        ];

        $email = self::createTemplateEmail();
        $email->getHeaders()->addTextHeader('X-PostHog-Template-Key', 'order shipped!');
        yield 'template with an invalid template key' => [$email, 'invalid_template_key'];

        $email = self::createTemplateEmail();
        $email->getHeaders()->addTextHeader('X-PostHog-Capture-Engagement', 'yes');
        yield 'template with a capture engagement that is not a boolean' => [$email, 'unsupported_header'];

        $email = self::createTemplateEmail();
        $email->getHeaders()->add(new TrackingHeader(true, false));
        yield 'template with mixed tracking' => [$email, 'mixed_tracking'];
    }

    #[DataProvider('getErrorResponses')]
    public function testErrorResponse(MockResponse $response, string $message, int $statusCode, ?string $retryAfter = null)
    {
        $transport = new PostHogApiTransport('KEY', new MockHttpClient($response));

        try {
            $transport->send(self::createEmail());
            $this->fail('The send must fail.');
        } catch (HttpTransportException $e) {
            $this->assertSame($message, $e->getMessage());
            $this->assertSame($statusCode, $e->getResponse()->getStatusCode());
            $this->assertSame($retryAfter, $e->getResponse()->getHeaders(false)['retry-after'][0] ?? null);
        }
    }

    public static function getErrorResponses(): iterable
    {
        yield 'invalid request' => [
            self::createErrorResponse(400, 'invalid_input', 'Enter a valid email address.', 'to'),
            'Unable to send an email: Enter a valid email address. [invalid_input, field "to"] (code 400).',
            400,
        ];

        yield 'wrong key' => [
            self::createErrorResponse(401, 'authentication_failed', 'Invalid secret key.'),
            'Unable to send an email: Invalid secret key. [authentication_failed] (code 401).',
            401,
        ];

        yield 'missing scope' => [
            self::createErrorResponse(403, 'permission_denied', 'The key does not have the transactional_email:write scope.'),
            'Unable to send an email: The key does not have the transactional_email:write scope. [permission_denied] (code 403).',
            403,
        ];

        yield 'unknown template' => [
            self::createErrorResponse(404, 'not_found', 'Template not found.', 'template_id'),
            'Unable to send an email: Template not found. [not_found, field "template_id"] (code 404).',
            404,
        ];

        yield 'same key still in flight' => [
            self::createErrorResponse(409, 'idempotency_key_in_flight', 'A send with this key is in progress.'),
            'Unable to send an email: A send with this key is in progress. [idempotency_key_in_flight] (code 409).',
            409,
        ];

        yield 'suppressed recipient' => [
            self::createErrorResponse(422, 'recipient_suppressed', 'The recipient is suppressed.'),
            'Unable to send an email: The recipient is suppressed. [recipient_suppressed] (code 422).',
            422,
        ];

        yield 'over quota' => [
            self::createErrorResponse(429, 'quota_limited', 'The email quota is used up.'),
            'Unable to send an email: The email quota is used up. [quota_limited] (code 429).',
            429,
        ];

        yield 'throttled' => [
            self::createErrorResponse(429, 'throttled', 'Request was throttled.', null, ['Retry-After' => '12']),
            'Unable to send an email: Request was throttled. [throttled] (code 429).',
            429,
            '12',
        ];

        yield 'unavailable' => [
            self::createErrorResponse(503, 'service_unavailable', 'Try again with the same key.'),
            'Unable to send an email: Try again with the same key. [service_unavailable] (code 503).',
            503,
        ];

        yield 'other server error' => [
            new MockResponse('<html>Bad gateway</html>', ['http_code' => 502]),
            'Unable to send an email: <html>Bad gateway</html> (code 502).',
            502,
        ];
    }

    public function testNetworkError()
    {
        $transport = new PostHogApiTransport('KEY', new MockHttpClient(new MockResponse('', ['error' => 'Connection reset'])));

        $this->expectException(HttpTransportException::class);
        $this->expectExceptionMessage('Could not reach the remote PostHog server.');

        $transport->send(self::createEmail());
    }

    private function sendAndGetBody(Email $email): array
    {
        $body = null;
        $client = new MockHttpClient(static function (string $method, string $url, array $options) use (&$body): ResponseInterface {
            $body = json_decode($options['body'], true);

            return new JsonMockResponse(['message_id' => 'msg-1234', 'replayed' => false], ['http_code' => 202]);
        });

        (new PostHogApiTransport('KEY', $client))->send($email);

        return $body;
    }

    private static function createEmail(): Email
    {
        return (new Email())
            ->from(new Address('shop@example.com', 'Shop'))
            ->to(new Address('ana@example.com', 'Ana'))
            ->subject('Your order shipped')
            ->html('<p>On its way.</p>')
            ->text('On its way.');
    }

    private static function createNonSeekableStream(string $contents)
    {
        $wrapper = new class {
            public static string $contents = '';
            public mixed $context;
            private string $left = '';

            public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
            {
                $this->left = self::$contents;

                return true;
            }

            public function stream_read(int $count): string
            {
                $chunk = substr($this->left, 0, $count);
                $this->left = substr($this->left, \strlen($chunk));

                return $chunk;
            }

            public function stream_eof(): bool
            {
                return '' === $this->left;
            }

            public function stream_seek(int $offset, int $whence): bool
            {
                return false;
            }

            public function stream_stat(): array
            {
                return [];
            }
        };
        $wrapper::$contents = $contents;

        stream_wrapper_register('posthog-test', $wrapper::class);

        try {
            return fopen('posthog-test://body', 'r');
        } finally {
            stream_wrapper_unregister('posthog-test');
        }
    }

    private static function createTemplateEmail(): RemoteTemplateEmail
    {
        return (new RemoteTemplateEmail())
            ->from('shop@example.com')
            ->to('ana@example.com')
            ->template('0190f0c2-7d1e-7a3b-9c2e-5f1a2b3c4d5e');
    }

    private static function createErrorResponse(int $statusCode, string $code, string $detail, ?string $attr = null, array $headers = []): MockResponse
    {
        return new JsonMockResponse(['type' => 'validation_error', 'code' => $code, 'detail' => $detail, 'attr' => $attr], [
            'http_code' => $statusCode,
            'response_headers' => $headers,
        ]);
    }

    private static function getHeader(array $options, string $name): ?string
    {
        foreach ($options['headers'] as $header) {
            if (str_starts_with(strtolower($header), strtolower($name).': ')) {
                return substr($header, \strlen($name) + 2);
            }
        }

        return null;
    }
}
