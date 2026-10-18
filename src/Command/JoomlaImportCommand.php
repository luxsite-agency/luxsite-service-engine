<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Command;

use App\Entity\ServiceCategory;
use App\Entity\ServiceCity;
use App\Entity\ServiceDistrict;
use App\Entity\ServiceItem;
use App\Entity\ServiceLead;
use App\Entity\ServicePost;
use App\Entity\ServicePrice;
use App\Entity\ServiceReview;
use App\Entity\ServiceSite;
use App\Entity\ServiceTeamMember;
use App\ServiceSite\Import\JoomlaMapping;
use App\ServiceSite\Import\SeblodMarkers;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Generic-імпорт сайту з Joomla/Seblod у рушій. Джерело — дамп таблиць Joomla (префікс aim_),
 * залитий у сусідню схему того ж MySQL (--source-schema, типово joomla_src); усе сайтове
 * (id міст і категорій, слаги, FAQ-файли, правила цін) — у YAML-мапінгу паку (--mapping).
 *
 * Конвенції Seblod, на які спирається код: тип <t> зберігає поля в <prefix>cck_store_form_<t>
 * (колонки <t>_<назва>_<мова>), pk = aim_content.id (title/state/catid/ordering/publish_up там),
 * per-мовні аліаси — aim_cck_store_item_content.alias_uk/alias_ru. Повторювані блоки
 * (FAQ, команда) — маркерний формат, див. SeblodMarkers.
 *
 * Типи: domains → ServiceSite + ServiceCity (+ команда), catalog → ServiceCategory/ServiceItem,
 * area → ServiceDistrict (групи з apl_district), iblog → ServicePost, ipage → тексти бренду («Про нас»),
 * apl_prices → ServicePrice, apl_review → ServiceReview, site_leads → ServiceLead (лише --with-leads).
 * Узагальнення 10.2026 (другий сайт з Seblod): у «catalog» може бути кілька Seblod-типів (items мапінгу: cargo, country,
 * catalog — кожен зі своїми колонками, категорією й характеристиками), «iblog» — будь-який тип постів (posts.type: inews),
 * місто без типу domains (усе з мапінгу), тексти категорій із типу на категорії (category_source) або ipage
 * (pages.categories), головна/політика з ipage (pages.home/privacy), команда з таблиці (team.table → тип «team»).
 *
 * Ідемпотентно: upsert по legacyId (команда — по місту й позиції). Повторний прогін оновлює
 * тексти з джерела; те, чого в джерелі нема (нові записи адмінки), не чіпає.
 * Картинки: з --media-root=<корінь Joomla> обкладинки копіюються в public/media/<тип>/.
 */
#[AsCommand(name: 'app:joomla:import', description: 'Імпорт сайту з Joomla/Seblod (схема joomla_src) за YAML-мапінгом паку')]
final class JoomlaImportCommand extends Command
{
    private const MEDIA = [
        'item' => 'service_item',
        'category' => 'service_category',
        'post' => 'service_post',
        'team' => 'service_team',
        'review' => 'service_review',
    ];

    private JoomlaMapping $map;
    private string $schema = 'joomla_src';
    private string $prefix = 'aim_';
    private ?string $mediaRoot = null;
    private bool $dryRun = false;
    private SymfonyStyle $io;

    /** @var array<string, array{new: int, upd: int, skip: int}> */
    private array $stats = [];
    /** @var list<string> що не лягло і чому */
    private array $issues = [];

    /** @var array<int, ServiceCity> legacy id запису domains → місто */
    private array $cityByLegacy = [];
    /** @var array<int, ServiceCity> id у cck_core_sites → місто */
    private array $cityBySiteId = [];
    /** @var array<string, ServiceCity> host → місто */
    private array $cityByHost = [];

    public function __construct(
        private readonly Connection $db,
        private readonly EntityManagerInterface $em,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('mapping', 'm', InputOption::VALUE_REQUIRED, 'YAML-мапінг паку (seo/joomla-mapping.yaml)')
            ->addOption('source-schema', null, InputOption::VALUE_REQUIRED, 'Схема MySQL з таблицями Joomla (перекриває source.schema мапінгу)')
            ->addOption('media-root', null, InputOption::VALUE_REQUIRED, 'Корінь сайту Joomla з images/ — копіювати обкладинки в public/media')
            ->addOption('with-leads', null, InputOption::VALUE_NONE, 'Перенести й заявки (site_leads) як історію')
            ->addOption('only', null, InputOption::VALUE_REQUIRED, 'Лише типи через кому: domains,catalog,area,iblog,ipage,prices,reviews,team')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Без запису в БД (лише звіт)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->io = new SymfonyStyle($input, $output);

        $mappingFile = (string) $input->getOption('mapping');
        if ($mappingFile === '') {
            $this->io->error('Вкажіть --mapping=<шлях до seo/joomla-mapping.yaml паку>.');

            return Command::INVALID;
        }
        $this->map = JoomlaMapping::load($mappingFile);
        $this->schema = JoomlaMapping::ident('--source-schema', $input->getOption('source-schema')) ?? $this->map->sourceSchema();
        $this->prefix = $this->map->tablePrefix();
        $this->dryRun = (bool) $input->getOption('dry-run');
        $this->mediaRoot = $input->getOption('media-root') ? rtrim((string) $input->getOption('media-root'), '/\\') : null;
        if ($this->mediaRoot !== null && !is_dir($this->mediaRoot . '/images')) {
            $this->io->error(sprintf('--media-root: теки %s/images нема.', $this->mediaRoot));

            return Command::INVALID;
        }
        if (!preg_match('/^[A-Za-z0-9_]+$/', $this->schema) || !preg_match('/^[A-Za-z0-9_]*$/', $this->prefix)) {
            $this->io->error('Схема/префікс: лише латиниця, цифри, підкреслення.');

            return Command::INVALID;
        }
        if ($this->db->fetchOne('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = ? AND table_name = ?', [$this->schema, $this->prefix . 'content']) === 0) {
            $this->io->error(sprintf('У схемі %s нема таблиці %scontent — дамп Joomla не залито.', $this->schema, $this->prefix));

            return Command::FAILURE;
        }

        $only = $input->getOption('only') ? array_map('trim', explode(',', (string) $input->getOption('only'))) : null;
        $run = fn (string $type): bool => $this->map->imports($type) && ($only === null || \in_array($type, $only, true));

        // міста потрібні всім наступним крокам — domains імпортуються завжди
        $site = $this->importSite();
        $this->importCities($site);

        if ($run('catalog')) {
            $this->importCategories($site);
            $this->importItems($site);
        }
        if ($run('area')) {
            $this->importDistricts();
        }
        if ($run('iblog')) {
            $this->importPosts($site);
        }
        if ($run('ipage')) {
            $this->importPages($site);
        }
        if ($run('prices')) {
            $this->importPrices($site);
        }
        if ($run('reviews')) {
            $this->importReviews();
        }
        if ($run('team') && $this->map->teamTable() !== null) {
            $this->importTeamTable();
        }
        if ($input->getOption('with-leads') && ($only === null || \in_array('leads', $only, true))) {
            $this->importLeads();
        }

        if ($this->dryRun) {
            $this->em->clear();
            $this->io->note('dry-run: у БД нічого не записано.');
        }

        $rows = [];
        foreach ($this->stats as $type => $s) {
            $rows[] = [$type, $s['new'], $s['upd'], $s['skip']];
        }
        $this->io->table(['Тип', 'Нових', 'Оновлено', 'Пропущено'], $rows);
        if ($this->issues !== []) {
            $this->io->section('Не лягло / потребує уваги');
            $this->io->listing($this->issues);
        }
        $this->io->success(sprintf('Імпорт %s зі схеми %s завершено.', $site->getSlug(), $this->schema));

        return Command::SUCCESS;
    }

    // ───────────────────────────── бренд і міста ─────────────────────────────

    private function importSite(): ServiceSite
    {
        $cfg = $this->map->site();
        $site = $this->em->getRepository(ServiceSite::class)->findOneBy(['slug' => $cfg['slug']]);
        if ($site === null) {
            $site = (new ServiceSite())->setSlug($cfg['slug'])->setName($cfg['name'])->setApexDomain($cfg['apex_domain']);
            $this->em->persist($site);
            $this->bump('site', true);
        } else {
            $this->bump('site', false);
        }
        $site->setSchemaType($cfg['schema_type'])->setActive(true);
        $this->flush();

        return $site;
    }

