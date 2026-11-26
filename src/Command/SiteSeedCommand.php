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
use App\Entity\ServicePageImage;
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
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Yaml\Yaml;
use Vich\UploaderBundle\FileAbstraction\ReplacingFile;
use Vich\UploaderBundle\Storage\StorageInterface;

/**
 * Наповнення сайту з YAML (пак: content/seed.yaml) — новий сайт, демо, стенд. Ідемпотентно:
 * збіг за slug (сайт, місто, категорія, послуга, район, пост, сторінка) чи за парою назва+місто (прайс, відгук, команда) —
 * оновлює поля з файлу, нічого не дублює; --purge-missing не робимо (видаляти — в адмінці).
 *
 * Схема файлу (усі тексти — uk, ru необов'язково; порожнє = не чіпати). Кожне поле `<key>_uk` має пару `<key>_ru` і `<key>_en`,
 * адреси — `slug_en` (категорії, послуги, райони, сторінки); en-запис існує на [uk, ru, en], коли заповнено title_en (name_en):
 *   site: {slug, name, apex, schema_type?, hero_image?, about_title_uk?, about_uk?, about_ru?}
 *   cities: [{slug, host, apex: true|false, name_nom_uk, name_gen_uk?, name_dat_uk?, name_loc_uk?, name_*_ru?, phone?, email?,
 *             hours?, address_uk?, meta_title_uk?, meta_desc_uk?, intro_title_uk?, intro_text_uk?, text2_uk?, text3_uk?,
 *             faq_uk?: [{q,a}], districts_intro_uk?, noindex?: bool, gtm_id?}]
 *   categories: [{slug, slug_en?, title_uk, title_ru?, title_en?, lead_uk?, faq_uk?, desc_uk?, position?, landing?, noindex?}]
 *   items: [{slug, slug_en?, category, title_uk, title_ru?, title_en?, lead_uk?, desc_uk?, desc2_uk?, desc3_uk?, tab_title_uk?, meta_title_uk?,
 *            meta_desc_uk?, faq_uk?: [{q,a}], position?, utp?, noindex?, hidden_cities?: [slug], price_hidden_cities?: [slug],
 *            prices?: [{city, name_uk, name_ru?, price?, prefix?, text_uk?, unit_uk?, per_unit?, calculator?}]}]
 *   districts: [{city, slug, name_uk, name_ru?, title_uk?, lead_uk?, desc_uk?, group_uk?, sub_areas_uk?, primary?, min_price?, faq_uk?}]
 *   reviews: [{city, author_uk, text_uk, stars?, date?}]
 *   team: [{city, name_uk, role_uk?, desc_uk?, position?}]
 *   posts: [{slug, title_uk, lead_uk?, desc_uk?, meta_title_uk?, meta_desc_uk?, published?: YYYY-MM-DD}]
 *   pages: [{slug, slug_ru?, slug_en?, parent?: slug, title_uk, title_ru?, title_en?, h1_uk?, lead_uk?, desc_uk? (або body_uk?), meta_title_uk?,
 *            meta_desc_uk?, template?: page|hub|sitemap|form:<ключ>, items?: [slug послуги] (hub), noindex?, in_sitemap?,
 *            position?, footer?: bool, active?: bool, hidden_cities?: [slug],
 *            extra?: {<ключ app.custom_fields.page>: значення | {uk?, ru?, en?}},
 *            cover?: файл | {file, alt?}, gallery?: [{file, caption? (= caption_uk), caption_ru?, caption_en?, position?}]}]
 *     — сторінки app.pages (/slug/, /parent/slug/).
 *     extra: скаляр пишеться мовами розгортання (app.locales без ru: ru бере uk), мапа — як є; ключі, яких у файлі нема,
 *     лишаються (внесене в адмінці), null чи порожній рядок прибирає ключ.
 *     cover/gallery: файл — шлях відносно теки seed.yaml (або кореня паку, коли seed лежить у content/), не поза паком;
 *     JPG/PNG/WebP до 10 МБ. Копія лягає в media/service_page під унікальним іменем (Vich, як з адмінки). Повторний seed
 *     упізнає вже залитий файл за вмістом: галерея не дублюється, підпис і порядок оновлюються; зображення, яких у файлі
 *     нема, лишаються (видаляти — в адмінці).
 */
