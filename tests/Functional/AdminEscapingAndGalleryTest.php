<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\ServiceLead;
use App\Entity\ServicePage;
use App\Entity\ServicePageImage;

/**
 * Безпека адмінки після оновлення EasyAdmin до 5.10.2 (20.11.2026, попередження від 17.11.2026):
 *  - ім'я з публічної форми заявки не виконується як HTML на картці заявки (заголовок сторінки бере __toString() заявки);
 *  - вкладена CRUD-форма галереї сторінки (CollectionField::useEntryCrudForm) лишається робочою для модератора:
 *    5.10.2 почав застосовувати до вкладених форм setEntityPermission() їхнього контролера (GHSA-7435-rgcw-xrhf).
 */
final class AdminEscapingAndGalleryTest extends SeoTestCase
{
    private const PAYLOAD = '<img src=x onerror=alert(1)>';

    public function testLeadNameFromPublicFormIsNotRenderedAsHtmlOnAdminCard(): void
    {
        $page = (string) $this->http('GET', 'https://' . $this->apex . '/contacts/')->getContent();
        self::assertSame(1, preg_match('/name="_token" value="([^"]+)"/', $page, $m));
        $r = $this->http('POST', 'https://' . $this->apex . '/lead', [
            '_token' => html_entity_decode($m[1]),
            'phone' => '+380 67 ' . random_int(100, 999) . ' 33 44',
            'name' => 'Олена ' . self::PAYLOAD,
        ], ['REMOTE_ADDR' => '10.9.' . random_int(0, 255) . '.' . random_int(1, 254), 'HTTP_REFERER' => 'https://' . $this->apex . '/contacts/']);
        self::assertSame('/thank-you/?src=form', $r->headers->get('Location'), 'заявку прийнято');

        $this->em()->clear();
        $lead = $this->em()->getRepository(ServiceLead::class)->findOneBy(['city' => $this->apexCityId], ['id' => 'DESC']);
        self::assertInstanceOf(ServiceLead::class, $lead);
        self::assertStringContainsString(self::PAYLOAD, (string) $lead->getName(), 'форма зберігає імʼя як є — екранувати мусить адмінка');

        $this->login($this->adminHost, $this->moderatorEmail, $this->password);
        foreach (['/admin/service-lead', '/admin/service-lead/' . $lead->getId()] as $path) {
            $response = $this->http('GET', 'https://' . $this->adminHost . $path);
            self::assertSame(200, $response->getStatusCode(), $path);
            $html = (string) $response->getContent();
            self::assertStringNotContainsString(self::PAYLOAD, $html, $path . ': імʼя з форми потрапило в сторінку як HTML');
            self::assertStringContainsString('Олена &lt;img src=x onerror=alert(1)&gt;', $html, $path . ': імʼя показано текстом');
        }
    }

    public function testModeratorEditsGalleryCaptionThroughNestedCrudForm(): void
    {
        $em = $this->em();
        $image = (new ServicePageImage())->setImageName('scan-' . $this->s . '.jpg')->setCaptionUk('Старий підпис')->setPosition(1);
        $page = (new ServicePage())->setSite($this->site())->setSlugUk('gallery-' . $this->s)->setTitleUk('Ліцензії')->addImage($image);
        $em->persist($page);
        $em->flush();
        $pageId = (int) $page->getId();
        $imageId = (int) $image->getId();
        $em->clear();

        $this->login($this->adminHost, $this->moderatorEmail, $this->password);
        $editUrl = 'https://' . $this->adminHost . '/admin/service-page/' . $pageId . '/edit';
        $form = (string) $this->http('GET', $editUrl)->getContent();
        self::assertStringContainsString('name="ServicePage[images][0][captionUk]"', $form, 'рядок галереї — вкладена форма зі своїми полями');
        self::assertStringContainsString('value="Старий підпис"', $form);
        self::assertStringContainsString('field-collection-add-button', $form, 'модератор може додати рядок галереї');
        self::assertStringContainsString('field-collection-delete-button', $form, 'і видалити');

        $fields = array_filter(self::formFields($form, 'ServicePage'), static fn (string $name): bool => !str_ends_with($name, '[file]'), \ARRAY_FILTER_USE_KEY);
        $fields['ServicePage[site]'] = (string) $this->siteId;
        $fields['ServicePage[images][0][captionUk]'] = 'Ліцензія МОЗ';
        $response = $this->http('POST', $editUrl, self::nest($fields) + ['ea' => ['editForm' => ['btn' => 'saveAndReturn']]]);
        self::assertTrue($response->isRedirect(), 'збережено: ' . $response->getStatusCode());

        self::assertSame('Ліцензія МОЗ', $this->db()->fetchOne('SELECT caption_uk FROM service_page_image WHERE id = ?', [$imageId]), 'підпис з вкладеної форми записано');
        self::assertSame('1', (string) $this->db()->fetchOne('SELECT COUNT(*) FROM service_page_image WHERE page_id = ?', [$pageId]), 'рядок галереї не загубився');
    }
}