    private function importCities(ServiceSite $site): void
    {
        $rows = [];
        $cities = $this->map->cities();
        // таблиця domains потрібна лише містам із legacy_id; місто без нього — повністю з мапінгу
        if (array_filter($cities, static fn (array $c): bool => $c['legacy_id'] !== null) !== []) {
            foreach ($this->db->fetchAllAssociative(sprintf('SELECT * FROM %s', $this->t('cck_store_form_domains'))) as $r) {
                $rows[(int) $r['id']] = $r;
            }
        }
        $repo = $this->em->getRepository(ServiceCity::class);
        $year = date('Y');
        $token = $this->map->yearToken();

        foreach ($cities as $cfg) {
            if ($cfg['legacy_id'] === null) {
                $this->importMappedCity($site, $cfg);
                continue;
            }
            $row = $rows[$cfg['legacy_id']] ?? null;
            if ($row === null) {
                $this->issue(sprintf('Місто %s: запису domains id=%d у джерелі нема — пропуск.', $cfg['slug'], $cfg['legacy_id']));
                $this->bump('city', null);
                continue;
            }
            $city = $repo->findOneBy(['site' => $site, 'slug' => $cfg['slug']]);
            if ($city === null) {
                $city = (new ServiceCity())->setSite($site)->setSlug($cfg['slug'])->setHost($cfg['host']);
                $this->em->persist($city);
                $this->bump('city', true);
            } else {
                $this->bump('city', false);
            }
            $meta = static fn (?string $v): ?string => $v === null || $token === null ? $v : str_replace($token, $year, $v);
            $city
                ->setHost($cfg['host'])
                ->setIsApex($cfg['apex'])
                ->setActive(true)
                ->setPhone($this->nn($row['domains_phone']))
                ->setEmail($this->nn($row['domains_email']))
                ->setHours($this->nn($row['domains_hours']))
                ->setNameNomUk($this->nn($row['domains_city_uk']) ?? $cfg['slug'])
                ->setNameGenUk($this->nn($row['domain_city_gen_uk']))
                ->setNameDatUk($this->nn($row['domain_city_dat_uk']))
                ->setNameLocUk($this->nn($row['domain_city_prep_uk']))
                ->setNameNomRu($this->nn($row['domains_city_ru']))
                ->setNameGenRu($this->nn($row['domain_city_gen_ru']))
                ->setNameDatRu($this->nn($row['domain_city_dat_ru']))
                ->setNameLocRu($this->nn($row['domain_city_prep_ru']))
                ->setMetaTitleUk($meta($this->nn($row['domains_meta_title_uk'])))
                ->setMetaTitleRu($meta($this->nn($row['domains_meta_title_ru'])))
                ->setMetaDescUk($this->nn($row['domains_meta_desc_uk']))
                ->setMetaDescRu($this->nn($row['domains_meta_desc_ru']))
                // H1 хіро: domains_title («Виклик||сантехніка», || = перенос) + subtitle («в Києві та області»)
                ->setIntroTitleUk($this->introTitle('uk', $row) ?? $this->heroTitle($row['domains_title_uk'], $row['domains_subtitle_uk']))
                ->setIntroTitleRu($this->introTitle('ru', $row) ?? $this->heroTitle($row['domains_title_ru'], $row['domains_subtitle_ru']))
                // текст під H1: заголовок блоку txt_title стає <h2> перед txt_desc, щоб не губити його
                ->setIntroTextUk($this->withHeading($row['domains_txt_title_uk'], $row['domains_txt_desc_uk']))
                ->setIntroTextRu($this->withHeading($row['domains_txt_title_ru'], $row['domains_txt_desc_ru']))
                ->setText2Uk($this->html($row['domains_txt_desc_2_uk']))
                ->setText2Ru($this->html($row['domains_txt_desc_2_ru']))
                ->setText3Uk($this->html($row['domains_txt_desc_3_uk']))
                ->setText3Ru($this->html($row['domains_txt_desc_3_ru']))
                ->setFaqUk($this->nnArr(SeblodMarkers::faq((string) $row['domains_faq_page_uk'], 'domains_faq_page_uk')))
                ->setFaqRu($this->nnArr(SeblodMarkers::faq((string) $row['domains_faq_page_ru'], 'domains_faq_page_ru')));

            $this->cityByLegacy[$cfg['legacy_id']] = $city;
            if ($cfg['site_id'] !== null) {
                $this->cityBySiteId[$cfg['site_id']] = $city;
            }
            $this->cityByHost[$cfg['host']] = $city;

            $this->importTeam($city, $row);
        }
        $this->flush();

        if ($this->cityByLegacy === []) {
            throw new \RuntimeException('Жодного міста не імпортовано — далі нема куди класти контент.');
        }
    }

    /**
     * Місто без запису domains: назви у відмінках, контакти — з мапінгу (cities[].name_*, phone, email, hours, address_*).
     * Тексти й SEO головної — з ipage (pages.home), H1 — texts.intro_title з відмінками міста.
     *
     * @param array{legacy_id: ?int, site_id: ?int, slug: string, host: string, apex: bool, fields: array<string, string>} $cfg
     */
    private function importMappedCity(ServiceSite $site, array $cfg): void
    {
        $f = $cfg['fields'];
        $city = $this->em->getRepository(ServiceCity::class)->findOneBy(['site' => $site, 'slug' => $cfg['slug']]);
        if ($city === null) {
            $city = (new ServiceCity())->setSite($site)->setSlug($cfg['slug'])->setHost($cfg['host']);
            $this->em->persist($city);
            $this->bump('city', true);
        } else {
            $this->bump('city', false);
        }
        $city
            ->setHost($cfg['host'])
            ->setIsApex($cfg['apex'])
            ->setActive(true)
            ->setNameNomUk($f['name_nom_uk'] ?? $cfg['slug'])
            ->setNameGenUk($f['name_gen_uk'] ?? null)
            ->setNameDatUk($f['name_dat_uk'] ?? null)
            ->setNameLocUk($f['name_loc_uk'] ?? null)
            ->setNameNomRu($f['name_nom_ru'] ?? null)
            ->setNameGenRu($f['name_gen_ru'] ?? null)
            ->setNameDatRu($f['name_dat_ru'] ?? null)
            ->setNameLocRu($f['name_loc_ru'] ?? null);
        // контакти: лише якщо задані в мапінгу — інакше не затираємо те, що внесли в адмінці
        if (isset($f['phone'])) {
            $city->setPhone($f['phone']);
        }
        if (isset($f['email'])) {
            $city->setEmail($f['email']);
        }
        if (isset($f['hours'])) {
            $city->setHours($f['hours']);
        }
        if (isset($f['address_uk'])) {
            $city->setAddressUk($f['address_uk']);
        }
        if (isset($f['address_ru'])) {
            $city->setAddressRu($f['address_ru']);
        }
        foreach (['viber' => 'setViber', 'telegram' => 'setTelegram', 'whatsapp' => 'setWhatsapp'] as $k => $setter) {
            if (isset($f[$k])) {
                $city->{$setter}($f[$k]);
            }
        }
        $names = [
            'uk' => [$f['name_nom_uk'] ?? '', $f['name_gen_uk'] ?? '', $f['name_dat_uk'] ?? '', $f['name_loc_uk'] ?? ''],
            'ru' => [$f['name_nom_ru'] ?? '', $f['name_gen_ru'] ?? '', $f['name_dat_ru'] ?? '', $f['name_loc_ru'] ?? ''],
        ];
        foreach (['uk' => 'Uk', 'ru' => 'Ru'] as $lang => $sfx) {
            $title = $this->introTitleFrom($lang, ...$names[$lang]);
            if ($title !== null) {
                $city->{'setIntroTitle' . $sfx}($title);
            }
        }
        $this->cityByLegacy[-1 - \count($this->cityByLegacy)] = $city;
        if ($cfg['site_id'] !== null) {
            $this->cityBySiteId[$cfg['site_id']] = $city;
        }
        $this->cityByHost[$cfg['host']] = $city;
    }

