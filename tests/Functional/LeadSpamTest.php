<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\ServiceCity;
use App\Entity\ServiceItem;
use App\Entity\ServiceLead;
use App\Entity\ServicePrice;
use App\ServiceSite\LeadSpamGuard;
use App\Tests\Support\LeadSpamKernel;
use App\Tests\Support\RecordingCrm;
use Symfony\Component\HttpFoundation\Response;

/**
 * Захист форм заявок (app.lead_spam, LeadSpamKernel: коди країни 380/0, ліміт 3 з IP за годину, дубль 60 с, CRM —
 * RecordingCrm): справжня заявка з форми й з калькулятора проходить як раніше (302 на подяку, запис без spam_reason, лист,
 * CRM); спам — подяка без конверсії (src=ok), запис зі spam_reason, ні листа, ні CRM; too-fast — «спробуйте ще раз»; ліміт
 * спам-записів з IP; дубль номера не враховує спам; app:crm:sync спам не бере; «Не спам» в адмінці відправляє заявку.
 */
final class LeadSpamTest extends SeoTestCase
{
    protected static function getKernelClass(): string
    {
        return LeadSpamKernel::class;
    }

    protected function setUp(): void
    {
        parent::setUp();
        RecordingCrm::$pushed = [];
        $em = $this->em();
        $em->find(ServiceCity::class, $this->apexCityId)?->setLeadEmails('leads@example.test');
        $em->flush();
        $em->clear();
    }

    public function testFormsCarryProtectionFields(): void
    {
        $html = (string) $this->http('GET', 'https://' . $this->apex . '/contacts/')->getContent();
        self::assertStringContainsString('name="website"', $html, 'honeypot');
        self::assertMatchesRegularExpression('/name="_ft" value="\d{10}\.[0-9a-f]{20}"/', $html, 'підписана мітка часу');
        self::assertStringNotContainsString('cf-turnstile', $html, 'без ключів Turnstile нічого не рендериться');
        self::assertStringNotContainsString('challenges.cloudflare.com', $html);
    }

    public function testHumanLeadGoesToMailAndCrm(): void
    {
        $r = $this->post(['name' => 'Олена', 'message' => 'Потрібне прибирання після ремонту, 60 м², деталі за телефоном.', 'service' => 'Прибирання'], secondsAgo: 20);
        self::assertSame(302, $r->getStatusCode(), (string) $r->getContent());
        self::assertSame('/thank-you/?src=form', $r->headers->get('Location'));
        $lead = $this->lastLead();
        self::assertNull($lead->getSpamReason());
        self::assertSame('Олена', $lead->getName());
        self::assertSame([$lead->getId()], RecordingCrm::$pushed, 'CRM-конектор викликано');
        self::assertSame('rec-' . $lead->getId(), $lead->getCrmExternalId());
        self::assertEmailCount(1);
        $mail = self::getMailerMessage();
        self::assertNotNull($mail);
        self::assertEmailAddressContains($mail, 'To', 'leads@example.test');
        self::assertEmailTextBodyContains($mail, 'Олена');

        $thanks = (string) $this->http('GET', 'https://' . $this->apex . '/thank-you/?src=form')->getContent();
        self::assertStringContainsString("event: \"generate_lead\"", $thanks, 'конверсія для справжньої заявки');
    }

