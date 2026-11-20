<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Tests\Functional;

use App\AdminApi\AdminApiTokens;
use App\AdminApi\ApiScope;
use App\Entity\AdminApiToken;
use App\Entity\ServicePage;
use App\Entity\User;

/**
 * Повідомлення після дії (flash) і власний заголовок сторінки EasyAdmin друкує як HTML, тому текст, який вписала інша
 * людина (назва токена, заголовок сторінки, назва сайту), у них має бути екранований там, де складається рядок:
 *  - модератор створив токен → у повідомленні назва показана текстом;
 *  - адміністратор відкликав токен модератора → те саме;
 *  - видалення сторінки з дочірніми → у відмові заголовок сторінки показаний текстом;
 *  - «Налаштування сайту «…»» → назва сайту в заголовку показана текстом.
 */
final class AdminFlashAndTitleEscapingTest extends SeoTestCase
{
    private const PAYLOAD = '<img src=x onerror=alert(1)>';

    public function testIssuedTokenNameInFlashIsText(): void
    {
        $this->login($this->adminHost, $this->moderatorEmail, $this->password);
        $issueUrl = 'https://' . $this->adminHost . '/admin/admin-api-token/issue';
        $form = $this->http('GET', $issueUrl);
        self::assertSame(200, $form->getStatusCode());
        $fields = self::formFields((string) $form->getContent(), 'new_api_token');
        $fields['new_api_token[name]'] = 'Скрипт ' . $this->s . ' ' . self::PAYLOAD;
        $fields['new_api_token[scopes][0]'] = ApiScope::ContentRead->value;
        $fields['new_api_token[days]'] = '1';
        $response = $this->http('POST', $issueUrl, self::nest($fields));
        self::assertTrue($response->isRedirect(), 'токен створено: ' . $response->getStatusCode());
        self::assertSame('1', (string) $this->db()->fetchOne('SELECT COUNT(*) FROM admin_api_token WHERE name = ?', ['Скрипт ' . $this->s . ' ' . self::PAYLOAD]), 'назва збережена як є');

        $flash = $this->flash((string) $this->http('GET', (string) $response->headers->get('Location'))->getContent());
        self::assertStringContainsString('створено', $flash->textContent);
        $this->assertShownAsText($flash, 'Скрипт ' . $this->s);
    }

    public function testRevokedTokenNameInFlashIsText(): void
    {
        $moderator = $this->em()->getRepository(User::class)->findOneBy(['email' => $this->moderatorEmail]);
        self::assertInstanceOf(User::class, $moderator);
        $token = static::getContainer()->get(AdminApiTokens::class)
            ->issue($moderator, 'Агент ' . $this->s . ' ' . self::PAYLOAD, [ApiScope::ContentRead], 1, $moderator)->token;
        $tokenId = (int) $token->getId();

        $this->login($this->adminHost, $this->adminEmail, $this->password);
        $confirm = (string) $this->http('GET', 'https://' . $this->adminHost . '/admin/admin-api-token/revoke?entityId=' . $tokenId)->getContent();
        self::assertSame(1, preg_match('/name="token" value="([^"]+)"/', $confirm, $m), 'сторінка підтвердження з CSRF');
        $response = $this->http('POST', 'https://' . $this->adminHost . '/admin/admin-api-token/revoke', ['id' => $tokenId, 'token' => html_entity_decode($m[1])]);
        self::assertTrue($response->isRedirect(), 'відкликано: ' . $response->getStatusCode());
        $this->em()->clear();
        self::assertTrue($this->em()->find(AdminApiToken::class, $tokenId)?->isRevoked(), 'токен відкликано');

        $flash = $this->flash((string) $this->http('GET', (string) $response->headers->get('Location'))->getContent());
        self::assertStringContainsString('відкликано', $flash->textContent);
        $this->assertShownAsText($flash, 'Агент ' . $this->s);
    }

    public function testPageTitleInDeleteRefusalFlashIsText(): void
    {
        $em = $this->em();
        $parent = (new ServicePage())->setSite($this->site())->setSlugUk('parent-' . $this->s)->setTitleUk('Розділ ' . $this->s . ' ' . self::PAYLOAD);
        $em->persist($parent);
        $em->persist((new ServicePage())->setSite($this->site())->setSlugUk('child-' . $this->s)->setTitleUk('Дочірня')->setParent($parent));
        $em->flush();
        $parentId = (int) $parent->getId();
        $em->clear();

        $this->login($this->adminHost, $this->moderatorEmail, $this->password);
        $index = (string) $this->http('GET', 'https://' . $this->adminHost . '/admin/service-page')->getContent();
        self::assertSame(1, preg_match('/<form[^>]*id="action-confirmation-form"[^>]*>.*?name="token" value="([^"]+)"/s', $index, $m), 'форма видалення з CSRF у списку');
        $response = $this->http('POST', 'https://' . $this->adminHost . '/admin/service-page/' . $parentId . '/delete', ['token' => html_entity_decode($m[1])]);
        self::assertTrue($response->isRedirect(), 'видалення → редирект: ' . $response->getStatusCode());
        self::assertSame('1', (string) $this->db()->fetchOne('SELECT COUNT(*) FROM service_page WHERE id = ?', [$parentId]), 'сторінку з дочірніми не видалено');

        $flash = $this->flash((string) $this->http('GET', (string) $response->headers->get('Location'))->getContent());
        self::assertStringContainsString('не видалено', $flash->textContent);
        $this->assertShownAsText($flash, 'Розділ ' . $this->s);
    }

    public function testSiteNameInSettingsPageTitleIsText(): void
    {
        $this->db()->executeStatement('UPDATE service_site SET name = ? WHERE id = ?', ['Сайт ' . $this->s . ' ' . self::PAYLOAD, $this->siteId]);

        $this->login($this->adminHost, $this->adminEmail, $this->password);
        $response = $this->http('GET', 'https://' . $this->adminHost . '/admin/site-setting?site=' . $this->siteId);
        self::assertSame(200, $response->getStatusCode());
        $html = (string) $response->getContent();
        $heading = self::dom($html)->getElementsByTagName('h1')->item(0);
        self::assertInstanceOf(\DOMElement::class, $heading, 'на сторінці є заголовок');
        self::assertStringContainsString('Налаштування сайту', $heading->textContent);
        $this->assertShownAsText($heading, 'Сайт ' . $this->s);
        self::assertStringNotContainsString(self::PAYLOAD, $html, 'назва сайту потрапила в сторінку як HTML');
    }

    /** Смуга повідомлень EasyAdmin на сторінці. */
    private function flash(string $html): \DOMElement
    {
        $node = self::dom($html)->getElementById('flash-messages');
        self::assertInstanceOf(\DOMElement::class, $node, 'на сторінці є смуга повідомлень');

        return $node;
    }

    private static function dom(string $html): \DOMDocument
    {
        $doc = new \DOMDocument();
        @$doc->loadHTML('<?xml encoding="UTF-8">' . $html);

        return $doc;
    }

    /** Чужий текст у вузлі — саме текст: тегів із нього нема, видно його так, як ввели, екранування одне. */
    private function assertShownAsText(\DOMElement $node, string $text): void
    {
        self::assertSame(0, $node->getElementsByTagName('img')->length, 'чужий текст потрапив у розмітку: ' . $node->ownerDocument?->saveHTML($node));
        self::assertStringContainsString($text . ' ' . self::PAYLOAD, $node->textContent, 'текст показано так, як його ввели');
        self::assertStringNotContainsString('&lt;', $node->textContent, 'подвійне екранування');
    }
}