    /** @param array<string, mixed> $row */
    private function importTeam(ServiceCity $city, array $row): void
    {
        $uk = SeblodMarkers::team((string) $row['domains_team_uk'], 'domains_team_uk');
        $ru = SeblodMarkers::team((string) $row['domains_team_ru'], 'domains_team_ru');
        $repo = $this->em->getRepository(ServiceTeamMember::class);
        foreach ($uk as $i => $m) {
            $member = $repo->findOneBy(['city' => $city, 'position' => $i]);
            if ($member === null) {
                $member = (new ServiceTeamMember())->setCity($city)->setPosition($i);
                $this->em->persist($member);
                $this->bump('team', true);
            } else {
                $this->bump('team', false);
            }
            $member
                ->setNameUk($m['name'])
                ->setNameRu($this->nn($ru[$i]['name'] ?? null))
                ->setRoleUk($this->nn($m['role']))
                ->setRoleRu($this->nn($ru[$i]['role'] ?? null))
                ->setActive(true);
            if ($m['img'] !== '' && ($name = $this->copyMedia($m['img'], 'team', 'team-' . $city->getSlug())) !== null) {
                $member->setImageName($name)->setImageAlt($m['name']);
            }
        }
    }

    // ───────────────────────────── каталог ─────────────────────────────

    private function importCategories(ServiceSite $site): void
    {
        $repo = $this->em->getRepository(ServiceCategory::class);
        $titles = [];
        foreach ($this->db->fetchAllAssociative(sprintf('SELECT id, title, published FROM %s', $this->t('categories'))) as $r) {
            $titles[(int) $r['id']] = $r;
        }
        // тексти категорії з Seblod-типу на категорії Joomla (category_source) і з ipage (pages.categories)
        $src = $this->map->categorySource();
        $srcRows = [];
        if ($src !== null) {
            foreach ($this->db->fetchAllAssociative(sprintf('SELECT * FROM %s', $this->t('cck_store_form_' . $src['type']))) as $r) {
                $srcRows[(int) $r['id']] = $r;
            }
        }
        $pageRows = $this->ipageRows(array_values($this->map->pageCategories()));

        $pos = 0;
        foreach ($this->map->categories() as $legacyId => $cfg) {
            if (!isset($titles[$legacyId])) {
                $this->issue(sprintf('Категорія %d (%s): у %scategories такого id нема — створено лише з мапінгу.', $legacyId, $cfg['slug_uk'], $this->prefix));
            }
            $cat = $repo->findOneBy(['site' => $site, 'legacyId' => $legacyId]);
            if ($cat === null) {
                $cat = (new ServiceCategory())->setSite($site)->setLegacyId($legacyId);
                $this->em->persist($cat);
                $this->bump('category', true);
            } else {
                $this->bump('category', false);
            }
            // джерело тексту: мапінг > ipage (pages.categories) > тип на категорії (category_source)
            $srow = $srcRows[$legacyId] ?? null;
            $prow = ($pid = $this->map->pageCategories()[$legacyId] ?? null) !== null ? ($pageRows[$pid] ?? null) : null;
            if ($pid !== null && $prow === null) {
                $this->issue(sprintf('Категорія %s: ipage id=%d (pages.categories) у джерелі нема.', $cfg['slug_uk'], $pid));
            }
            $pick = function (string $field, string $lang, bool $html = false) use ($cfg, $srow, $prow, $src): ?string {
                $mapKey = ['meta_desc' => 'meta_desc', 'meta_title' => 'meta_title'][$field] ?? $field;
                $v = $cfg[$mapKey . '_' . $lang] ?? null;
                if ($v !== null && trim((string) $v) !== '') {
                    return $html ? $this->html($v) : $this->nn($v);
                }
                $pageCol = ['title' => 'title', 'lead' => 'lead', 'desc' => 'desc', 'meta_title' => 'meta_title', 'meta_desc' => 'meta_description'][$field] ?? null;
                if ($prow !== null && $pageCol !== null && ($pv = $this->nn($prow['ipage_' . $pageCol . '_' . $lang] ?? null)) !== null) {
                    return $html ? $this->html($pv) : $pv;
                }
                if ($srow !== null && isset($src['fields'][$field])) {
                    $sv = $this->nn($srow[$src['type'] . '_' . $src['fields'][$field] . '_' . $lang] ?? null);
                    if ($sv !== null) {
                        return $html ? $this->html($sv) : $sv;
                    }
                }

                return null;
            };
            $faq = function (string $lang) use ($srow, $prow, $src): ?array {
                if ($prow !== null && ($list = SeblodMarkers::faq((string) ($prow['ipage_faq_' . $lang] ?? ''), 'ipage_faq_' . $lang)) !== []) {
                    return $list;
                }
                if ($srow !== null && isset($src['fields']['faq'])) {
                    $col = $src['type'] . '_' . $src['fields']['faq'] . '_' . $lang;

                    return $this->nnArr(SeblodMarkers::faq((string) ($srow[$col] ?? ''), $col));
                }

                return null;
            };
            $cat
                ->setSlugUk((string) $cfg['slug_uk'])
                ->setSlugRu($this->nn($cfg['slug_ru'] ?? null))
                ->setTitleUk($pick('title', 'uk') ?? (string) ($titles[$legacyId]['title'] ?? $cfg['slug_uk']))
                ->setTitleRu($pick('title', 'ru'))
                ->setMetaTitleUk($pick('meta_title', 'uk'))
                ->setMetaTitleRu($pick('meta_title', 'ru'))
                ->setMetaDescUk($pick('meta_desc', 'uk'))
                ->setMetaDescRu($pick('meta_desc', 'ru'))
                ->setDescUk($pick('desc', 'uk', true))
                ->setDescRu($pick('desc', 'ru', true))
                ->setPosition($pos++)
                ->setActive(true);
            // нові поля (лід, FAQ) — лише коли є джерело, щоб повторний імпорт без нього не стирав внесене в адмінці
            if ($srow !== null || $prow !== null || isset($cfg['lead_uk'])) {
                $cat
                    ->setLeadUk($pick('lead', 'uk', true))
                    ->setLeadRu($pick('lead', 'ru', true))
                    ->setFaqUk($faq('uk'))
                    ->setFaqRu($faq('ru'));
            }
            if (($landing = $this->map->categoryLanding($legacyId)) !== null) {
                $cat->setLanding($landing);
            }
            if ($srow !== null && isset($src['fields']['img']) && ($img = $this->nn($srow[$src['type'] . '_' . $src['fields']['img']] ?? null)) !== null
                && ($name = $this->copyMedia($img, 'category', 'category-' . $legacyId)) !== null) {
                $cat->setImageName($name)->setImageAlt($cat->getTitleUk());
            }
        }
        $this->flush();
    }

    /**
     * Рядки ipage за id (з назвою й станом із content).
     *
     * @param list<int> $ids
     *
     * @return array<int, array<string, mixed>>
     */
    private function ipageRows(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $rows = [];
        foreach ($this->db->fetchAllAssociative(
            sprintf('SELECT p.*, c.title AS content_title, c.state FROM %s p JOIN %s c ON c.id = p.id WHERE p.id IN (%s)', $this->t('cck_store_form_ipage'), $this->t('content'), implode(',', array_map('intval', $ids))),
        ) as $r) {
            $rows[(int) $r['id']] = $r;
        }

        return $rows;
    }

