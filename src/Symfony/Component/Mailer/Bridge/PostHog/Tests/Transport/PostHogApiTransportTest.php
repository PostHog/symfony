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

    #[DataProvider('getSenderNames')]
    public function testSenderNameIsQuotedOnlyWhenItHoldsAReservedCharacter(string $name, string $expected)
    {
        $email = self::createEmail()->from(new Address('shop@example.com', $name));

        $this->assertSame($expected, $this->sendAndGetBody($email)['from']);
    }

    public static function getSenderNames(): iterable
    {
        yield 'plain' => ['Shop', 'Shop <shop@example.com>'];
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

        $requests = [];
        (new PostHogApiTransport('KEY', self::createPostHog($requests)))->setHost('eu.posthog.com')->send($email);

        [$uploads, $invoice, $logo, $send] = $requests;

        $this->assertSame(['POST', 'https://eu.posthog.com/api/projects/@current/messaging/attachments/uploads/'], [$uploads['method'], $uploads['url']]);
        $this->assertContains('Authorization: Bearer KEY', $uploads['options']['headers']);
        $this->assertSame(['files' => [
            ['sha256' => hash('sha256', '%PDF-1.4'."\n"), 'size' => 9],
            ['sha256' => hash('sha256', 'image-bytes'), 'size' => 11],
        ]], json_decode($uploads['options']['body'], true));

        $this->assertSame(['PUT', 'https://storage.example.com/'.hash('sha256', '%PDF-1.4'."\n")], [$invoice['method'], $invoice['url']]);
        $this->assertSame('%PDF-1.4'."\n", $invoice['options']['body']);
        $this->assertContains('x-amz-checksum-sha256: '.hash('sha256', '%PDF-1.4'."\n"), $invoice['options']['headers']);
        $this->assertNull(self::getHeader($invoice['options'], 'Authorization'));
        $this->assertSame(0, $invoice['options']['max_redirects']);
        $this->assertSame('image-bytes', $logo['options']['body']);

        $body = json_decode($send['options']['body'], true);
        $this->assertSame('https://eu.posthog.com/api/projects/@current/messaging/send/email/', $send['url']);
        $this->assertSame('<img src="cid:logo@example.com">', $body['html']);
        $this->assertSame([
            [
                'sha256' => hash('sha256', '%PDF-1.4'."\n"),
                'filename' => 'invoice.pdf',
                'content_type' => 'application/pdf',
            ],
            [
                'sha256' => hash('sha256', 'image-bytes'),
                'filename' => 'logo.png',
                'content_type' => 'image/png',
                'content_id' => 'logo@example.com',
            ],
        ], $body['attachments']);
    }

    public function testEmailWithoutAttachmentsUploadsNothing()
    {
        $requests = [];
        (new PostHogApiTransport('KEY', self::createPostHog($requests)))->send(self::createEmail());

        $this->assertCount(1, $requests);
        $this->assertStringEndsWith('/messaging/send/email/', $requests[0]['url']);
    }

    public function testFileThatPostHogAlreadyHasIsNotUploaded()
    {
        $email = self::createEmail()->attach('%PDF-1.4', 'invoice.pdf', 'application/pdf');

        $requests = [];
        (new PostHogApiTransport('KEY', self::createPostHog($requests, [hash('sha256', '%PDF-1.4')])))->send($email);

        $this->assertSame(['POST', 'POST'], array_column($requests, 'method'));
        $this->assertSame(hash('sha256', '%PDF-1.4'), json_decode($requests[1]['options']['body'], true)['attachments'][0]['sha256']);
    }

    public function testSameFileInTwoAttachmentsIsUploadedOnce()
    {
        $email = self::createEmail()
            ->attach('%PDF-1.4', 'invoice.pdf', 'application/pdf')
            ->attach('%PDF-1.4', 'copy.pdf', 'application/pdf');

        $requests = [];
        (new PostHogApiTransport('KEY', self::createPostHog($requests)))->send($email);

        $this->assertSame(['POST', 'PUT', 'POST'], array_column($requests, 'method'));
        $this->assertSame([['sha256' => hash('sha256', '%PDF-1.4'), 'size' => 8]], json_decode($requests[0]['options']['body'], true)['files']);
        $this->assertSame(['invoice.pdf', 'copy.pdf'], array_column(json_decode($requests[2]['options']['body'], true)['attachments'], 'filename'));
    }

    #[DataProvider('getUploadFailures')]
    public function testFailedUploadSendsNoEmail(array $uploadsAnswer, ?MockResponse $storageAnswer, string $message)
    {
        $email = self::createEmail()->attach('%PDF-1.4', 'invoice.pdf', 'application/pdf');

        $requests = [];
        $client = new MockHttpClient(function (string $method, string $url, array $options) use (&$requests, $uploadsAnswer, $storageAnswer): ResponseInterface {
            $requests[] = $method.' '.$url;

            if (str_ends_with($url, '/messaging/send/email/')) {
                $this->fail('No email must be sent.');
            }

            if (str_ends_with($url, '/messaging/attachments/uploads/')) {
                return new JsonMockResponse(...$uploadsAnswer);
            }

            return $storageAnswer ?? $this->fail('No file must be uploaded.');
        });

        try {
            (new PostHogApiTransport('KEY', $client))->send($email);
            $this->fail('The send must fail.');
        } catch (HttpTransportException $e) {
            $this->assertSame($message, $e->getMessage());
        }
    }

    public static function getUploadFailures(): iterable
    {
        $slot = static fn (array $slot): array => [['files' => [$slot + ['sha256' => hash('sha256', '%PDF-1.4')]]]];
        $upload = ['status' => 'upload', 'url' => 'https://storage.example.com/invoice', 'method' => 'PUT', 'headers' => []];

        yield 'project cannot send files' => [
            [['type' => 'validation_error', 'code' => 'field_not_accepted', 'detail' => "This project can't send files yet, so PostHog didn't prepare the uploads.", 'attr' => 'files'], ['http_code' => 400]],
            null,
            'Unable to upload the attachments: This project can\'t send files yet, so PostHog didn\'t prepare the uploads. [field_not_accepted, field "files"] (code 400).',
        ];

        yield 'daily upload quota used up' => [
            [['type' => 'throttled_error', 'code' => 'upload_quota_exceeded', 'detail' => 'This project uploaded as many file bytes as it can today.'], ['http_code' => 429]],
            null,
            'Unable to upload the attachments: This project uploaded as many file bytes as it can today. [upload_quota_exceeded] (code 429).',
        ];

        yield 'upload URL that is not https' => [
            $slot(['url' => 'http://storage.example.com/invoice'] + $upload),
            null,
            'Unable to upload the attachment "invoice.pdf": PostHog named an upload URL that is not https.',
        ];

        yield 'no slot for the file' => [
            [['files' => []]],
            null,
            'Unable to upload the attachments: PostHog answered without an upload slot for each file.',
        ];

        yield 'slot for another file' => [
            [['files' => [['sha256' => hash('sha256', 'other'), 'status' => 'present']]]],
            null,
            'Unable to upload the attachments: PostHog answered without an upload slot for each file.',
        ];

        yield 'upload slot without a URL' => [
            $slot(['status' => 'upload', 'method' => 'PUT', 'headers' => []]),
            null,
            'Unable to upload the attachments: PostHog answered without an upload slot for each file.',
        ];

        yield 'storage refuses the upload' => [
            $slot($upload),
            new MockResponse('<?xml version="1.0"?><Error><Code>AccessDenied</Code><Message>Request has expired</Message></Error>', ['http_code' => 403]),
            'Unable to upload the attachment "invoice.pdf": AccessDenied, Request has expired (code 403).',
        ];

        yield 'storage redirects' => [
            $slot($upload),
            new MockResponse('', ['http_code' => 307, 'response_headers' => ['Location' => 'https://elsewhere.example.com/']]),
            'Unable to upload the attachment "invoice.pdf": the file storage answered (code 307).',
        ];

        yield 'storage cannot be reached' => [
            $slot($upload),
            new MockResponse('', ['error' => 'Connection refused']),
            'Unable to upload the attachment "invoice.pdf": could not reach the file storage of PostHog.',
        ];

        yield 'storage disconnects while it answers an error' => [
            $slot($upload),
            new MockResponse((static function (): iterable {
                yield '<?xml version="1.0"?><Error>';

                throw new \RuntimeException('Connection reset by peer');
            })(), ['http_code' => 500]),
            'Unable to upload the attachment "invoice.pdf": could not reach the file storage of PostHog.',
        ];
    }

    public function testEmbeddedImageReferencedByItsNameUsesTheNameAsContentId()
    {
        $email = self::createEmail()
            ->html('<img src="cid:logo">')
            ->embed('image-bytes', 'logo', 'image/png');

        $this->assertSame([[
            'sha256' => hash('sha256', 'image-bytes'),
            'filename' => 'logo',
            'content_type' => 'image/png',
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

    public function testInlinePartWithoutANameKeepsTheOtherReferencesOfTheHtml()
    {
        $email = self::createEmail()
            ->html('<img src="cid:banner@cdn.example">')
            ->addPart((new DataPart('logo-bytes', null, 'image/png'))->asInline()->setContentId('logo@shop.example'));

        $body = $this->sendAndGetBody($email);

        $this->assertSame('<img src="cid:banner@cdn.example">', $body['html']);
        $this->assertSame('logo@shop.example', $body['attachments'][0]['content_id']);
    }

    public function testUnquotedReferenceEndsAtTheClosingParenthesis()
    {
        $email = self::createEmail()
            ->html('<div style="background: url(cid:logo)">')
            ->embed('image-bytes', 'logo', 'image/png');

        $this->assertSame('logo', $this->sendAndGetBody($email)['attachments'][0]['content_id']);
    }

    public function testStyleReferenceToAPartWithItsOwnContentIdIsSentAsWritten()
    {
        $email = self::createEmail()
            ->html('<div style="background: url(cid:logo)">')
            ->addPart((new DataPart('image-bytes', 'logo', 'image/png'))->asInline()->setContentId('logo@shop.example'));

        $body = $this->sendAndGetBody($email);

        $this->assertSame('<div style="background: url(cid:logo)">', $body['html']);
        $this->assertSame('logo', $body['attachments'][0]['content_id']);
    }

    public function testContentIdHeaderIsTheContentIdOfThePart()
    {
        $logo = new DataPart('image-bytes', 'logo.png', 'image/png');
        $logo->getHeaders()->addIdHeader('Content-ID', 'logo@shop.example');
        $email = self::createEmail()->html('<img src="cid:logo@shop.example">')->addPart($logo);

        $this->assertSame('logo@shop.example', $this->sendAndGetBody($email)['attachments'][0]['content_id']);
    }

    public function testContentIdTextHeaderIsReadLikeAnIdHeader()
    {
        $logo = new DataPart('image-bytes', 'logo.png', 'image/png');
        $logo->getHeaders()->addTextHeader('Content-ID', '<logo@shop.example>');
        $email = self::createEmail()->html('<img src="cid:logo@shop.example">')->addPart($logo);

        $this->assertSame('logo@shop.example', $this->sendAndGetBody($email)['attachments'][0]['content_id'] ?? null);
    }

    public function testInlinePartWithAContentIdHeaderKeepsIt()
    {
        $logo = (new DataPart('image-bytes', 'logo.png', 'image/png'))->asInline();
        $logo->getHeaders()->addIdHeader('Content-ID', 'logo@shop.example');
        $email = self::createEmail()->addPart($logo);

        $this->assertSame('logo@shop.example', $this->sendAndGetBody($email)['attachments'][0]['content_id']);
    }

    public function testBodyThatEmailBuiltKeepsTheReferencesItDoesNotRewrite()
    {
        $html = '<img src="cid:logo"><div style="background: url(cid:logo)">';
        $email = self::createEmail()->html($html)->embed('image-bytes', 'logo', 'image/png');
        $email->setBody($email->getBody());

        $body = $this->sendAndGetBody($email);

        $this->assertSame($html, $body['html']);
        $this->assertSame('logo', $body['attachments'][0]['content_id']);
    }

    public function testAttachmentWithoutANameIsNamedAfterItsType()
    {
        $email = self::createEmail()
            ->addPart(new DataPart('%PDF-1.4', null, 'application/pdf'))
            ->addPart(new DataPart('bytes', null, 'application/x-unknown-type'));

        $attachments = $this->sendAndGetBody($email)['attachments'];

        $this->assertSame('attachment.pdf', $attachments[0]['filename']);
        $this->assertSame('attachment', $attachments[1]['filename']);
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

    public function testExplicitBodyWithTheHtmlAsWrittenIsSent()
    {
        $html = '<img src="cid:logo">';
        $logo = new DataPart('image-bytes', 'logo', 'image/png');
        $email = self::createEmail()
            ->html($html)
            ->addPart($logo)
            ->setBody(new MixedPart(new AlternativePart(new TextPart('On its way.'), new TextPart($html, 'utf-8', 'html')), $logo));

        $body = $this->sendAndGetBody($email);

        $this->assertSame($html, $body['html']);
        $this->assertSame('logo', $body['attachments'][0]['content_id']);
    }

    public function testBodyThatEmailBuiltIsSentWhenSetExplicitly()
    {
        $email = self::createEmail()
            ->html('<img src="cid:logo">')
            ->embed('image-bytes', 'logo', 'image/png');
        $email->setBody($email->getBody());

        $body = $this->sendAndGetBody($email);

        $this->assertSame('<img src="cid:logo">', $body['html']);
        $this->assertSame('logo', $body['attachments'][0]['content_id']);
    }

    public function testExplicitBodyLeavesTheAttachmentsOfTheEmailAsTheyAre()
    {
        $html = '<img src="cid:logo@shop.example">';
        $logo = (new DataPart('image-bytes', null, 'image/png'))->setContentId('logo@shop.example');
        $email = self::createEmail()
            ->html($html)
            ->addPart($logo)
            ->setBody(new MixedPart(new AlternativePart(new TextPart('On its way.'), new TextPart($html, 'utf-8', 'html')), $logo));

        $this->assertSame('logo@shop.example', $this->sendAndGetBody($email)['attachments'][0]['content_id']);
        $this->assertNull($logo->getName());
        $this->assertSame('attachment', $logo->getDisposition());
    }

    public function testSendPostHogOptions()
    {
        $email = self::createEmail();
        $email->getHeaders()
            ->addTextHeader('X-PostHog-Distinct-Id', 'user_42')
            ->addTextHeader('X-PostHog-Template-Key', 'order-shipped')
            ->addTextHeader('X-PostHog-Idempotency-Key', 'order-981-shipped')
            ->addTextHeader('X-PostHog-Capture-Engagement', 'false')
            ->addTextHeader('X-PostHog-Sensitive', 'true');

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
                'tracking' => ['engagement' => false],
                'sensitive' => true,
            ], json_decode($options['body'], true));

            return new JsonMockResponse(['message_id' => 'msg-1234', 'replayed' => false], ['http_code' => 202]);
        });

        (new PostHogApiTransport('KEY', $client))->send($email);
    }

    #[DataProvider('getTrackingData')]
    public function testTrackingHeaderSetsOpenAndClickTracking(TrackingHeader $tracking, ?array $expected)
    {
        $email = self::createEmail();
        $email->getHeaders()->add($tracking);

        $body = $this->sendAndGetBody($email);

        $this->assertSame($expected, $body['tracking'] ?? null);
        $this->assertArrayNotHasKey('capture_engagement', $body);
    }

    public static function getTrackingData(): iterable
    {
        yield 'both on' => [new TrackingHeader(true, true), ['opens' => true, 'clicks' => true]];
        yield 'both off' => [new TrackingHeader(false, false), ['opens' => false, 'clicks' => false]];
        yield 'opens without clicks' => [new TrackingHeader(true, false), ['opens' => true, 'clicks' => false]];
        yield 'opens only' => [new TrackingHeader(true), ['opens' => true]];
        yield 'clicks only' => [new TrackingHeader(null, true), ['clicks' => true]];
        yield 'clicks without opens, which PostHog refuses' => [new TrackingHeader(false, true), ['opens' => false, 'clicks' => true]];
        yield 'both default' => [new TrackingHeader(), null];
    }

    #[DataProvider('getTrackingTextData')]
    public function testTrackingTextHeaderIsReadLikeTheTrackingHeader(string $value, ?array $expected)
    {
        $this->assertSame($expected, $this->sendAndGetBody(self::createEmail(['X-Track' => $value]))['tracking'] ?? null);
    }

    public static function getTrackingTextData(): iterable
    {
        yield 'both set' => ['opens=true; clicks=false', ['opens' => true, 'clicks' => false]];
        yield 'clicks without opens, which PostHog refuses' => ['opens=false; clicks=true', ['opens' => false, 'clicks' => true]];
        yield 'default flag left out' => ['opens=default; clicks=true', ['clicks' => true]];
        yield 'as TrackingHeader writes it' => [(new TrackingHeader(true))->getBodyAsString(), ['opens' => true]];
        yield 'spaces and a trailing separator' => [' opens = false ; ', ['opens' => false]];
        yield 'empty part after a separator' => ['opens=true;', ['opens' => true]];
    }

    public function testClicksWithoutOpensIsSentInOneRequest()
    {
        $email = self::createEmail(['X-Track' => 'opens=false; clicks=true']);

        $requests = [];
        (new PostHogApiTransport('KEY', self::createPostHog($requests)))->send($email);

        $this->assertCount(1, $requests);
        $this->assertSame(['opens' => false, 'clicks' => true], json_decode($requests[0]['options']['body'], true)['tracking']);
    }

    #[DataProvider('getCharsetData')]
    public function testBodiesAreSentAsUtf8(Email $email, string $field, string $expected)
    {
        $this->assertSame($expected, $this->sendAndGetBody($email)[$field]);
    }

    public static function getCharsetData(): iterable
    {
        yield 'latin-1 text' => [self::createEmail()->text("Gr\xfc\xdfe", 'iso-8859-1'), 'text', 'Grüße'];
        yield 'latin-1 HTML' => [self::createEmail()->html("<p>Gr\xfc\xdfe</p>", 'ISO-8859-1'), 'html', '<p>Grüße</p>'];
        yield 'utf-8 text' => [self::createEmail()->text('Grüße'), 'text', 'Grüße'];
    }

    public function testCaptureEngagementHeaderJoinsTheTrackingHeader()
    {
        $email = self::createEmail();
        $email->getHeaders()
            ->add(new TrackingHeader(true, true))
            ->addTextHeader('X-PostHog-Capture-Engagement', 'false');

        $body = $this->sendAndGetBody($email);

        $this->assertSame(['opens' => true, 'clicks' => true, 'engagement' => false], $body['tracking']);
        $this->assertArrayNotHasKey('capture_engagement', $body);
    }

    #[DataProvider('getSensitiveData')]
    public function testSensitiveHeaderIsPassedThrough(string $value, bool $expected)
    {
        $email = self::createEmail();
        $email->getHeaders()->addTextHeader('X-PostHog-Sensitive', $value);

        $this->assertSame($expected, $this->sendAndGetBody($email)['sensitive']);
    }

    public static function getSensitiveData(): iterable
    {
        yield 'sensitive' => ['true', true];
        yield 'not sensitive' => ['false', false];
    }

    public function testEmailWithoutOptionsLeavesTrackingAndSensitivityToPostHog()
    {
        $body = $this->sendAndGetBody(self::createEmail());

        $this->assertArrayNotHasKey('tracking', $body);
        $this->assertArrayNotHasKey('sensitive', $body);
    }

    public function testTemplateKeyOfMaximumLengthIsSent()
    {
        $email = self::createEmail();
        $email->getHeaders()->addTextHeader('X-PostHog-Template-Key', str_repeat('k', 256));

        $this->assertSame(str_repeat('k', 256), $this->sendAndGetBody($email)['template_key']);
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
            ->addTextHeader('X-PostHog-Capture-Engagement', 'false')
            ->addTextHeader('X-PostHog-Sensitive', 'true')
            ->add(new TrackingHeader(true, false));

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

    public function testSenderAndReturnPathOfTheFromAddressAreSent()
    {
        $email = self::createEmail()->sender('shop@example.com')->returnPath('shop@example.com');

        $this->assertSame('Shop <shop@example.com>', $this->sendAndGetBody($email)['from']);
    }

    public function testInlineTextPartOfAnExplicitBodyIsSent()
    {
        $email = self::createEmail()->setBody(new AlternativePart((new TextPart('On its way.'))->setDisposition('inline'), new TextPart('<p>On its way.</p>', 'utf-8', 'html')));

        $this->assertSame('On its way.', $this->sendAndGetBody($email)['text']);
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

        yield 'sender header other than the From address' => [
            self::createEmail()->sender('bounce@example.com'),
            'envelope_mismatch',
            new Envelope(new Address('shop@example.com'), [new Address('ana@example.com')]),
        ];

        $email = self::createEmail()->returnPath('shop@example.com');
        $email->getHeaders()->addPathHeader('Return-Path', 'bounce@example.com');
        yield 'second return path other than the From address' => [
            $email,
            'envelope_mismatch',
            new Envelope(new Address('shop@example.com'), [new Address('ana@example.com')]),
        ];

        yield 'return path other than the From address' => [
            self::createEmail()->returnPath('bounce@example.com'),
            'envelope_mismatch',
            new Envelope(new Address('shop@example.com'), [new Address('ana@example.com')]),
        ];

        yield 'explicit body with the text shown as an attachment' => [
            self::createEmail()->setBody(new AlternativePart((new TextPart('On its way.'))->setDisposition('attachment'), new TextPart('<p>On its way.</p>', 'utf-8', 'html'))),
            'unsupported_part',
        ];

        yield 'inline part referred to by its name and its content id' => [
            self::createEmail()
                ->html('<img src="cid:logo"><img src="cid:logo@shop.example">')
                ->addPart((new DataPart('image-bytes', 'logo', 'image/png'))->asInline()->setContentId('logo@shop.example')),
            'unsupported_part',
        ];

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
        $email->getHeaders()->addTextHeader('X-PostHog-Template-Key', str_repeat('k', 257));
        yield 'template key longer than 256 characters' => [$email, 'invalid_template_key'];

        $email = self::createEmail();
        $email->getHeaders()->addTextHeader('X-PostHog-Sensitive', 'yes');
        yield 'sensitive that is not a boolean' => [$email, 'unsupported_header'];

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

        yield 'second tracking header' => [self::createEmail(['X-Track' => 'opens=true', 'x-track' => 'clicks=false']), 'unsupported_header'];

        yield 'tracking flag that is not true, false or default' => [self::createEmail(['X-Track' => 'opens=yes']), 'unsupported_header'];

        yield 'unknown tracking flag' => [self::createEmail(['X-Track' => 'open=false']), 'unsupported_header'];

        yield 'tracking part without a value' => [self::createEmail(['X-Track' => 'opens']), 'unsupported_header'];

        yield 'top-level content header' => [self::createEmail(['Content-Language' => 'de']), 'unsupported_header'];

        yield 'explicit body without the HTML of the email' => [
            self::createEmail()->setBody(new TextPart('On its way.')),
            'unsupported_part',
        ];

        yield 'explicit body without an attachment of the email' => [
            self::createEmail()->attach('%PDF-1.4', 'invoice.pdf', 'application/pdf')->setBody(new AlternativePart(new TextPart('On its way.'), new TextPart('<p>On its way.</p>', 'utf-8', 'html'))),
            'unsupported_part',
        ];

        yield 'explicit body with another text' => [
            self::createEmail()->setBody(new AlternativePart(new TextPart('Something else.'), new TextPart('<p>On its way.</p>', 'utf-8', 'html'))),
            'unsupported_part',
        ];

        yield 'explicit body with another HTML' => [
            self::createEmail()->setBody(new AlternativePart(new TextPart('On its way.'), new TextPart('<p>Something else.</p>', 'utf-8', 'html'))),
            'unsupported_part',
        ];

        yield 'explicit body with the text in another charset' => [
            self::createEmail()->setBody(new AlternativePart(new TextPart('On its way.', 'iso-8859-1'), new TextPart('<p>On its way.</p>', 'utf-8', 'html'))),
            'unsupported_part',
        ];

        yield 'attachment with a content type parameter' => [
            self::createEmail()->addPart((new DataPart('BEGIN:VCALENDAR', 'invite.ics', 'text/calendar'))->setContentTypeParameter('method', 'REQUEST')),
            'unsupported_part',
        ];

        foreach (['X-PostHog-Distinct-Id' => 'user-42', 'X-PostHog-Template-Key' => 'receipt', 'X-PostHog-Idempotency-Key' => 'order-42', 'X-PostHog-Sensitive' => 'true', 'X-PostHog-Capture-Engagement' => 'true'] as $name => $value) {
            $email = self::createEmail([$name => $value]);
            $email->getHeaders()->addTextHeader($name, $value);
            yield 'second '.$name.' header' => [$email, 'unsupported_header'];
        }

        yield 'text in a charset that cannot be converted' => [self::createEmail()->text('On its way.', 'x-unknown-charset'), 'unsupported_part'];

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
        $email->getHeaders()->addTextHeader('X-Track', 'opens=yes');
        yield 'template with a tracking flag that is not true, false or default' => [$email, 'unsupported_header'];

        $email = self::createTemplateEmail();
        $email->getHeaders()->addTextHeader('X-PostHog-Sensitive', 'yes');
        yield 'template with a sensitive that is not a boolean' => [$email, 'unsupported_header'];
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
        yield 'clicks without opens' => [
            self::createErrorResponse(400, 'clicks_need_opens', 'Click tracking needs open tracking. Turn on `opens`, or turn off `clicks`.', 'tracking'),
            'Unable to send an email: Click tracking needs open tracking. Turn on `opens`, or turn off `clicks`. [clicks_need_opens, field "tracking"] (code 400).',
            400,
        ];

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
        $requests = [];
        (new PostHogApiTransport('KEY', self::createPostHog($requests)))->send($email);

        return json_decode(end($requests)['options']['body'], true);
    }

    /**
     * Answers like PostHog: an upload slot for each file it does not have, the file storage, and the send.
     *
     * @param string[] $present the SHA-256 of the files PostHog already has
     */
    private static function createPostHog(array &$requests, array $present = []): MockHttpClient
    {
        return new MockHttpClient(static function (string $method, string $url, array $options) use (&$requests, $present): ResponseInterface {
            $requests[] = ['method' => $method, 'url' => $url, 'options' => $options];

            if (str_ends_with($url, '/messaging/attachments/uploads/')) {
                return new JsonMockResponse(['files' => array_map(static fn (array $file): array => \in_array($file['sha256'], $present, true)
                    ? ['sha256' => $file['sha256'], 'status' => 'present']
                    : ['sha256' => $file['sha256'], 'status' => 'upload', 'url' => 'https://storage.example.com/'.$file['sha256'], 'method' => 'PUT', 'headers' => ['x-amz-checksum-sha256' => $file['sha256']], 'expires_at' => '2026-11-13T12:00:00Z'],
                    json_decode($options['body'], true)['files'])]);
            }

            if (str_starts_with($url, 'https://storage.example.com/')) {
                return new MockResponse('', ['http_code' => 200]);
            }

            return new JsonMockResponse(['message_id' => 'msg-1234', 'replayed' => false], ['http_code' => 202]);
        });
    }

    /**
     * @param array<string, string> $headers text headers added in this order
     */
    private static function createEmail(array $headers = []): Email
    {
        $email = (new Email())
            ->from(new Address('shop@example.com', 'Shop'))
            ->to(new Address('ana@example.com', 'Ana'))
            ->subject('Your order shipped')
            ->html('<p>On its way.</p>')
            ->text('On its way.');

        foreach ($headers as $name => $value) {
            $email->getHeaders()->addTextHeader($name, $value);
        }

        return $email;
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