    public function testSpamIsStoredSilentlyWithoutMailCrmAndConversion(): void
    {
        $before = $this->leadCount();
        $message = 'Hi, we help cleaning companies get more clients, no cost to try. Take a look: https://leads-boost.example/demo';
        $r = $this->post(['name' => 'Eric Jones', 'message' => $message], secondsAgo: 40, ip: $this->ip());
        self::assertSame(302, $r->getStatusCode());
        self::assertSame('/thank-you/?src=ok', $r->headers->get('Location'), 'подяка без конверсії');
        self::assertSame($before + 1, $this->leadCount(), 'спам збережено');
        $lead = $this->lastLead();
        self::assertSame('url:message', $lead->getSpamReason());
        self::assertSame('Eric Jones', $lead->getName());
        self::assertSame($message, $lead->getMessage());
        self::assertSame('/contacts/', $lead->getPageUrl());
        self::assertNull($lead->getCrmExternalId());
        self::assertSame([], RecordingCrm::$pushed, 'конектор не викликано');
        self::assertEmailCount(0, 'пошта не надіслана');

        $thanks = (string) $this->http('GET', 'https://' . $this->apex . '/thank-you/?src=ok')->getContent();
        self::assertStringNotContainsString('generate_lead', $thanks, 'жодної події конверсії');
        self::assertStringNotContainsString('lead_type: ', $thanks, 'блоку подій dataLayer нема (lead_type лишається лише в самій формі)');
        self::assertStringNotContainsString("svTags.run('gtag'", $thanks);
        self::assertStringContainsString('Заявку прийнято', $thanks, 'а сторінка — та сама подяка');

        // honeypot і підроблена мітка — теж записи зі spam_reason
        $this->post(['website' => 'http://x', 'name' => 'Bot'], secondsAgo: 30, ip: $this->ip());
        self::assertSame('honeypot', $this->lastLead()->getSpamReason());
        $r = $this->post(['_ft' => time() . '.deadbeefdeadbeefdead'], ip: $this->ip());
        self::assertSame('/thank-you/?src=ok', $r->headers->get('Location'));
        self::assertSame('time-token', $this->lastLead()->getSpamReason());
        // чужа країна (phone_country_codes 380/0) — спам
        $r = $this->post(['phone' => '+1 212 555 0100'], secondsAgo: 30, ip: $this->ip());
        self::assertSame('/thank-you/?src=ok', $r->headers->get('Location'));
        self::assertSame('phone:country', $this->lastLead()->getSpamReason());
        self::assertSame([], RecordingCrm::$pushed);
        self::assertEmailCount(0);

        // мова сторінки: той самий латинський текст з /ru/ — спам, бо ru теж кирилична
        $r = $this->post(['message' => 'Hello, I would like to offer you excellent services for your business today.'], secondsAgo: 30, ip: $this->ip(), referer: '/ru/contacts/');
        self::assertSame('/ru/thank-you/?src=ok', $r->headers->get('Location'));
        self::assertSame('latin-only', $this->lastLead()->getSpamReason());
    }

    public function testTooFastAsksToRetryAndRetryIsNotADuplicate(): void
    {
        $phone = '+380 67 ' . random_int(100, 999) . ' 11 22';
        $ip = $this->ip();
        $before = $this->leadCount();
        $r = $this->post(['phone' => $phone, 'name' => 'Швидкий'], secondsAgo: 1, ip: $ip);
        self::assertSame(302, $r->getStatusCode());
        self::assertSame('/contacts/', $r->headers->get('Location'), 'назад на форму, не на подяку');
        self::assertStringContainsString('спробуйте ще раз', $this->flashes());
        self::assertSame($before + 1, $this->leadCount(), 'спроба збережена як спам');
        self::assertStringStartsWith('too-fast', (string) $this->lastLead()->getSpamReason());

        // повтор через кілька секунд — звичайна заявка (спам-запис дублем не вважається)
        $r = $this->post(['phone' => $phone, 'name' => 'Швидкий'], secondsAgo: 6, ip: $ip);
        self::assertSame('/thank-you/?src=form', $r->headers->get('Location'));
        $lead = $this->lastLead();
        self::assertNull($lead->getSpamReason());
        self::assertSame([$lead->getId()], RecordingCrm::$pushed);

        // а ще один той самий номер у вікні dedup_seconds — дубль без другої заявки
        $r = $this->post(['phone' => $phone], secondsAgo: 6, ip: $ip);
        self::assertSame('/thank-you/?src=dup', $r->headers->get('Location'));
        self::assertSame($before + 2, $this->leadCount());

        // короткий номер — «Перевірте номер телефону», запис phone:short
        $r = $this->post(['phone' => '067 12 34'], secondsAgo: 10, ip: $ip);
        self::assertSame('/contacts/', $r->headers->get('Location'));
        self::assertStringContainsString('Перевірте номер телефону', $this->flashes());
        self::assertSame('phone:short', $this->lastLead()->getSpamReason());
    }