    private function importItems(ServiceSite $site): void
    {
        $faqJson = $this->loadFaqJson($this->map->faqFile('service'));
        $cats = $this->em->getRepository(ServiceCategory::class);
        $repo = $this->em->getRepository(ServiceItem::class);
        $posByCat = [];
        foreach ($this->map->itemTypes() as $cfg) {
            $type = $cfg['type'];
            $f = $cfg['fields'];
            $slugCols = $cfg['slug'] === 'content_alias' ? 'c.alias AS alias_uk, c.alias AS alias_ru' : 'ic.alias_uk, ic.alias_ru';
            $rows = $this->db->fetchAllAssociative(sprintf(<<<'SQL'
                SELECT f.*, c.title AS content_title, c.state, c.catid, c.ordering, %s
                FROM %s f
                JOIN %s c ON c.id = f.id
                LEFT JOIN %s ic ON ic.id = f.id
                WHERE c.state IN (0, 1)
                ORDER BY c.catid, c.ordering, f.id
                SQL, $slugCols, $this->t('cck_store_form_' . $type), $this->t('content'), $this->t('cck_store_item_content')));

            foreach ($rows as $row) {
                $id = (int) $row['id'];
                $catKey = $cfg['category'] === 'catid' ? (int) $row['catid'] : $cfg['category'];
                $cat = $cats->findOneBy(['site' => $site, 'legacyId' => $catKey]);
                if ($cat === null) {
                    $this->issue(sprintf('Послуга %d «%s» (%s): категорія %d не в мапінгу — пропуск.', $id, $row['content_title'], $type, $catKey));
                    $this->bump('item', null);
                    continue;
                }
                $slugUk = $this->nn($row['alias_uk']);
                if ($slugUk === null) {
                    $this->issue(sprintf('Послуга %d «%s»: без alias_uk — пропуск.', $id, $row['content_title']));
                    $this->bump('item', null);
                    continue;
                }
                $item = $repo->findOneBy(['site' => $site, 'legacyId' => $id]);
                if ($item === null) {
                    $item = (new ServiceItem())->setSite($site)->setLegacyId($id);
                    $this->em->persist($item);
                    $this->bump('item', true);
                } else {
                    $this->bump('item', false);
                }
                $posByCat[$catKey] = ($posByCat[$catKey] ?? -1) + 1;

                $col = fn (string $field, string $lang): ?string => $this->itemCol($row, $type, $f, $field, $lang);
                $faqCol = fn (string $lang): array => isset($f['faq'])
                    ? SeblodMarkers::faq((string) ($row[$this->colName($type, $f['faq'], $lang)] ?? ''), $this->colName($type, $f['faq'], $lang))
                    : [];
                $faqUk = $faqJson[$id]['uk'] ?? $faqCol('uk');
                $faqRu = $faqJson[$id]['ru'] ?? $faqCol('ru');

                $item
                    ->setCategory($cat)
                    ->setSlugUk($slugUk)
                    ->setSlugRu($this->nn($row['alias_ru']))
                    ->setTitleUk($col('title', 'uk') ?? (string) $row['content_title'])
                    ->setTitleRu($col('title', 'ru'))
                    ->setLeadUk($this->html($col('lead', 'uk')))
                    ->setLeadRu($this->html($col('lead', 'ru')))
                    ->setDescUk($this->html($col('desc', 'uk')))
                    ->setDescRu($this->html($col('desc', 'ru')))
                    ->setDesc2Uk($this->html($col('desc2', 'uk')))
                    ->setDesc2Ru($this->html($col('desc2', 'ru')))
                    ->setDesc3Uk($this->html($col('desc3', 'uk')))
                    ->setDesc3Ru($this->html($col('desc3', 'ru')))
                    ->setTabTitleUk($col('tab_title', 'uk'))
                    ->setTabTitleRu($col('tab_title', 'ru'))
                    ->setMetaTitleUk($col('meta_title', 'uk'))
                    ->setMetaTitleRu($this->ruItemCol($row, $type, $f, 'meta_title'))
                    ->setMetaDescUk($col('meta_desc', 'uk'))
                    ->setMetaDescRu($this->ruItemCol($row, $type, $f, 'meta_desc'))
                    ->setFaqUk($this->nnArr($faqUk))
                    ->setFaqRu($this->nnArr($faqRu))
                    ->setUtp(isset($f['utp']) && (bool) ($row[$type . '_' . $f['utp']] ?? false))
                    ->setPosition($posByCat[$catKey])
                    ->setActive((int) $row['state'] === 1);
                // нові поля (коротка назва, характеристики) — лише коли мапінг їх описує, щоб не стирати внесене в адмінці
                if (isset($f['name'])) {
                    $item->setNameUk($col('name', 'uk'))->setNameRu($col('name', 'ru'));
                }
                if ($cfg['extra'] !== []) {
                    // ключі з мапінгу перезаписуються, решта (заповнене в адмінці, напр. група автопарку) лишається
                    $item->setExtra(array_merge((array) $item->getExtra(), $this->extraValues($row, $cfg['extra'])));
                }
                if ((int) $row['state'] !== 1) {
                    $this->issue(sprintf('Послуга %d «%s»: у Joomla чернетка (state=%d) — імпортовано вимкненою.', $id, $item->getTitleUk(), $row['state']));
                }
                if (isset($f['img']) && ($img = $this->nn($row[$type . '_' . $f['img']] ?? null)) !== null && ($name = $this->copyMedia($img, 'item', 'item-' . $id)) !== null) {
                    $item->setImageName($name)->setImageAlt($item->getTitleUk());
                }
            }
            $this->flush();
        }
    }

    /** Назва колонки поля типу: основа → <type>_<основа>_<мова>; {uk: …, ru: …} — повна назва колонки мовою. */
    private function colName(string $type, string|array $spec, string $lang): string
    {
        if (\is_array($spec)) {
            return (string) ($spec[$lang] ?? '');
        }

        return $type . '_' . $spec . '_' . $lang;
    }

    /**
     * Значення локалізованого поля запису типу (null — поля в мапінгу нема, колонки нема або порожньо).
     *
     * @param array<string, mixed>                       $row
     * @param array<string, string|array<string, string>> $fields
     */
    private function itemCol(array $row, string $type, array $fields, string $field, string $lang): ?string
    {
        if (!isset($fields[$field])) {
            return null;
        }

        return $this->nn($row[$this->colName($type, $fields[$field], $lang)] ?? null);
    }

    /**
     * Російське SEO-поле послуги з запасниками ru_fallback_columns (як ruCol), для основи колонки з мапінгу.
     *
     * @param array<string, mixed>                       $row
     * @param array<string, string|array<string, string>> $fields
     */
    private function ruItemCol(array $row, string $type, array $fields, string $field): ?string
    {
        if (!isset($fields[$field])) {
            return null;
        }
        if (\is_array($fields[$field])) {
            return $this->nn($row[$fields[$field]['ru'] ?? ''] ?? null);
        }

        return $this->ruCol($row, $type . '_' . $fields[$field]);
    }

    /**
     * Додаткові поля запису за мапінгом (items[].extra): {ключ: {value: колонка|{uk, ru}, prefix: {column, map: {знач: {uk, ru}}}}}
     * → {ключ: {uk, ru}} для ServiceItem::setExtra. Назви й одиниці полів — у паку (app.custom_fields), тут лише значення;
     * префікс за значенням іншої колонки (тоннаж_type 1 → «від»). Порожнє значення — поле пропускається.
     *
     * @param array<string, mixed>               $row
     * @param array<string, array<string, mixed>> $extra
     *
     * @return array<string, array<string, string>>
     */
    private function extraValues(array $row, array $extra): array
    {
        $out = [];
        foreach ($extra as $key => $spec) {
            foreach (['uk', 'ru'] as $lang) {
                $col = $spec['value'] ?? null;
                $col = \is_array($col) ? ($col[$lang] ?? $col['uk'] ?? null) : $col;
                $value = $col !== null ? $this->nn(SeblodMarkers::text((string) ($row[(string) $col] ?? ''))) : null;
                if ($value === null) {
                    continue;
                }
                if (isset($spec['prefix']['column'])) {
                    $k = trim((string) ($row[(string) $spec['prefix']['column']] ?? ''));
                    $pref = $spec['prefix']['map'][$k][$lang] ?? $spec['prefix']['map'][$k]['uk'] ?? null;
                    if (\is_string($pref) && $pref !== '') {
                        $value = $pref . ' ' . $value;
                    }
                }
                $out[(string) $key][$lang] = $value;
            }
        }

        return $out;
    }

    // ───────────────────────────── райони ─────────────────────────────

