<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Entity\ServicePartner;
use App\ServiceSite\Partners\HostResolver;
use App\ServiceSite\Partners\PartnerDeliveryException;
use App\ServiceSite\Partners\WebhookSender;
use App\Settings\SecretBox;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class WebhookSenderTest extends TestCase
{
    public function testJsonHeadersSignatureAndPinnedAddress(): void
    {
        $box = new SecretBox(SecretBox::generateKey());
        $partner = (new ServicePartner())->setWebhookUrl('https://partner.example/leads')
            ->setWebhookSecret($box->encrypt('test-secret'));
        $payload = ['event' => 'lead.assigned', 'assignment_id' => 42, 'site' => ['name' => 'Сайт'], 'lead' => ['page_url' => 'https://site.example/']];
        $calls = 0;
        $client = new MockHttpClient(function (string $method, string $url, array $options) use ($payload, &$calls): MockResponse {
            ++$calls;
            self::assertSame('POST', $method);
            self::assertSame('https://partner.example/leads', $url);
            self::assertSame(['partner.example' => '93.184.216.34'], $options['resolve']);
            self::assertSame(0, $options['max_redirects']);
            self::assertEquals(5, $options['timeout']);
            self::assertEquals(5, $options['max_duration']);
            $headers = self::headers($options);
            $body = json_encode($payload, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR);
            self::assertSame($body, $options['body']);
            self::assertSame($payload, json_decode($options['body'], true, flags: \JSON_THROW_ON_ERROR));
            self::assertSame('application/json', $headers['content-type']);
            self::assertSame('Сайт lead webhook', $headers['user-agent']);
            self::assertSame('lead.assigned', $headers['x-lead-event']);
            self::assertSame('42', $headers['x-lead-delivery']);
            self::assertMatchesRegularExpression('/^\d+$/', $headers['x-lead-timestamp']);
            self::assertLessThanOrEqual(2, abs(time() - (int) $headers['x-lead-timestamp']));
            self::assertSame('sha256=' . hash_hmac('sha256', $headers['x-lead-timestamp'] . '.' . $body, 'test-secret'), $headers['x-lead-signature']);

            return new MockResponse('', ['http_code' => 204]);
        });
        (new WebhookSender($client, $this->resolver(['93.184.216.34']), $box))->send($partner, $payload);
        self::assertSame(1, $calls);
    }

    /** @return iterable<string, array{string, list<string>}> */
    public static function unsafeUrls(): iterable
    {
        yield 'http' => ['http://partner.example/', ['93.184.216.34']];
        yield 'credentials' => ['https://user:pass@partner.example/', ['93.184.216.34']];
        yield 'loopback' => ['https://partner.example/', ['127.0.0.1']];
        yield 'private' => ['https://partner.example/', ['10.1.2.3']];
        yield 'link local' => ['https://partner.example/', ['169.254.169.254']];
        yield 'mixed records' => ['https://partner.example/', ['93.184.216.34', '10.1.2.3']];
        yield 'ipv6 loopback' => ['https://[::1]/', ['::1']];
        yield 'unresolved' => ['https://partner.example/', []];
    }

    #[DataProvider('unsafeUrls')]
    public function testUnsafeDestinationDoesNotMakeRequest(string $url, array $ips): void
    {
        $calls = 0;
        $client = new MockHttpClient(static function () use (&$calls): MockResponse {
            ++$calls;

            return new MockResponse();
        });
        try {
            (new WebhookSender($client, $this->resolver($ips), new SecretBox()))->send((new ServicePartner())->setWebhookUrl($url), ['event' => 'ping']);
            self::fail('Очікувалась відмова до HTTP-запиту');
        } catch (PartnerDeliveryException) {
            self::assertSame(0, $calls);
        }
    }

    /** @return iterable<array{int}> */
    public static function failureStatuses(): iterable
    {
        yield [500];
        yield [302];
        yield [307];
    }

    #[DataProvider('failureStatuses')]
    public function testNon2xxIsAnErrorAndRedirectIsNotFollowed(int $status): void
    {
        $calls = 0;
        $client = new MockHttpClient(static function (string $method, string $url, array $options) use ($status, &$calls): MockResponse {
            ++$calls;
            self::assertSame(0, $options['max_redirects']);

            return new MockResponse('', ['http_code' => $status, 'response_headers' => ['Location: https://other.example/']]);
        });
        try {
            (new WebhookSender($client, $this->resolver(['93.184.216.34']), new SecretBox()))
                ->send((new ServicePartner())->setWebhookUrl('https://partner.example/'), ['event' => 'ping']);
            self::fail('Очікувалась помилка HTTP');
        } catch (PartnerDeliveryException $e) {
            self::assertSame('HTTP ' . $status, $e->getMessage());
        }
        self::assertSame(1, $calls);
    }

    public function testUnsignedPingHasNoSignature(): void
    {
        $calls = 0;
        $client = new MockHttpClient(static function (string $method, string $url, array $options) use (&$calls): MockResponse {
            ++$calls;
            $headers = self::headers($options);
            self::assertArrayNotHasKey('x-lead-signature', $headers);
            self::assertSame('ping', $headers['x-lead-event']);
            self::assertSame('ping', $headers['x-lead-delivery']);

            return new MockResponse();
        });
        (new WebhookSender($client, $this->resolver(['93.184.216.34']), new SecretBox()))
            ->send((new ServicePartner())->setWebhookUrl('https://partner.example/'), ['event' => 'ping']);
        self::assertSame(1, $calls);
    }

    public function testTransportErrorIsLimitedTo500Characters(): void
    {
        $client = new MockHttpClient(static fn () => throw new TransportException(str_repeat('x', 600)));
        try {
            (new WebhookSender($client, $this->resolver(['93.184.216.34']), new SecretBox()))
                ->send((new ServicePartner())->setWebhookUrl('https://partner.example/'), ['event' => 'ping']);
            self::fail('Очікувалась помилка транспорту');
        } catch (PartnerDeliveryException $e) {
            self::assertSame(str_repeat('x', 500), $e->getMessage());
        }
    }

    private function resolver(array $ips): HostResolver
    {
        $resolver = $this->createStub(HostResolver::class);
        $resolver->method('ips')->willReturn($ips);

        return $resolver;
    }

    /** @return array<string, string> */
    public static function headers(array $options): array
    {
        $headers = [];
        foreach ($options['headers'] as $header) {
            [$name, $value] = explode(':', $header, 2);
            $headers[strtolower($name)] = trim($value);
        }

        return $headers;
    }
}