    public function testSpamRecordsPerIpAreCapped(): void
    {
        $ip = $this->ip();
        $before = $this->leadCount();
        for ($i = 0; $i < 5; ++$i) {
            $r = $this->post(['website' => 'bot'], secondsAgo: 30, ip: $ip);
            self::assertSame('/thank-you/?src=ok', $r->headers->get('Location'), 'відповідь однакова й після ліміту');
        }
        self::assertSame($before + LeadSpamKernel::SPAM['ip_per_hour'], $this->leadCount(), 'далі лише лог');
        // ліміт справжніх заявок з IP — той самий ip_per_hour, окремий лічильник
        $ip2 = $this->ip();
        for ($i = 0; $i < LeadSpamKernel::SPAM['ip_per_hour']; ++$i) {
            self::assertSame('/thank-you/?src=form', $this->post([], secondsAgo: 30, ip: $ip2)->headers->get('Location'));
        }
        $r = $this->post([], secondsAgo: 30, ip: $ip2);
        self::assertSame('/contacts/', $r->headers->get('Location'));
        self::assertStringContainsString('Забагато заявок', $this->flashes());
    }

    public function testCalculatorLeadAndSpam(): void
    {
        $em = $this->em();
        $row = (new ServicePrice())
            ->setItem($em->getReference(ServiceItem::class, $this->itemId))
            ->setCity($em->getReference(ServiceCity::class, $this->apexCityId))
            ->setNameUk('Миття вікон')->setPrice('500')->setUnitUk('м²')->setMaxQty(100)->setInCalculator(true)->setActive(true);
        $em->persist($row);
        $em->flush();
        $priceId = (int) $row->getId();
        $em->clear();

        $page = (string) $this->http('GET', 'https://' . $this->apex . '/calculator/')->getContent();
        self::assertStringContainsString('name="_ft"', $page);
        self::assertSame(1, preg_match('/name="_token" value="([^"]+)"/', $page, $m));
        $base = ['_token' => html_entity_decode($m[1]), 'loc' => 'uk', 'qty' => [$priceId => '3']];
        $ip = $this->ip();

        $r = $this->http('POST', 'https://' . $this->apex . '/calculator/lead', $base + ['_ft' => $this->ft(30), 'phone' => '+380 67 ' . random_int(100, 999) . ' 22 33', 'name' => 'Тест'], ['REMOTE_ADDR' => $ip]);
        self::assertSame(302, $r->getStatusCode(), (string) $r->getContent());
        self::assertSame('/thank-you/?src=calc', $r->headers->get('Location'));
        $lead = $this->lastLead();
        self::assertNull($lead->getSpamReason());
        self::assertStringContainsString('Миття вікон × 3 м² = 1500 грн', (string) $lead->getMessage());
        self::assertSame([$lead->getId()], RecordingCrm::$pushed);
        self::assertEmailCount(1);

        $r = $this->http('POST', 'https://' . $this->apex . '/calculator/lead', $base + ['_ft' => $this->ft(30), 'phone' => '+380 67 ' . random_int(100, 999) . ' 22 33', 'name' => "Test'||(SELECT 1 FROM (SELECT SLEEP(5))a)-- -"], ['REMOTE_ADDR' => $ip]);
        self::assertSame('/thank-you/?src=ok', $r->headers->get('Location'));
        $spam = $this->lastLead();
        self::assertSame('junk:name', $spam->getSpamReason());
        self::assertNull($spam->getMessage(), 'розрахунок для спаму не робиться');
        self::assertSame('/calculator/', $spam->getPageUrl());
        self::assertCount(1, RecordingCrm::$pushed, 'CRM не викликано вдруге');
    }