    private function importDistricts(): void
    {
        $faqJson = $this->loadFaqJson($this->map->faqFile('area'));
        $groups = [];
        foreach ($this->db->fetchAllAssociative(sprintf('SELECT id, city, name_uk, name_ru FROM %s', $this->t('apl_district'))) as $g) {
            $groups[(int) $g['id']] = $g;
        }
        $rows = $this->db->fetchAllAssociative(sprintf(<<<'SQL'
            SELECT a.*, c.title AS content_title, c.state, c.ordering, ic.alias_uk, ic.alias_ru
            FROM %s a
            JOIN %s c ON c.id = a.id
            LEFT JOIN %s ic ON ic.id = a.id
            WHERE c.state = 1
            ORDER BY a.area_city, c.ordering, a.id
            SQL, $this->t('cck_store_form_area'), $this->t('content'), $this->t('cck_store_item_content')));

        $repo = $this->em->getRepository(ServiceDistrict::class);
        $prefixes = $this->map->districtNamePrefixes();
        $posByCity = [];
        /** @var array<string, list<ServiceDistrict>> кандидати на головну сторінку групи (місто|група) */
        $primaryCandidates = [];
        foreach ($rows as $row) {
            $id = (int) $row['id'];
            $city = $this->cityBySiteId[(int) $row['area_city']] ?? null;
            if ($city === null) {
                $this->issue(sprintf('Район %d «%s»: area_city=%s не відповідає жодному місту мапінгу (site_id) — пропуск.', $id, $row['content_title'], $row['area_city']));
                $this->bump('district', null);
                continue;
            }
            $slugUk = $this->nn($row['alias_uk']);
            if ($slugUk === null) {
                $this->issue(sprintf('Район %d «%s»: без alias_uk — пропуск.', $id, $row['content_title']));
                $this->bump('district', null);
                continue;
            }
            $district = $repo->findOneBy(['city' => $city, 'legacyId' => $id]);
            if ($district === null) {
                $district = (new ServiceDistrict())->setCity($city)->setLegacyId($id);
                $this->em->persist($district);
                $this->bump('district', true);
            } else {
                $this->bump('district', false);
            }
            $group = $groups[(int) $row['area_district']] ?? null;
            if ($group === null && $this->nn($row['area_district']) !== null) {
                $this->issue(sprintf('Район %d «%s»: групи apl_district id=%s нема — без групи.', $id, $row['content_title'], $row['area_district']));
            }
            // Seblod (перший імпортований сайт): area_title = коротка назва («Оболонь», у посиланнях), area_name = H1 сторінки
            // («Послуги сантехніка Оболонь»); у рушії name = коротка, title = H1
            $nameUk = $this->stripPrefix($this->nn($row['area_title_uk']) ?? $this->nn($row['area_name_uk']) ?? (string) $row['content_title'], $prefixes['uk'] ?? []);
            $nameRu = $this->nn($row['area_title_ru']) ?? $this->nn($row['area_name_ru']);
            $h1Uk = $this->nn($row['area_name_uk']);
            $groupUk = $group !== null ? $this->nn($group['name_uk']) : null;
            $price = (float) $row['area_price'];
            $citySlug = $city->getSlug();
            $posByCity[$citySlug] = ($posByCity[$citySlug] ?? -1) + 1;

            $district
                ->setSlugUk($slugUk)
                ->setSlugRu($this->nn($row['alias_ru']))
                ->setNameUk($nameUk)
                ->setNameRu($nameRu !== null ? $this->stripPrefix($nameRu, $prefixes['ru'] ?? []) : null)
                ->setGroupUk($groupUk)
                ->setGroupRu($group !== null ? $this->nn($group['name_ru']) : null)
                ->setTitleUk($h1Uk)
                ->setTitleRu($this->nn($row['area_name_ru']))
                ->setLeadUk($this->html($row['area_lead_uk']))
                ->setLeadRu($this->html($row['area_lead_ru']))
                ->setDescUk($this->html($row['area_desc_uk']))
                ->setDescRu($this->html($row['area_desc_ru']))
                ->setMetaTitleUk($this->nn($row['area_metatitle_uk']))
                ->setMetaTitleRu($this->ruCol($row, 'area_metatitle'))
                ->setMetaDescUk($this->nn($row['area_meta_description_uk']))
                ->setMetaDescRu($this->ruCol($row, 'area_meta_description'))
                ->setMinPrice($price > 0 ? (int) round($price) : null)
                ->setFaqUk($this->nnArr($faqJson[$id]['uk'] ?? []))
                ->setFaqRu($this->nnArr($faqJson[$id]['ru'] ?? []))
                // головна сторінка групи = район, названий як сам адмінрайон («Дніпровський район»)
                ->setIsPrimary(false)
                ->setPosition($posByCity[$citySlug])
                ->setActive(true);
            if ($groupUk !== null && \in_array(mb_strtolower($groupUk), [mb_strtolower($nameUk), mb_strtolower((string) $h1Uk)], true)) {
                $primaryCandidates[$citySlug . '|' . $groupUk][] = $district;
            }
        }
        // головна сторінка групи — одна: та, чий H1 збігається з назвою адмінрайону («Дніпровський район»),
        // інакше перша; дублі (у Joomla по дві сторінки на район) лишаються звичайними місцевостями
        foreach ($primaryCandidates as $key => $list) {
            $groupUk = explode('|', $key, 2)[1];
            usort($list, static fn (ServiceDistrict $a, ServiceDistrict $b) => (mb_strtolower((string) $b->getTitleUk()) === mb_strtolower($groupUk)) <=> (mb_strtolower((string) $a->getTitleUk()) === mb_strtolower($groupUk)));
            $list[0]->setIsPrimary(true);
            foreach (array_slice($list, 1) as $dup) {
                $this->issue(sprintf('Район «%s» (%s): друга сторінка адмірайону «%s» — головною лишено %s, ця імпортована як місцевість.', $dup->getTitleUk(), $dup->getSlugUk(), $groupUk, $list[0]->getSlugUk()));
            }
        }
        $this->flush();
    }

    // ───────────────────────────── блог і сторінки ─────────────────────────────

    private function importPosts(ServiceSite $site): void
    {
        $cfg = $this->map->posts();
        $type = $cfg['type'];
        $f = $cfg['fields'];
        $slugCols = $cfg['slug'] === 'content_alias' ? 'c.alias AS alias_uk, c.alias AS alias_ru' : 'ic.alias_uk, ic.alias_ru';
        $rows = $this->db->fetchAllAssociative(sprintf(<<<'SQL'
            SELECT b.*, c.title AS content_title, c.state, c.created, c.publish_up, %s
            FROM %s b
            JOIN %s c ON c.id = b.id
            LEFT JOIN %s ic ON ic.id = b.id
            WHERE c.state IN (0, 1)
            ORDER BY c.publish_up, b.id
            SQL, $slugCols, $this->t('cck_store_form_' . $type), $this->t('content'), $this->t('cck_store_item_content')));

        $repo = $this->em->getRepository(ServicePost::class);
        $col = fn (array $row, string $field, string $lang): ?string => $this->nn($row[$type . '_' . $f[$field] . '_' . $lang] ?? null);
        foreach ($rows as $row) {
            $id = (int) $row['id'];
            $slugUk = $this->nn($row['alias_uk']);
            if ($slugUk === null) {
                $this->issue(sprintf('Пост %d «%s»: без alias_uk — пропуск.', $id, $row['content_title']));
                $this->bump('post', null);
                continue;
            }
            $post = $repo->findOneBy(['site' => $site, 'legacyId' => $id]);
            if ($post === null) {
                $post = (new ServicePost())->setSite($site)->setLegacyId($id);
                $this->em->persist($post);
                $this->bump('post', true);
            } else {
                $this->bump('post', false);
            }
            $published = $this->date($row['publish_up']) ?? $this->date($row['created']);
            $post
                ->setSlugUk($slugUk)
                ->setSlugRu($this->nn($row['alias_ru']))
                ->setTitleUk($col($row, 'title', 'uk') ?? (string) $row['content_title'])
                ->setTitleRu($col($row, 'title', 'ru'))
                ->setLeadUk($this->html($col($row, 'lead', 'uk')))
                ->setLeadRu($this->html($col($row, 'lead', 'ru')))
                ->setDescUk($this->html($col($row, 'desc', 'uk')))
                ->setDescRu($this->html($col($row, 'desc', 'ru')))
                ->setMetaTitleUk($col($row, 'meta_title', 'uk') ?? ($this->map->postMetaTitleFromTitle() ? $col($row, 'title', 'uk') : null))
                ->setMetaTitleRu($this->ruCol($row, $type . '_' . $f['meta_title']) ?? ($this->map->postMetaTitleFromTitle() ? $col($row, 'title', 'ru') : null))
                ->setMetaDescUk($col($row, 'meta_desc', 'uk'))
                ->setMetaDescRu($this->ruCol($row, $type . '_' . $f['meta_desc']))
                ->setPublishedAt($published)
                ->setActive((int) $row['state'] === 1);
            if ((int) $row['state'] !== 1) {
                $this->issue(sprintf('Пост %d «%s»: у Joomla чернетка — імпортовано вимкненим.', $id, $post->getTitleUk()));
            }
            if (($img = $this->nn($row[$type . '_' . $f['img']] ?? null)) !== null && ($name = $this->copyMedia($img, 'post', 'post-' . $id)) !== null) {
                $post->setImageName($name)->setImageAlt($post->getTitleUk());
            }
        }
        $this->flush();
    }

