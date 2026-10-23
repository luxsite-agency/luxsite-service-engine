<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\ServiceCategory;
use App\Entity\ServiceCity;
use App\Entity\ServiceDistrict;
use App\Entity\ServiceItem;
use App\Entity\ServicePost;
use App\Entity\ServiceSite;
use App\Entity\User;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Фікстура для тестів менеджера редиректів і журналу 404: сайт з двома містами (апекс і філія), категорія з двома
 * послугами (одна не надається у філії), район у кожному місті, опублікований пост, адмін і модератор.
 */
abstract class SeoTestCase extends HttpKernelTestCase
{
    protected string $s;
    protected string $slug;
    protected string $apex;
    protected string $branch;
    protected string $adminHost;
    protected int $siteId;
    protected int $apexCityId;
    protected int $branchCityId;
    protected int $itemId;
    protected int $hiddenItemId;
    protected int $categoryId;
    protected int $districtId;
    protected int $postId;
    protected string $adminEmail;
    protected string $moderatorEmail;
    protected string $password;

    protected function setUp(): void
    {
        $this->s = bin2hex(random_bytes(3));
        $this->slug = 'ci-seo-' . $this->s;
        $this->apex = $this->slug . '.example';
        $this->branch = 'branch.' . $this->apex;
        $this->adminHost = 'admin.' . $this->apex;
        $_SERVER['ADMIN_HOST'] = $_ENV['ADMIN_HOST'] = $this->adminHost;
        putenv('ADMIN_HOST=' . $this->adminHost);
        self::bootKernel();

        $em = $this->em();
        $site = (new ServiceSite())->setSlug($this->slug)->setName('CI SEO Site')->setApexDomain($this->apex);
        $em->persist($site);
        $apex = (new ServiceCity())->setSite($site)->setHost($this->apex)->setSlug('main')->setIsApex(true)->setNameNomUk('Головне');
        $branch = (new ServiceCity())->setSite($site)->setHost($this->branch)->setSlug('branch')->setNameNomUk('Філія');
        $em->persist($apex);
        $em->persist($branch);
        $category = (new ServiceCategory())->setSite($site)->setSlugUk('cat-' . $this->s)->setTitleUk('Категорія')->setActive(true);
        $em->persist($category);
        $item = (new ServiceItem())->setSite($site)->setCategory($category)->setSlugUk('item-' . $this->s)->setTitleUk('Послуга')->setActive(true);
        $hidden = (new ServiceItem())->setSite($site)->setCategory($category)->setSlugUk('hidden-' . $this->s)->setTitleUk('Лише апекс')->setActive(true);
        $hidden->addHiddenCity($branch);
        $em->persist($item);
        $em->persist($hidden);
        $district = (new ServiceDistrict())->setCity($apex)->setSlugUk('district-' . $this->s)->setNameUk('Район')->setActive(true);
        $em->persist($district);
        $em->persist((new ServiceDistrict())->setCity($branch)->setSlugUk('branch-district-' . $this->s)->setNameUk('Район філії')->setActive(true));
        $post = (new ServicePost())->setSite($site)->setSlugUk('post-' . $this->s)->setTitleUk('Пост')->setActive(true)->setPublishedAt(new \DateTimeImmutable('-1 day'));
        $em->persist($post);
        $this->password = bin2hex(random_bytes(8));
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $this->adminEmail = $this->slug . '-admin@example.test';
        $this->moderatorEmail = $this->slug . '-moderator@example.test';
        foreach ([$this->adminEmail => 'ROLE_ADMIN', $this->moderatorEmail => 'ROLE_MODERATOR'] as $email => $role) {
            $user = (new User())->setEmail($email)->setRoles([$role]);
            $user->setPassword($hasher->hashPassword($user, $this->password));
            $em->persist($user);
        }
        $em->flush();
        $this->siteId = (int) $site->getId();
        $this->apexCityId = (int) $apex->getId();
        $this->branchCityId = (int) $branch->getId();
        $this->itemId = (int) $item->getId();
        $this->hiddenItemId = (int) $hidden->getId();
        $this->categoryId = (int) $category->getId();
        $this->districtId = (int) $district->getId();
        $this->postId = (int) $post->getId();
        $em->clear();
    }

    protected function tearDown(): void
    {
        $em = $this->em();
        $em->clear();
        $site = $em->find(ServiceSite::class, $this->siteId);
        if ($site !== null) {
            $em->remove($site); // міста, записи, правила й журнал — ON DELETE CASCADE
        }
        foreach ([$this->adminEmail, $this->moderatorEmail] as $email) {
            $user = $em->getRepository(User::class)->findOneBy(['email' => $email]);
            if ($user !== null) {
                $em->remove($user);
            }
        }
        $em->flush();
        parent::tearDown();
    }

    protected function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    protected function db(): Connection
    {
        return static::getContainer()->get(Connection::class);
    }

    protected function site(): ServiceSite
    {
        return $this->em()->find(ServiceSite::class, $this->siteId) ?? throw new \LogicException('сайт фікстури');
    }

    protected function city(int $id): ServiceCity
    {
        return $this->em()->find(ServiceCity::class, $id) ?? throw new \LogicException('місто фікстури');
    }

    /** Ціль правила за джерелом в області (null — сайт). */
    protected function target(string $source, ?int $cityId = null): ?string
    {
        $v = $this->db()->fetchOne('SELECT target FROM service_redirect WHERE site_id = ? AND city_key = ? AND source = ?', [$this->siteId, $cityId ?? 0, $source]);

        return $v === false || $v === null ? null : (string) $v;
    }

    protected function logged(string $path, ?int $cityId = null): bool
    {
        $sql = 'SELECT 1 FROM service_not_found WHERE site_id = ? AND path = ?' . ($cityId === null ? '' : ' AND city_id = ' . $cityId);

        return $this->db()->fetchOne($sql, [$this->siteId, $path]) !== false;
    }
}