#[AsCommand(name: 'app:site:seed', description: 'Наповнити сайт з YAML (пак content/seed.yaml): сайт, міста, послуги, прайс, райони, відгуки, команда, блог, сторінки; ідемпотентно за slug')]
final class SiteSeedCommand
{
    private const array LOC = ['Uk', 'Ru', 'En'];

    /** @var array<string, int> */
    private array $report = [];

    /** JPG/PNG/WebP до 10 МБ — ті самі межі, що у формі адмінки (Assert\Image сутностей) */
    private const array IMAGE_TYPES = [\IMAGETYPE_JPEG, \IMAGETYPE_PNG, \IMAGETYPE_WEBP];
    private const int IMAGE_MAX_BYTES = 10 * 1024 * 1024;

    /** Тека seed.yaml: від неї шляхи файлів обкладинки й галереї */
    private string $baseDir = '';

    /** dry-run: файлів не копіюємо (Vich заливає файл уже на persist(), до flush) */
    private bool $dryRun = false;

    /** @param list<string> $locales мови розгортання (app.locales) */
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly StorageInterface $storage,
        #[Autowire('%app.locales%')]
        private readonly array $locales = ['uk', 'ru'],
    ) {
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

        $this->baseDir = (string) realpath(\dirname($file));
        $this->dryRun = $dryRun;
        // файли обкладинок і галерей перевіряємо до першого запису: Vich копіює файл на persist(), і помилка в останній
        // сторінці лишила б у медіа файли перших
        foreach ($data['pages'] ?? [] as $p) {
            $slug = (string) ($p['slug'] ?? '');
            if (!empty($p['cover'])) {
                $this->packFile((string) (\is_array($p['cover']) ? ($p['cover']['file'] ?? '') : $p['cover']), 'cover', $slug);
            }
            foreach ((array) ($p['gallery'] ?? []) as $row) {
                $this->packFile((string) (\is_array($row) ? ($row['file'] ?? '') : $row), 'gallery', $slug);
            }
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
        $items = [];
        foreach ($data['categories'] ?? [] as $i => $c) {
            $categories[$c['slug']] = $this->category($site, $c, $i);
        }
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
            $this->team($this->cityOf($cities, $t['city']), $t, $i);
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
            // послуги хаба: з цього ж файлу або вже наявні; список у файлі замінює попередній
            if (\array_key_exists('items', $p)) {
                $page = $pages[$p['slug']];
                foreach ($page->getItems()->toArray() as $old) {
                    $page->removeItem($old);
                }
                foreach ((array) $p['items'] as $slug) {
                    $page->addItem($items[$slug] ?? $this->em->getRepository(ServiceItem::class)->findOneBy(['site' => $site, 'slugUk' => $slug])
                        ?? throw new \RuntimeException('pages: нема послуги ' . $slug . ' (сторінка ' . $p['slug'] . ')'));
                }
            }
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
            'districts_intro' => 'districtsIntro', 'about' => 'about', 'about_where' => 'aboutWhere', 'price_note' => 'priceNote', 'faq' => 'faq']);
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
        $this->slugEn($cat, $d);
        $this->loc($cat, $d, ['title' => 'title', 'lead' => 'lead', 'faq' => 'faq', 'desc' => 'desc', 'desc2' => 'desc2', 'desc3' => 'desc3', 'meta_title' => 'metaTitle', 'meta_desc' => 'metaDesc']);
        $cat->setPosition((int) ($d['position'] ?? ($i + 1) * 10));
        if (isset($d['landing'])) {
            $cat->setLanding((bool) $d['landing']);
        }
        if (isset($d['noindex'])) {
            $cat->setNoindex((bool) $d['noindex']);
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
        $this->slugEn($item, $d);
        // name_* (коротка назва) свідомо не з seed: site-csm має name_uk/name_ru з меню старого сайту, що досі ігнорувались —
        // повторний seed змінив би назви в меню й картках (23.11.2026); коротка назва — в адмінці
        $this->loc($item, $d, ['title' => 'title', 'lead' => 'lead', 'desc' => 'desc', 'desc2' => 'desc2', 'desc3' => 'desc3', 'tab_title' => 'tabTitle',
            'meta_title' => 'metaTitle', 'meta_desc' => 'metaDesc', 'faq' => 'faq']);
        $item->setPosition((int) ($d['position'] ?? ($i + 1) * 10));
        if (isset($d['utp'])) {
            $item->setUtp((bool) $d['utp']);
        }
        if (isset($d['noindex'])) {
            $item->setNoindex((bool) $d['noindex']);
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
        $this->slugEn($dist, $d);
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

    /** @param array<string, mixed> $d */
    private function team(ServiceCity $city, array $d, int $i): void
    {
        $m = $this->em->getRepository(ServiceTeamMember::class)->findOneBy(['city' => $city, 'nameUk' => $d['name_uk']]);
        $new = $m === null;
        $m ??= (new ServiceTeamMember())->setCity($city)->setNameUk((string) $d['name_uk']);
        $this->loc($m, $d, ['name' => 'name', 'role' => 'role', 'desc' => 'desc']);
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
        $this->slugEn($post, $d);
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
        // body_uk/body_ru — синонім desc_* (так поле зветься в паках, що переносять сторінки зі старої CMS)
        foreach (['uk', 'ru', 'en'] as $l) {
            if (\array_key_exists('body_' . $l, $d) && !\array_key_exists('desc_' . $l, $d)) {
                $d['desc_' . $l] = $d['body_' . $l];
            }
            unset($d['body_' . $l]);
        }
        $this->slugEn($page, $d);
        $this->loc($page, $d, ['title' => 'title', 'h1' => 'h1', 'lead' => 'lead', 'desc' => 'desc', 'meta_title' => 'metaTitle', 'meta_desc' => 'metaDesc']);
        if (\array_key_exists('template', $d)) {
            $page->setTemplate($d['template'] !== null ? (string) $d['template'] : null);
            if (preg_match(ServicePage::TEMPLATE_PATTERN, $page->getTemplate()) !== 1) {
                throw new \RuntimeException(sprintf('pages: невідомий шаблон «%s» (сторінка %s): page, hub, sitemap або form:<ключ>', $page->getTemplate(), $d['slug']));
            }
        }
        if (isset($d['noindex'])) {
            $page->setNoindex((bool) $d['noindex']);
        }
        if (isset($d['in_sitemap'])) {
            $page->setInSitemap((bool) $d['in_sitemap']);
        }
        $page->setPosition((int) ($d['position'] ?? ($i + 1) * 10));
        $page->setInFooter((bool) ($d['footer'] ?? $page->isInFooter()));
        $page->setActive((bool) ($d['active'] ?? true));
        foreach ($d['hidden_cities'] ?? [] as $slug) {
            $page->addHiddenCity($this->cityOf($cities, $slug));
        }
        if (\array_key_exists('extra', $d)) {
            $page->setExtra($this->extra((array) $d['extra'], (array) $page->getExtra()));
        }
        if (!empty($d['cover'])) {
            $this->cover($page, \is_array($d['cover']) ? $d['cover'] : ['file' => (string) $d['cover']]);
        }
        if (\array_key_exists('gallery', $d)) {
            $this->gallery($page, (array) $d['gallery']);
        }
        $this->em->persist($page);
        $this->count('page', $new);

        return $page;
    }

    /**
     * Значення додаткових полів запису (ExtraFieldsTrait): скаляр → мовами розгортання, мапа {uk, ru, en} → як є.
     *
     * @param array<string, mixed>                 $values
     * @param array<string, array<string, string>> $current
     *
     * @return array<string, array<string, string>>
     */
    private function extra(array $values, array $current): array
    {
        $langs = array_values(array_intersect(['uk', 'en'], $this->locales)) ?: ['uk'];
        foreach ($values as $key => $value) {
            $key = (string) $key;
            if (preg_match('/^[a-z0-9_]{1,40}$/', $key) !== 1) {
                throw new \RuntimeException(sprintf('pages: extra — ключ «%s»: лише [a-z0-9_], до 40 символів (як в app.custom_fields).', $key));
            }
            if (\is_array($value)) {
                if (array_diff(array_keys($value), ['uk', 'ru', 'en']) !== []) {
                    throw new \RuntimeException(sprintf('pages: extra.%s — мови лише uk, ru, en.', $key));
                }
                $current[$key] = array_map(static fn ($v): string => \is_scalar($v) ? (string) $v : '', $value);
            } elseif ($value === null || trim((string) $value) === '') {
                unset($current[$key]);
            } else {
                $current[$key] = array_fill_keys($langs, (string) $value);
            }
        }

        return $current;
    }

    /** @param array<string, mixed> $d {file, alt?} */
    private function cover(ServicePage $page, array $d): void
    {
        $src = $this->packFile((string) ($d['file'] ?? ''), 'cover', $page->getSlugUk());
        if (!$this->sameFile($this->storedPath($page), $src)) {
            if (!$this->dryRun) {
                $page->setImageFile(new ReplacingFile($src));
            }
            $this->count('page cover', true);
        }
        if (\array_key_exists('alt', $d)) {
            $page->setImageAlt($d['alt'] === null ? null : (string) $d['alt']);
        }
    }

    /** @param array<int, mixed> $rows [{file, caption?, caption_uk?, caption_ru?, caption_en?, position?}] */
    private function gallery(ServicePage $page, array $rows): void
    {
        // уже залиті зображення сторінки за вмістом файлу; кожне зіставляється не більше ніж з одним рядком seed
        $stored = [];
        foreach ($page->getImages() as $image) {
            $path = $this->storedPath($image);
            if ($path !== null) {
                $stored[(string) sha1_file($path)][] = $image;
            }
        }
        foreach (array_values($rows) as $i => $row) {
            $row = \is_array($row) ? $row : ['file' => (string) $row];
            $src = $this->packFile((string) ($row['file'] ?? ''), 'gallery', $page->getSlugUk());
            $hash = (string) sha1_file($src);
            $image = isset($stored[$hash]) ? array_shift($stored[$hash]) : null;
            $new = $image === null;
            if ($new) {
                $image = new ServicePageImage();
                if (!$this->dryRun) {
                    $image->setImageFile(new ReplacingFile($src));
                }
                $page->addImage($image);
            }
            if (\array_key_exists('caption', $row) && !\array_key_exists('caption_uk', $row)) {
                $row['caption_uk'] = $row['caption'];
            }
            $this->loc($image, $row, ['caption' => 'caption']);
            $image->setPosition((int) ($row['position'] ?? ($i + 1) * 10));
            $this->count('page image', $new);
        }
    }

    /** Шлях уже збереженого файлу сутності (обкладинка сторінки, зображення галереї); null — файлу нема. */
    private function storedPath(ServicePage|ServicePageImage $entity): ?string
    {
        if ($entity->getImageName() === null || $entity->getImageName() === '') {
            return null;
        }
        $path = $this->storage->resolvePath($entity, 'imageFile');

        return $path !== null && is_file($path) ? $path : null;
    }

    private function sameFile(?string $stored, string $src): bool
    {
        return $stored !== null && filesize($stored) === filesize($src) && sha1_file($stored) === sha1_file($src);
    }

    /**
     * Файл паку за шляхом із seed: відносно теки seed.yaml, далі — кореня паку (коли seed лежить у content/). Лише всередині
     * паку (без виходу через ..), лише зображення, які приймає адмінка.
     */
    private function packFile(string $rel, string $what, string $slug): string
    {
        $root = basename($this->baseDir) === 'content' ? \dirname($this->baseDir) : $this->baseDir;
        $found = null;
        if ($rel !== '' && !str_starts_with($rel, '/')) {
            foreach (array_unique([$this->baseDir, $root]) as $dir) {
                $path = realpath($dir . '/' . $rel);
                if ($path !== false && is_file($path) && str_starts_with($path, $root . '/')) {
                    $found = $path;
                    break;
                }
            }
        }
        if ($found === null) {
            throw new \RuntimeException(sprintf('pages: %s — нема файлу «%s» (сторінка %s): шлях відносно %s, у межах паку.', $what, $rel, $slug, $this->baseDir));
        }
        $info = @getimagesize($found);
        if ($info === false || !\in_array($info[2], self::IMAGE_TYPES, true) || filesize($found) > self::IMAGE_MAX_BYTES) {
            throw new \RuntimeException(sprintf('pages: %s — «%s» (сторінка %s): дозволено JPG/PNG/WebP до 10 МБ.', $what, $rel, $slug));
        }

        return $found;
    }

    /**
     * `slug_en` (англомовне розгортання): порожньо або null — адреса з українського слагу. Відсутній ключ не чіпає поле.
     *
     * @param array<string, mixed> $d
     */
    private function slugEn(object $e, array $d): void
    {
        if (\array_key_exists('slug_en', $d) && method_exists($e, 'setSlugEn')) {
            $e->setSlugEn($d['slug_en'] !== null && $d['slug_en'] !== '' ? (string) $d['slug_en'] : null);
        }
    }

    /**
     * Локалізовані поля: ключ YAML `<key>_uk` / `<key>_ru` / `<key>_en` → setter `set<Prop>Uk` / `…Ru` / `…En`. Відсутній ключ не чіпає поле.
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