    /**
     * Сторінки ipage: лише ті, що мапінг адресує — about → «Про нас» бренду, privacy → політика конфіденційності,
     * home → тексти й SEO головної апексу, categories → тексти категорій (див. importCategories); решта — у звіт.
     */
    private function importPages(ServiceSite $site): void
    {
        $pages = $this->map->pages();
        $rows = [];
        foreach ($this->db->fetchAllAssociative(sprintf('SELECT p.*, c.title AS content_title, c.state FROM %s p JOIN %s c ON c.id = p.id', $this->t('cck_store_form_ipage'), $this->t('content'))) as $r) {
            $rows[(int) $r['id']] = $r;
        }
        $used = array_values($this->map->pageCategories());
        // лід сторінки (якщо є) іде абзацом перед основним текстом — у рушії в «Про нас» і політики одне поле
        $body = fn (array $r, string $lang): ?string => $this->joinHtml($this->html($r['ipage_lead_' . $lang] ?? null), $this->html($r['ipage_desc_' . $lang] ?? null));
        foreach (['about', 'privacy', 'home'] as $kind) {
            if (!isset($pages[$kind])) {
                continue;
            }
            $r = $rows[$pages[$kind]] ?? null;
            if ($r === null) {
                $this->issue(sprintf('Сторінка %s: ipage id=%d у джерелі нема.', $kind, $pages[$kind]));
                continue;
            }
            $used[] = $pages[$kind];
            $this->bump('page', false);
            if ($kind === 'about') {
                $site
                    ->setAboutTitleUk($this->nn($r['ipage_title_uk']) ?? (string) $r['content_title'])
                    ->setAboutTitleRu($this->nn($r['ipage_title_ru'] ?? null))
                    ->setAboutUk($body($r, 'uk'))
                    ->setAboutRu($body($r, 'ru'));
            } elseif ($kind === 'privacy') {
                $site->setPrivacyUk($body($r, 'uk'))->setPrivacyRu($body($r, 'ru'));
            } else {
                $city = $this->apexCity();
                // текст головної без заголовка-дубля H1 першого екрана (texts.intro_title); pages.home_text: text2|text3 —
                // довгий текст іде в SEO-блок нижче, під H1 лишається лише лід ipage
                $target = $this->map->homeTextField();
                $full = fn (string $lang) => $target === 'intro' ? $body($r, $lang) : $this->html($r['ipage_desc_' . $lang] ?? null);
                $bodyUk = $this->dropTitleHeading($full('uk'), $city->getIntroTitleUk());
                $bodyRu = $this->dropTitleHeading($full('ru'), $city->getIntroTitleRu());
                if ($target !== 'intro') {
                    $setter = $target === 'text2' ? 'setText2' : 'setText3';
                    $city->{$setter . 'Uk'}($bodyUk)->{$setter . 'Ru'}($bodyRu);
                    $bodyUk = $this->html($r['ipage_lead_uk'] ?? null);
                    $bodyRu = $this->html($r['ipage_lead_ru'] ?? null);
                    // лід порожній — вступ під H1 прибираємо (інакше лишився б старий текст з попереднього імпорту)
                    $city->setIntroTextUk($bodyUk)->setIntroTextRu($bodyRu);
                }
                $city
                    ->setMetaTitleUk($this->nn($r['ipage_meta_title_uk'] ?? null) ?? $city->getMetaTitleUk())
                    ->setMetaTitleRu($this->nn($r['ipage_meta_title_ru'] ?? null) ?? $city->getMetaTitleRu())
                    ->setMetaDescUk($this->nn($r['ipage_meta_description_uk'] ?? null) ?? $city->getMetaDescUk())
                    ->setMetaDescRu($this->ruCol($r, 'ipage_meta_description') ?? $city->getMetaDescRu())
                    ->setIntroTextUk($bodyUk ?? $city->getIntroTextUk())
                    ->setIntroTextRu($bodyRu ?? $city->getIntroTextRu());
                if ($city->getIntroTitleUk() === null) {
                    $city->setIntroTitleUk($this->nn($r['ipage_title_uk']))->setIntroTitleRu($this->nn($r['ipage_title_ru'] ?? null));
                }
                $faqUk = SeblodMarkers::faq((string) ($r['ipage_faq_uk'] ?? ''), 'ipage_faq_uk');
                if ($faqUk !== []) {
                    $city->setFaqUk($faqUk)->setFaqRu($this->nnArr(SeblodMarkers::faq((string) ($r['ipage_faq_ru'] ?? ''), 'ipage_faq_ru')));
                }
            }
        }
        foreach ($rows as $id => $r) {
            if (!\in_array($id, $used, true)) {
                $this->issue(sprintf('Сторінка ipage %d «%s» (%d симв.%s) не імпортована: у рушії для неї нема місця (мапінг pages).', $id, $r['content_title'], \strlen((string) $r['ipage_desc_uk']), (int) $r['state'] === 1 ? '' : ', у Joomla вимкнена'));
                $this->bump('page', null);
            }
        }
        $this->flush();
    }

    /** Два HTML-фрагменти через перенос; лід без тегів стає абзацом. */
    private function joinHtml(?string $lead, ?string $body): ?string
    {
        if ($lead !== null && !preg_match('#^\s*<#', $lead)) {
            $lead = '<p>' . $lead . '</p>';
        }
        $parts = array_filter([$lead, $body], static fn (?string $v): bool => $v !== null);

        return $parts === [] ? null : implode("\n", $parts);
    }

    // ───────────────────────────── ціни, відгуки, ліди ─────────────────────────────

    private function importPrices(ServiceSite $site): void
    {
        $rows = $this->db->fetchAllAssociative(sprintf('SELECT * FROM %s WHERE state = 1 ORDER BY service, id', $this->t('apl_prices')));
        $items = $this->em->getRepository(ServiceItem::class);
        $repo = $this->em->getRepository(ServicePrice::class);
        $texts = $this->map->priceTexts();
        $prefixes = $this->map->pricePrefixes();
        $unlinked = [];
        $posByItem = [];
        foreach ($rows as $row) {
            $id = (int) $row['id'];
            $city = $this->cityBySiteId[(int) $row['city']] ?? null;
            if ($city === null) {
                $this->issue(sprintf('Ціна %d «%s»: city=%s не відповідає місту мапінгу — пропуск.', $id, $row['name_uk'], $row['city']));
                $this->bump('price', null);
                continue;
            }
            $item = $items->findOneBy(['site' => $site, 'legacyId' => (int) $row['service']]);
            if ($item === null) {
                $unlinked[(int) $row['service']] = ($unlinked[(int) $row['service']] ?? 0) + 1;
                if ($this->map->unlinkedPrices() !== 'keep') {
                    $this->bump('price', null);
                    continue;
                }
            }
            $price = $repo->findOneBy(['city' => $city, 'legacyId' => $id]);
            if ($price === null) {
                $price = (new ServicePrice())->setCity($city)->setLegacyId($id);
                $this->em->persist($price);
                $this->bump('price', true);
            } else {
                $this->bump('price', false);
            }
            $itemKey = $item?->getId() ?? ('x' . $row['service']);
            $posByItem[$itemKey] = ($posByItem[$itemKey] ?? -1) + 1;

            // price_type: 0 = сума, 1 = безкоштовно, 2 = за домовленістю; pref_price: 0 = точно, 1 = «до», 2 = «від»
            $type = (int) $row['price_type'];
            $pref = match ((int) $row['pref_price']) { 1 => $prefixes['until'], 2 => $prefixes['from'], default => null };
            $price
                ->setItem($item)
                ->setNameUk((string) $row['name_uk'])
                ->setNameRu($this->nn($row['name_ru']))
                ->setDescUk($this->nn($row['price_desc_uk']))
                ->setDescRu($this->nn($row['price_desc_ru']))
                ->setPrice($type === 0 ? number_format((float) $row['price'], 2, '.', '') : null)
                ->setPricePrefix($type === 0 ? $pref : null)
                ->setPriceTextUk($type === 1 ? $texts['free']['uk'] : ($type === 2 ? $texts['agreement']['uk'] : null))
                ->setPriceTextRu($type === 1 ? $texts['free']['ru'] : ($type === 2 ? $texts['agreement']['ru'] : null))
                ->setPosition($posByItem[$itemKey])
                ->setActive(true);
        }
        foreach ($unlinked as $serviceId => $n) {
            $this->issue(sprintf(
                'Ціни: %d рядків із service=%d — такої послуги нема (%s). Прив\'язати до послуги в адмінці («Прайс»).',
                $n, $serviceId, $this->map->unlinkedPrices() === 'keep' ? 'імпортовано без прив\'язки до послуги' : 'пропущено',
            ));
        }
        $this->flush();
    }