    public function testNotSpamInAdminSendsLeadAndCrmSyncSkipsSpam(): void
    {
        $this->post(['name' => 'Хибний спам', 'message' => 'We need a quote for weekly office cleaning, 200 sqm, Kyiv center. Please call.'], secondsAgo: 30, ip: $this->ip());
        $lead = $this->lastLead();
        self::assertSame('latin-only', $lead->getSpamReason());
        self::assertEmailCount(0);

        // крон добору в CRM спам не бачить (crm_external_id порожній, але spam_reason задано)
        $pending = $this->db()->fetchOne('SELECT COUNT(*) FROM service_lead WHERE id = ? AND crm_external_id IS NULL AND spam_reason IS NULL', [$lead->getId()]);
        self::assertSame(0, (int) $pending);

        $this->login($this->adminHost, $this->moderatorEmail, $this->password);
        $index = (string) $this->http('GET', 'https://' . $this->adminHost . '/admin/service-lead?filters[city][comparison]=%3D&filters[city][value]=' . $this->apexCityId)->getContent();
        self::assertStringContainsString('>спам</span>', $index, 'бейдж у списку');
        self::assertStringContainsString('latin-only', $index);
        self::assertStringContainsString('Не спам', $index);
        self::assertSame(1, preg_match('~href="([^"]*/admin/service-lead/' . $lead->getId() . '/not-spam\?[^"]*)"~', $index, $m), 'посилання «Не спам» для спам-запису');
        $url = html_entity_decode($m[1]);

        // без токена — відмова
        $r = $this->http('GET', preg_replace('/_t=[^&]+/', '_t=bad', $url) ?? $url);
        self::assertTrue($r->isRedirect());
        $this->em()->clear();
        self::assertSame('latin-only', $this->em()->find(ServiceLead::class, $lead->getId())?->getSpamReason());

        $r = $this->http('GET', $url);
        self::assertTrue($r->isRedirect(), (string) $r->getContent());
        $this->em()->clear();
        $fixed = $this->em()->find(ServiceLead::class, $lead->getId());
        self::assertNotNull($fixed);
        self::assertNull($fixed->getSpamReason());
        self::assertSame([$lead->getId()], RecordingCrm::$pushed, 'відправлено в CRM тим самим шляхом');
        self::assertSame('rec-' . $lead->getId(), $fixed->getCrmExternalId());
        self::assertEmailCount(1);
        $mail = self::getMailerMessage();
        self::assertNotNull($mail);
        self::assertEmailAddressContains($mail, 'To', 'leads@example.test');
        self::assertEmailTextBodyContains($mail, 'Хибний спам');

        $index = (string) $this->http('GET', 'https://' . $this->adminHost . '/admin/service-lead?filters[spamReason][comparison]=null')->getContent();
        self::assertStringContainsString('більше не спам', $index, 'флеш після дії');
        self::assertStringContainsString('filters[spamReason]', $index, 'фільтр «Спам»');
    }

    /**
     * POST /lead з /contacts/: токен CSRF зі сторінки, мітка _ft на secondsAgo секунд раніше (null — без поля), телефон
     * український, якщо не заданий.
     *
     * @param array<string, string> $fields
     */
    private function post(array $fields, ?int $secondsAgo = null, ?string $ip = null, string $referer = '/contacts/'): Response
    {
        $page = (string) $this->http('GET', 'https://' . $this->apex . $referer)->getContent();
        self::assertSame(1, preg_match('/name="_token" value="([^"]+)"/', $page, $m));
        $post = ['_token' => html_entity_decode($m[1])] + $fields;
        if (!\array_key_exists('phone', $fields)) {
            $post['phone'] = '+380 67 ' . random_int(100, 999) . ' 33 44';
        }
        if ($secondsAgo !== null && !isset($fields['_ft'])) {
            $post['_ft'] = $this->ft($secondsAgo);
        }

        return $this->http('POST', 'https://' . $this->apex . '/lead', $post, [
            'REMOTE_ADDR' => $ip ?? $this->ip(),
            'HTTP_REFERER' => 'https://' . $this->apex . $referer,
        ]);
    }

    /** Своя IP на кожен виклик: лічильники лімітів живуть у кеші й переживають прогони тестів. */
    private function ip(): string
    {
        return '10.' . random_int(40, 99) . '.' . random_int(0, 255) . '.' . random_int(1, 254);
    }

    private function ft(int $secondsAgo): string
    {
        return static::getContainer()->get(LeadSpamGuard::class)->formToken(time() - $secondsAgo);
    }

    private function flashes(): string
    {
        return (string) $this->http('GET', 'https://' . $this->apex . '/contacts/')->getContent();
    }

    private function lastLead(): ServiceLead
    {
        $this->em()->clear();
        $lead = $this->em()->getRepository(ServiceLead::class)->findOneBy(['city' => $this->apexCityId], ['id' => 'DESC']);
        self::assertInstanceOf(ServiceLead::class, $lead);

        return $lead;
    }

    private function leadCount(): int
    {
        return (int) $this->db()->fetchOne('SELECT COUNT(*) FROM service_lead WHERE city_id = ?', [$this->apexCityId]);
    }
}
