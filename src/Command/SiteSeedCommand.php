<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Command;

use App\Entity\ServiceCategory;
use App\Entity\ServiceCity;
use App\Entity\ServiceDistrict;
use App\Entity\ServiceItem;
use App\Entity\ServicePage;
use App\Entity\ServicePost;
use App\Entity\ServicePrice;
use App\Entity\ServiceReview;
use App\Entity\ServiceSite;
use App\Entity\ServiceTeamMember;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Yaml\Yaml;

/**
 * Наповнення сайту з YAML (пак: content/seed.yaml) — новий сайт, демо, стенд. Ідемпотентно:
 * збіг за slug (сайт, місто, категорія, послуга, район, пост, сторінка) чи за парою назва+місто (прайс, відгук, команда) —
 * оновлює поля з файлу, нічого не дублює; --purge-missing не робимо (видаляти — в адмінці).
 *
 * Схема файлу (усі тексти — uk, ru необов'язково; порожнє = не чіпати):
 *   site: {slug, name, apex, schema_type?, hero_image?, about_title_uk?, about_uk?, about_ru?}
 *   cities: [{slug, host, apex: true|false, name_nom_uk, name_gen_uk?, name_dat_uk?, name_loc_uk?, name_*_ru?, phone?, email?,
 *             hours?, address_uk?, meta_title_uk?, meta_desc_uk?, intro_title_uk?, intro_text_uk?, text2_uk?, text3_uk?,
 *             faq_uk?: [{q,a}], districts_intro_uk?, noindex?: bool, gtm_id?}]
 *   categories: [{slug, title_uk, title_ru?, desc_uk?, position?, landing?}]
 *   items: [{slug, category, title_uk, title_ru?, lead_uk?, desc_uk?, desc2_uk?, desc3_uk?, tab_title_uk?, meta_title_uk?,
 *            meta_desc_uk?, faq_uk?: [{q,a}], position?, utp?, hidden_cities?: [slug], price_hidden_cities?: [slug],
 *            prices?: [{city, name_uk, name_ru?, price?, prefix?, text_uk?, unit_uk?, per_unit?, calculator?}]}]
 *   districts: [{city, slug, name_uk, name_ru?, title_uk?, lead_uk?, desc_uk?, group_uk?, sub_areas_uk?, primary?, min_price?, faq_uk?}]
 *   reviews: [{city, author_uk, text_uk, stars?, date?}]
 *   team: [{city, name_uk, name_ru?, role_uk?, desc_uk?, position?, slug?, slug_ru?, meta_title_uk?, meta_desc_uk?,
 *           cities?: [slug] (інші міста, де людина теж працює; city — основне), items?: [slug послуги],
 *           extra?: {ключ: значення | {uk, ru}} (app.custom_fields.team)}]
 *   posts: [{slug, title_uk, lead_uk?, desc_uk?, meta_title_uk?, meta_desc_uk?, published?: YYYY-MM-DD}]
 *   pages: [{slug, slug_ru?, parent?: slug, title_uk, title_ru?, lead_uk?, desc_uk?, meta_title_uk?, meta_desc_uk?, position?,
 *            footer?: bool, active?: bool, hidden_cities?: [slug]}]   — сторінки app.pages (/slug/, /parent/slug/)
 */
#[AsCommand(name: 'app:site:seed', description: 'Наповнити сайт з YAML (пак content/seed.yaml): сайт, міста, послуги, прайс, райони, відгуки, команда, блог, сторінки; ідемпотентно за slug')]
final class SiteSeedCommand
{
    private const array LOC = ['Uk', 'Ru'];