    private function importReviews(): void
    {
        $cfg = $this->map->reviews();
        $byLocation = $cfg['city'] === 'location';
        $rows = $this->db->fetchAllAssociative(sprintf('SELECT * FROM %s WHERE state = 1 ORDER BY date, id', $this->t($cfg['table'])));
        $repo = $this->em->getRepository(ServiceReview::class);
        foreach ($rows as $row) {
            $id = (int) $row['id'];
            // apl_review.city = id запису domains (не cck_core_sites); фолбек — апекс-місто.
            // reviews.city: location — у колонці city текст «звідки автор», відгук іде на апекс
            $city = $byLocation ? $this->apexCity() : ($this->cityByLegacy[(int) $row['city']] ?? $this->cityBySiteId[(int) $row['city']] ?? $this->apexCity());
            $review = $repo->findOneBy(['city' => $city, 'legacyId' => $id]);
            if ($review === null) {
                $review = (new ServiceReview())->setCity($city)->setLegacyId($id);
                $this->em->persist($review);
                $this->bump('review', true);
            } else {
                $this->bump('review', false);
            }
            $authorUk = $this->nn($row['user_uk']) ?? $this->nn($row['user'] ?? null) ?? 'Клієнт';
            $textUk = SeblodMarkers::text((string) ($this->nn($row['text_uk']) ?? $row['text'] ?? ''));
            $textRu = SeblodMarkers::text((string) ($row['text_ru'] ?? ''));
            $review
                ->setAuthorUk($authorUk)
                ->setAuthorRu($this->nn($row['user_ru']))
                ->setTextUk($textUk)
                ->setTextRu($textRu !== '' && $textRu !== $textUk ? $textRu : null)
                // без колонки stars (сайти з Joomla без оцінок) — 5, як показувала Joomla
                ->setStars(isset($row['stars']) ? max(1, min(5, (int) $row['stars'])) : 5)
                ->setReviewedAt($this->date($row['date']))
                ->setActive(true);
            if ($byLocation) {
                $review->setLocation($this->nn($row['city'] ?? null));
            }
            if (($img = $this->nn($row['avatar'])) !== null && ($name = $this->copyMedia($img, 'review', 'review-' . $id)) !== null) {
                $review->setImageName($name)->setImageAlt($authorUk);
            }
        }
        $this->flush();
    }

    /** Команда з окремої таблиці (team.table: apl_team) на апекс-місто: ім'я user_*, посада text_*, фото avatar. */
    private function importTeamTable(): void
    {
        $city = $this->apexCity();
        $repo = $this->em->getRepository(ServiceTeamMember::class);
        $rows = $this->db->fetchAllAssociative(sprintf('SELECT * FROM %s WHERE state = 1 ORDER BY id', $this->t((string) $this->map->teamTable())));
        foreach (array_values($rows) as $i => $row) {
            $member = $repo->findOneBy(['city' => $city, 'position' => $i]);
            if ($member === null) {
                $member = (new ServiceTeamMember())->setCity($city)->setPosition($i);
                $this->em->persist($member);
                $this->bump('team', true);
            } else {
                $this->bump('team', false);
            }
            $name = $this->nn($row['user_uk'] ?? null) ?? 'Team';
            $member
                ->setNameUk($name)
                ->setNameRu($this->nn($row['user_ru'] ?? null))
                ->setRoleUk($this->nn(SeblodMarkers::text((string) ($row['text_uk'] ?? ''))))
                ->setRoleRu($this->nn(SeblodMarkers::text((string) ($row['text_ru'] ?? ''))))
                ->setActive(true);
            if (($img = $this->nn($row['avatar'] ?? null)) !== null && ($file = $this->copyMedia($img, 'team', 'team-' . $city->getSlug())) !== null) {
                $member->setImageName($file)->setImageAlt($name);
            }
        }
        $this->flush();
    }

    private function importLeads(): void
    {
        $rows = $this->db->fetchAllAssociative(sprintf('SELECT * FROM %s ORDER BY id', $this->t('site_leads')));
        $repo = $this->em->getRepository(ServiceLead::class);
        foreach ($rows as $row) {
            $id = (int) $row['id'];
            $page = $this->nn($row['page']);
            $host = $page !== null ? strtolower((string) parse_url($page, PHP_URL_HOST)) : '';
            $city = $this->cityByHost[$host] ?? $this->cityByHost[$this->nn($row['domain']) ?? ''] ?? $this->apexCity();
            $lead = $repo->findOneBy(['legacyId' => $id]);
            if ($lead === null) {
                $lead = (new ServiceLead())->setLegacyId($id);
                $created = $this->date($row['created']);
                if ($created !== null) {
                    $lead->setCreatedAt($created); // TimestampsTrait сам переводить у \DateTime під колонку datetime
                }
                $this->em->persist($lead);
                $this->bump('lead', true);
            } else {
                $this->bump('lead', false);
            }
            $lead
                ->setCity($city)
                ->setPhone((string) ($this->nn($row['phone']) ?? ''))
                ->setName($this->nn($row['name']))
                ->setMessage($this->nn($row['message']))
                ->setPageUrl($page)
                ->setLandingUrl($page)
                ->setLocale($page !== null && preg_match('#^https?://[^/]+/ru(/|$)#', $page) ? 'ru' : 'uk')
                ->setProcessed(true);
        }
        $this->flush();
    }

    // ───────────────────────────── допоміжне ─────────────────────────────

    private function t(string $table): string
    {
        return $this->schema . '.' . $this->prefix . $table;
    }

    private function apexCity(): ServiceCity
    {
        foreach ($this->cityByLegacy as $city) {
            if ($city->isApex()) {
                return $city;
            }
        }

        return reset($this->cityByLegacy) ?: throw new \RuntimeException('Міст нема.');
    }

    private function flush(): void
    {
        if (!$this->dryRun) {
            $this->em->flush();
        }
    }

    private function bump(string $type, ?bool $new): void
    {
        $this->stats[$type] ??= ['new' => 0, 'upd' => 0, 'skip' => 0];
        ++$this->stats[$type][$new === null ? 'skip' : ($new ? 'new' : 'upd')];
    }

    private function issue(string $text): void
    {
        $this->issues[] = $text;
        $this->io->writeln('  <comment>• ' . $text . '</comment>', OutputInterface::VERBOSITY_VERBOSE);
    }

    /**
     * Російське SEO-поле (<base>_ru) з колонок-запасників мапінгу (joomla_import.ru_fallback_columns, напр. [en]):
     * у першого імпортованого сайту Seblod клав ru meta description у *_meta_description_en, а *_ru лишав порожнім. Без запасників —
     * лише _ru; порожнє = null (рушій підставить свій дефолт мовою сторінки, бо loc() для meta* не падає на uk).
     */
    private function ruCol(array $row, string $base): ?string
    {
        $value = $this->nn($row[$base . '_ru'] ?? null);
        if ($value !== null) {
            return $value;
        }
        foreach ($this->map->ruFallbackColumns() as $lang) {
            $value = $this->nn($row[$base . '_' . $lang] ?? null);
            if ($value !== null) {
                return $value;
            }
        }

        return null;
    }

