<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\ServicePage;
use App\Entity\ServicePageImage;
use App\Tests\Support\TrilingualKernel;

/**
 * Англійський підпис зображення галереї сторінки (caption_en): поле у вкладеній формі галереї на розгортанні з en,
 * збереження з адмінки; підпис за мовою з фолбеком на український.
 */
final class GalleryCaptionEnAdminTest extends SeoTestCase
{
    protected static function getKernelClass(): string
    {
        return TrilingualKernel::class;
    }

    public function testModeratorSavesEnglishCaptionInGalleryForm(): void
    {
        $em = $this->em();
        $image = (new ServicePageImage())->setImageName('scan-' . $this->s . '.jpg')->setCaptionUk('Вид спереду')->setPosition(1);
        $page = (new ServicePage())->setSite($this->site())->setSlugUk('gallery-en-' . $this->s)->setTitleUk('Будинок')->addImage($image);
        $em->persist($page);
        $em->flush();
        $pageId = (int) $page->getId();
        $imageId = (int) $image->getId();
        $em->clear();

        $this->login($this->adminHost, $this->moderatorEmail, $this->password);
        $editUrl = 'https://' . $this->adminHost . '/admin/service-page/' . $pageId . '/edit';
        $form = (string) $this->http('GET', $editUrl)->getContent();
        self::assertStringContainsString('name="ServicePage[images][0][captionEn]"', $form, 'на розгортанні з en у рядку галереї є англійський підпис');

        $fields = array_filter(self::formFields($form, 'ServicePage'), static fn (string $name): bool => !str_ends_with($name, '[file]'), \ARRAY_FILTER_USE_KEY);
        $fields['ServicePage[site]'] = (string) $this->siteId;
        $fields['ServicePage[images][0][captionEn]'] = 'Front view';
        $response = $this->http('POST', $editUrl, self::nest($fields) + ['ea' => ['editForm' => ['btn' => 'saveAndReturn']]]);
        self::assertTrue($response->isRedirect(), 'збережено: ' . $response->getStatusCode());

        self::assertSame(
            ['caption_uk' => 'Вид спереду', 'caption_en' => 'Front view'],
            $this->db()->fetchAssociative('SELECT caption_uk, caption_en FROM service_page_image WHERE id = ?', [$imageId]),
        );
        $saved = $this->em()->find(ServicePageImage::class, $imageId);
        self::assertSame(['Вид спереду', 'Вид спереду', 'Front view'], [$saved?->caption('uk'), $saved?->caption('ru'), $saved?->caption('en')]);
    }

    public function testCaptionFallsBackToUkrainianWhenEnglishIsEmpty(): void
    {
        $image = (new ServicePageImage())->setCaptionUk('Ліцензія МОЗ')->setCaptionEn('  ');
        self::assertSame('Ліцензія МОЗ', $image->caption('en'));
        self::assertNull((new ServicePageImage())->caption('en'));
    }
}