    /** @var array<string, int> */
    private array $report = [];

    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('Шлях до YAML (напр. <пак>/content/seed.yaml)')] string $file,
        #[Option('Лише показати, що буде створено/оновлено')] bool $dryRun = false,
    ): int {
        if (!is_file($file)) {
            $io->error('Файлу нема: ' . $file);

            return Command::FAILURE;
        }
        $data = Yaml::parseFile($file);
        if (!\is_array($data) || !isset($data['site']['slug'])) {
            $io->error('У YAML має бути site.slug');

            return Command::FAILURE;
        }

        $site = $this->site($data['site']);
        $cities = [];
        foreach ($data['cities'] ?? [] as $c) {
            $cities[$c['slug']] = $this->city($site, $c);
        }
        if ($cities === []) {
            foreach ($this->em->getRepository(ServiceCity::class)->findBy(['site' => $site]) as $c) {
                $cities[$c->getSlug()] = $c;
            }
        }
        $categories = [];
        foreach ($data['categories'] ?? [] as $i => $c) {
            $categories[$c['slug']] = $this->category($site, $c, $i);
        }
        $items = [];
        foreach ($data['items'] ?? [] as $i => $it) {
            $cat = $categories[$it['category']] ?? $this->em->getRepository(ServiceCategory::class)->findOneBy(['site' => $site, 'slugUk' => $it['category']])
                ?? throw new \RuntimeException('items: нема категорії ' . $it['category']);
            $item = $items[$it['slug']] = $this->item($site, $cat, $it, $i, $cities);
            foreach ($it['prices'] ?? [] as $j => $p) {
                $this->price($item, $this->cityOf($cities, $p['city']), $p, $j);
            }
        }
        foreach ($data['districts'] ?? [] as $i => $d) {
            $this->district($this->cityOf($cities, $d['city']), $d, $i);
        }
        foreach ($data['reviews'] ?? [] as $r) {
            $this->review($this->cityOf($cities, $r['city']), $r);
        }
        foreach ($data['team'] ?? [] as $i => $t) {
            $this->team($site, $this->cityOf($cities, $t['city']), $t, $i, $cities, $items);
        }
        foreach ($data['posts'] ?? [] as $p) {
            $this->post($site, $p);
        }
        // сторінки: спершу всі (батько може йти в файлі після дочірньої), потім зв'язки з батьками
        $pages = [];
        foreach ($data['pages'] ?? [] as $i => $p) {
            $pages[$p['slug']] = $this->page($site, $p, $i, $cities);
        }
        foreach ($data['pages'] ?? [] as $p) {
            $parent = null;
            if (!empty($p['parent'])) {
                $parent = $pages[$p['parent']] ?? $this->em->getRepository(ServicePage::class)->findOneBy(['site' => $site, 'slugUk' => $p['parent']])
                    ?? throw new \RuntimeException('pages: нема батьківської сторінки ' . $p['parent']);
            }
            $pages[$p['slug']]->setParent($parent);
        }

        if ($dryRun) {
            $this->em->clear();
            $io->note('dry-run: нічого не записано');
        } else {
            $this->em->flush();
        }
        $rows = [];
        foreach ($this->report as $k => $n) {
            $rows[] = [$k, $n];
        }
        $io->table(['що', 'скільки'], $rows);
        $io->success(sprintf('Сайт %s (%s): дані з %s застосовано', $site->getSlug(), $site->getApexDomain(), basename($file)));

        return Command::SUCCESS;
    }

    /** @param array<string, mixed> $d */
    private function site(array $d): ServiceSite
    {
        $site = $this->em->getRepository(ServiceSite::class)->findOneBy(['slug' => $d['slug']]);
        $new = $site === null;
        $site ??= (new ServiceSite())->setSlug($d['slug']);
        $site->setName((string) ($d['name'] ?? $site->getName()));
        $site->setApexDomain((string) ($d['apex'] ?? $site->getApexDomain()));
        if (isset($d['schema_type'])) {
            $site->setSchemaType((string) $d['schema_type']);
        }
        if (isset($d['hero_image'])) {
            $site->setHeroImagePath((string) $d['hero_image']);
        }
        $this->loc($site, $d, ['about_title' => 'aboutTitle', 'about' => 'about', 'privacy' => 'privacy', 'terms' => 'terms']);
        $site->setActive(true);
        $this->em->persist($site);
        $this->count('site', $new);

        return $site;
    }

    /** @param array<string, mixed> $d */
    private function city(ServiceSite $site, array $d): ServiceCity
    {
        $city = $this->em->getRepository(ServiceCity::class)->findOneBy(['site' => $site, 'slug' => $d['slug']]);
        $new = $city === null;
        $city ??= (new ServiceCity())->setSite($site)->setSlug($d['slug']);
        $city->setHost((string) ($d['host'] ?? $city->getHost()));
        if (isset($d['apex'])) {
            $city->setIsApex((bool) $d['apex']);
        }
        foreach (['phone', 'email', 'hours', 'viber', 'telegram', 'whatsapp', 'gtmId' => 'gtm_id', 'mapEmbedUrl' => 'map_embed_url', 'mapLinkUrl' => 'map_link_url', 'leadEmails' => 'lead_emails'] as $prop => $key) {
            if (\is_int($prop)) {
                $prop = $key;
            }
            if (\array_key_exists($key, $d)) {
                $city->{'set' . ucfirst($prop)}($d[$key] === null ? null : (string) $d[$key]);
            }
        }
        if (isset($d['noindex'])) {
            $city->setNoindex((bool) $d['noindex']);
        }
        $this->loc($city, $d, ['name_nom' => 'nameNom', 'name_gen' => 'nameGen', 'name_dat' => 'nameDat', 'name_loc' => 'nameLoc', 'address' => 'address',
            'meta_title' => 'metaTitle', 'meta_desc' => 'metaDesc', 'intro_title' => 'introTitle', 'intro_text' => 'introText', 'text2' => 'text2', 'text3' => 'text3',
            'districts_intro' => 'districtsIntro', 'about' => 'about', 'about_where' => 'aboutWhere', 'faq' => 'faq']);
        $city->setActive(true);
        $this->em->persist($city);
        $this->count('city', $new);

        return $city;
    }

    /** @param array<string, mixed> $d */
    private function category(ServiceSite $site, array $d, int $i): ServiceCategory
    {
        $cat = $this->em->getRepository(ServiceCategory::class)->findOneBy(['site' => $site, 'slugUk' => $d['slug']]);
        $new = $cat === null;
        $cat ??= (new ServiceCategory())->setSite($site)->setSlugUk($d['slug']);
        $this->loc($cat, $d, ['title' => 'title', 'desc' => 'desc', 'desc2' => 'desc2', 'desc3' => 'desc3', 'meta_title' => 'metaTitle', 'meta_desc' => 'metaDesc']);
        $cat->setPosition((int) ($d['position'] ?? ($i + 1) * 10));
        if (isset($d['landing'])) {
            $cat->setLanding((bool) $d['landing']);
        }
        $cat->setActive(true);
        $this->em->persist($cat);
        $this->count('category', $new);

        return $cat;
    }

    /**
     * @param array<string, mixed>      $d
     * @param array<string, ServiceCity> $cities
     */
    private function item(ServiceSite $site, ServiceCategory $cat, array $d, int $i, array $cities): ServiceItem
    {
        $item = $this->em->getRepository(ServiceItem::class)->findOneBy(['site' => $site, 'slugUk' => $d['slug']]);
        $new = $item === null;
        $item ??= (new ServiceItem())->setSite($site)->setSlugUk($d['slug']);
        $item->setCategory($cat);
        $this->loc($item, $d, ['title' => 'title', 'lead' => 'lead', 'desc' => 'desc', 'desc2' => 'desc2', 'desc3' => 'desc3', 'tab_title' => 'tabTitle',
            'meta_title' => 'metaTitle', 'meta_desc' => 'metaDesc', 'faq' => 'faq']);
        $item->setPosition((int) ($d['position'] ?? ($i + 1) * 10));
        if (isset($d['utp'])) {
            $item->setUtp((bool) $d['utp']);
        }
        foreach ($d['hidden_cities'] ?? [] as $slug) {
            $item->addHiddenCity($this->cityOf($cities, $slug));
        }
        foreach ($d['price_hidden_cities'] ?? [] as $slug) {
            $item->addPriceHiddenCity($this->cityOf($cities, $slug));
        }
        $item->setActive(true);
        $this->em->persist($item);
        $this->count('item', $new);

        return $item;
    }

    /** @param array<string, mixed> $d */
    private function price(ServiceItem $item, ServiceCity $city, array $d, int $i): void
    {
        $price = $this->em->getRepository(ServicePrice::class)->findOneBy(['item' => $item, 'city' => $city, 'nameUk' => $d['name_uk']]);
        $new = $price === null;
        $price ??= (new ServicePrice())->setItem($item)->setCity($city)->setNameUk((string) $d['name_uk']);
        if (isset($d['name_ru'])) {
            $price->setNameRu((string) $d['name_ru']);
        }
        $price->setPrice(isset($d['price']) ? (string) $d['price'] : null);
        $price->setPricePrefix(isset($d['prefix']) ? (string) $d['prefix'] : null);
        $this->loc($price, $d, ['text' => 'priceText', 'desc' => 'desc', 'unit' => 'unit']);
        if (isset($d['per_unit'])) {
            $price->setPerUnit((bool) $d['per_unit']);
        }
        if (isset($d['calculator'])) {
            $price->setInCalculator((bool) $d['calculator']);
        }
        $price->setPosition((int) ($d['position'] ?? ($i + 1) * 10));
        $price->setActive(true);
        $this->em->persist($price);
        $this->count('price', $new);
    }

    /** @param array<string, mixed> $d */
    private function district(ServiceCity $city, array $d, int $i): void
    {
        $dist = $this->em->getRepository(ServiceDistrict::class)->findOneBy(['city' => $city, 'slugUk' => $d['slug']]);
        $new = $dist === null;
        $dist ??= (new ServiceDistrict())->setCity($city)->setSlugUk($d['slug']);
        $this->loc($dist, $d, ['name' => 'name', 'title' => 'title', 'lead' => 'lead', 'desc' => 'desc', 'group' => 'group', 'sub_areas' => 'subAreas',
            'meta_title' => 'metaTitle', 'meta_desc' => 'metaDesc', 'faq' => 'faq']);
        if (isset($d['primary'])) {
            $dist->setIsPrimary((bool) $d['primary']);
        }
        if (isset($d['min_price'])) {
            $dist->setMinPrice((int) $d['min_price']);
        }
        $dist->setPosition((int) ($d['position'] ?? ($i + 1) * 10));
        $dist->setActive(true);
        $this->em->persist($dist);
        $this->count('district', $new);
    }

    /** @param array<string, mixed> $d */
    private function review(ServiceCity $city, array $d): void
    {
        $rev = $this->em->getRepository(ServiceReview::class)->findOneBy(['city' => $city, 'authorUk' => $d['author_uk'], 'textUk' => $d['text_uk']]);
        $new = $rev === null;
        $rev ??= (new ServiceReview())->setCity($city)->setAuthorUk((string) $d['author_uk'])->setTextUk((string) $d['text_uk']);
        if (isset($d['author_ru'])) {
            $rev->setAuthorRu((string) $d['author_ru']);
        }
        if (isset($d['text_ru'])) {
            $rev->setTextRu((string) $d['text_ru']);
        }
        $rev->setStars((int) ($d['stars'] ?? 5));
        if (isset($d['date'])) {
            $rev->setReviewedAt($this->date($d['date']));
        }
        $rev->setActive(true);
        $this->em->persist($rev);
        $this->count('review', $new);
    }

    /**
     * Людина команди: збіг за slug (якщо заданий) у межах сайту, інакше за ім'ям у місті. city — основне місто,
     * cities — інші міста, де людина теж працює; items — slug-и послуг; extra — додаткові поля (app.custom_fields.team):
     * {ключ: 'значення'} або {ключ: {uk, ru}}.
     *
     * @param array<string, mixed>       $d
     * @param array<string, ServiceCity> $cities
     * @param array<string, ServiceItem> $items  послуги з цього ж файлу (ще не записані в БД)
     */
    private function team(ServiceSite $site, ServiceCity $city, array $d, int $i, array $cities, array $items): void
    {
        $repo = $this->em->getRepository(ServiceTeamMember::class);
        $slug = isset($d['slug']) ? (string) $d['slug'] : null;
        // новий сайт (ще без id) у БД людей не має — DQL з неперсистованою сутністю впав би
        $m = ($slug !== null && $site->getId() !== null ? $repo->findOneBySiteAndAnySlug($site, $slug) : null)
            ?? $repo->findOneBy(['city' => $city, 'nameUk' => $d['name_uk']]);
        $new = $m === null;
        $m ??= new ServiceTeamMember();
        $m->setCity($city)->setNameUk((string) $d['name_uk']);
        if ($slug !== null) {
            $m->setSlugUk($slug);
        }
        $this->loc($m, $d, ['name' => 'name', 'role' => 'role', 'desc' => 'desc', 'slug' => 'slug', 'meta_title' => 'metaTitle', 'meta_desc' => 'metaDesc']);
        foreach ($d['cities'] ?? [] as $c) {
            if ($c !== $city->getSlug()) {
                $m->addCity($this->cityOf($cities, (string) $c));
            }
        }
        foreach ($d['items'] ?? [] as $s) {
            $m->addItem($items[$s] ?? $this->em->getRepository(ServiceItem::class)->findOneBy(['site' => $site, 'slugUk' => $s])
                ?? throw new \RuntimeException('team: нема послуги ' . $s));
        }
        if (isset($d['extra']) && \is_array($d['extra'])) {
            $extra = $m->getExtra() ?? [];
            foreach ($d['extra'] as $key => $v) {
                $extra[(string) $key] = \is_array($v) ? array_map('strval', $v) : ['uk' => (string) $v];
            }
            $m->setExtra($extra);
        }
        $m->setPosition((int) ($d['position'] ?? ($i + 1) * 10));
        $m->setActive(true);
        $this->em->persist($m);
        $this->count('team', $new);
    }

    /** @param array<string, mixed> $d */
    private function post(ServiceSite $site, array $d): void
    {
        $post = $this->em->getRepository(ServicePost::class)->findOneBy(['site' => $site, 'slugUk' => $d['slug']]);
        $new = $post === null;
        $post ??= (new ServicePost())->setSite($site)->setSlugUk($d['slug']);
        $this->loc($post, $d, ['title' => 'title', 'lead' => 'lead', 'desc' => 'desc', 'meta_title' => 'metaTitle', 'meta_desc' => 'metaDesc']);
        $post->setPublishedAt($this->date($d['published'] ?? 'now'));
        $post->setActive(true);
        $this->em->persist($post);
        $this->count('post', $new);
    }

    /**
     * @param array<string, mixed>       $d
     * @param array<string, ServiceCity> $cities
     */
    private function page(ServiceSite $site, array $d, int $i, array $cities): ServicePage
    {
        $page = $this->em->getRepository(ServicePage::class)->findOneBy(['site' => $site, 'slugUk' => $d['slug']]);
        $new = $page === null;
        $page ??= (new ServicePage())->setSite($site)->setSlugUk($d['slug']);
        if (\array_key_exists('slug_ru', $d)) {
            $page->setSlugRu($d['slug_ru'] !== null ? (string) $d['slug_ru'] : null);
        }
        $this->loc($page, $d, ['title' => 'title', 'lead' => 'lead', 'desc' => 'desc', 'meta_title' => 'metaTitle', 'meta_desc' => 'metaDesc']);
        $page->setPosition((int) ($d['position'] ?? ($i + 1) * 10));
        $page->setInFooter((bool) ($d['footer'] ?? $page->isInFooter()));
        $page->setActive((bool) ($d['active'] ?? true));
        foreach ($d['hidden_cities'] ?? [] as $slug) {
            $page->addHiddenCity($this->cityOf($cities, $slug));
        }
        $this->em->persist($page);
        $this->count('page', $new);

        return $page;
    }

    /**
     * Локалізовані поля: ключ YAML `<key>_uk` / `<key>_ru` → setter `set<Prop>Uk` / `set<Prop>Ru`. Відсутній ключ не чіпає поле.
     *
     * @param array<string, mixed>  $d
     * @param array<string, string> $map key → prop
     */
    private function loc(object $e, array $d, array $map): void
    {
        foreach ($map as $key => $prop) {
            foreach (self::LOC as $suffix) {
                $k = $key . '_' . strtolower($suffix);
                if (!\array_key_exists($k, $d)) {
                    continue;
                }
                $setter = 'set' . ucfirst($prop) . $suffix;
                if (!method_exists($e, $setter)) {
                    throw new \RuntimeException(sprintf('%s: нема поля %s', $e::class, $k));
                }
                $v = $d[$k];
                if ($prop === 'faq' && \is_array($v)) {
                    $v = array_values(array_map(static fn ($r) => ['q' => (string) ($r['q'] ?? ''), 'a' => (string) ($r['a'] ?? '')], $v));
                }
                $e->{$setter}($v === null ? null : (\is_array($v) ? $v : (string) $v));
            }
        }
    }

    /** YAML віддає дату як timestamp (int) або DateTime — приймаємо все. */
    private function date(mixed $v): \DateTimeImmutable
    {
        if ($v instanceof \DateTimeInterface) {
            return \DateTimeImmutable::createFromInterface($v);
        }
        if (\is_int($v)) {
            return (new \DateTimeImmutable())->setTimestamp($v);
        }

        return new \DateTimeImmutable((string) $v);
    }

    /** @param array<string, ServiceCity> $cities */
    private function cityOf(array $cities, string $slug): ServiceCity
    {
        return $cities[$slug] ?? throw new \RuntimeException('Нема міста ' . $slug . ' (додайте в cities:)');
    }

    private function count(string $what, bool $new): void
    {
        $k = $what . ($new ? ' створено' : ' оновлено');
        $this->report[$k] = ($this->report[$k] ?? 0) + 1;
    }
}