    /** '' → null (Seblod зберігає порожні рядки замість NULL). */
    private function nn(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    /** @param list<array{q: string, a: string}> $faq */
    private function nnArr(array $faq): ?array
    {
        return $faq === [] ? null : array_values($faq);
    }

    /**
     * HTML-поле: '' → null; посилання приводяться до кореня сайту — абсолютні на хости мапінгу
     * стають відносними (href="https://host/x" → "/x"), відносні без слеша (href="services/…", як у Joomla
     * з <base>) отримують провідний слеш, щоб не ламатися на вкладених URL рушія.
     */
    private function html(mixed $value): ?string
    {
        $s = $this->nn($value);
        if ($s === null) {
            return null;
        }
        foreach ($this->map->linkHosts() as $host) {
            $s = preg_replace('#(href=["\'])https?://(www\.)?' . preg_quote($host, '#') . '(/|(?=["\']))#i', '$1/', $s) ?? $s;
        }
        $s = preg_replace('#(href=["\'])(?!https?://|/|\#|mailto:|tel:|javascript:|data:)([a-z0-9])#i', '$1/$2', $s) ?? $s;
        if ($this->map->demoteH1()) {
            // один H1 на сторінці — у шаблоні; заголовки контенту стають H2 (атрибути зберігаються)
            $s = preg_replace(['#<h1(\s|>)#i', '#</h1>#i'], ['<h2$1', '</h2>'], $s) ?? $s;
        }


        // У текстах Joomla трапляється блоковий тег усередині абзацу: <p><h1>…</h1></p>, <p><section>…</section></p>.
        // Браузер закриває абзац перед блоком і лишає порожній <p></p> та осиротілий </p> —
        // через це валилася верстка SEO-блоку (на /international-transport/ так поїхав увесь блок).
        // Знімаємо обгортку разом із її </p>; (?!<\2[\s>]) — щоб не склеїти вкладені <div><div>.
        $s = preg_replace('#<p>\s*(<(section|div|ul|ol|table|h[1-6])\b[^>]*>(?:(?!<\2[\s>]).)*?</\2>)\s*</p>#is', '$1', $s) ?? $s;
        // порожні абзаци (у Joomla це були розпірки)
        $s = preg_replace('~<p>(?:\s|&nbsp;|&#160;|<br\s*/?>)*</p>~i', '', $s) ?? $s;
        return $s;
    }

    /**
     * Прибрати з тексту головної заголовок, що дослівно повторює H1 першого екрана (Joomla-тексти починались із того
     * самого заголовка, що шаблон уже показує, — на сторінці було двічі). Порівняння без тегів, &nbsp; і регістру.
     */
    private function dropTitleHeading(?string $html, ?string $title): ?string
    {
        if ($html === null || $title === null) {
            return $html;
        }
        $norm = static fn (string $v): string => mb_strtolower(trim(preg_replace('/\s+/u', ' ', str_replace("\u{a0}", ' ', html_entity_decode(strip_tags($v), ENT_QUOTES | ENT_HTML5, 'UTF-8'))) ?? ''));
        $want = $norm($title);
        $out = preg_replace_callback('#<h([1-3])[^>]*>(.*?)</h\1>\s*#is', static function (array $m) use ($norm, $want): string {
            return $norm($m[2]) === $want ? '' : $m[0];
        }, $html, 1) ?? $html;

        return trim($out) === '' ? null : $out;
    }

    /**
     * H1 головної за шаблоном мапінгу (texts.intro_title.<мова>) з відмінками міста з domains_*.
     *
     * @param array<string, mixed> $row
     */
    private function introTitle(string $lang, array $row): ?string
    {
        return $this->introTitleFrom(
            $lang,
            $this->nn($row['domains_city_' . $lang]) ?? '',
            $this->nn($row['domain_city_gen_' . $lang]) ?? '',
            $this->nn($row['domain_city_dat_' . $lang]) ?? '',
            $this->nn($row['domain_city_prep_' . $lang]) ?? '',
        );
    }

    /** Шаблон texts.intro_title з підставленими відмінками; null — шаблону нема або бракує потрібного відмінка. */
    private function introTitleFrom(string $lang, string $nom, string $gen, string $dat, string $loc): ?string
    {
        $tpl = $this->map->introTitle($lang);
        if ($tpl === null) {
            return null;
        }
        $v = ['{city}' => $nom, '{city_gen}' => $gen, '{city_dat}' => $dat, '{city_loc}' => $loc];
        foreach ($v as $ph => $val) {
            if ($val === '' && str_contains($tpl, $ph)) {
                return null; // потрібного відмінка в джерелі нема — фолбек на domains_title
            }
        }

        return strtr($tpl, $v);
    }

    /** H1 хіро з domains_title («Виклик||сантехніка», || = перенос рядка в шаблоні) і subtitle. */
    private function heroTitle(mixed $title, mixed $subtitle): ?string
    {
        $t = $this->nn($title);
        if ($t === null) {
            return null;
        }
        $t = trim(preg_replace('/\s*\|\|\s*/', ' ', $t) ?? $t);
        $sub = $this->nn($subtitle);

        return $sub !== null ? $t . ' ' . $sub : $t;
    }

    /** Текст блоку з його заголовком (<h2>) попереду, щоб заголовок Seblod-поля не губився. */
    private function withHeading(mixed $heading, mixed $html): ?string
    {
        $body = $this->html($html);
        $h = $this->nn($heading);
        if ($body === null) {
            return $h !== null ? '<h2>' . htmlspecialchars($h, ENT_QUOTES) . '</h2>' : null;
        }

        return $h !== null ? '<h2>' . htmlspecialchars($h, ENT_QUOTES) . '</h2>' . "\n" . $body : $body;
    }

    /** @param list<string> $prefixes */
    private function stripPrefix(string $name, array $prefixes): string
    {
        foreach ($prefixes as $p) {
            if ($p !== '' && str_starts_with($name, $p)) {
                return trim(substr($name, \strlen($p)));
            }
        }

        return trim($name);
    }

    private function date(mixed $value): ?\DateTimeImmutable
    {
        $v = $this->nn($value);
        if ($v === null || str_starts_with($v, '0000-')) {
            return null;
        }
        try {
            return new \DateTimeImmutable($v);
        } catch (\Exception) {
            return null;
        }
    }

    /**
     * FAQ-JSON паку: {"<legacy id>": {"uk": [[q, a], …], "ru": [[q, a], …]}} → [id => ['uk' => [{q,a}], 'ru' => …]].
     *
     * @return array<int, array<string, list<array{q: string, a: string}>>>
     */
    private function loadFaqJson(?string $file): array
    {
        if ($file === null) {
            return [];
        }
        $data = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
        $out = [];
        foreach ((array) $data as $id => $langs) {
            foreach ((array) $langs as $lang => $pairs) {
                $list = [];
                foreach ((array) $pairs as $qa) {
                    $q = trim((string) ($qa[0] ?? $qa['q'] ?? ''));
                    $a = trim((string) ($qa[1] ?? $qa['a'] ?? ''));
                    if ($q !== '' && $a !== '') {
                        $list[] = ['q' => $q, 'a' => $a];
                    }
                }
                if ($list !== []) {
                    $out[(int) $id][(string) $lang] = $list;
                }
            }
        }

        return $out;
    }

    /**
     * Копія картинки Joomla (images/…) у public/media/<мапінг Vich>/; ім'я — <prefix>-<basename>.
     * Без --media-root або без файлу — null (поле лишається як було), у звіт.
     */
    private function copyMedia(string $relative, string $kind, string $prefix): ?string
    {
        if ($this->mediaRoot === null) {
            return null;
        }
        // Seblod зберігає картинку або простим шляхом, або JSON-ом
        // {"image_location":"images/…","image_title":"…"} — так у деяких сайтів з Joomla
        // лежать обкладинки новин. Без розбору JSON у поле потрапляв увесь рядок,
        // і файл «не знаходився».
        if (str_starts_with(ltrim($relative), '{')) {
            $json = json_decode($relative, true);
            $relative = is_array($json) ? (string) ($json['image_location'] ?? '') : '';
            if ($relative === '') {
                return null;
            }
        }
        $relative = ltrim(str_replace('\\', '/', explode('#', $relative, 2)[0]), '/');
        $src = $this->mediaRoot . '/' . $relative;
        if (!is_file($src)) {
            $this->issue(sprintf('Картинка %s не знайдена під --media-root — пропуск.', $relative));

            return null;
        }
        $base = preg_replace('/[^A-Za-z0-9._-]+/', '-', basename($relative)) ?? basename($relative);
        $name = $prefix . '-' . strtolower($base);
        $dir = $this->projectDir . '/public/media/' . self::MEDIA[$kind];
        if ($this->dryRun) {
            return $name;
        }
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException('Не вдалося створити ' . $dir);
        }
        $dst = $dir . '/' . $name;
        if (!is_file($dst) && !copy($src, $dst)) {
            $this->issue(sprintf('Картинка %s: не скопіювалась у %s.', $relative, $dst));

            return null;
        }

        return $name;
    }
}
